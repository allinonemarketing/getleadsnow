<?php
session_start();
require_once 'includes/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$userId = $_SESSION['user_id'];
$u = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $u = []; }

// --- API actions (JSON) ------------------------------------------------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($_GET['action'] === 'updateProfile') {
        $name  = trim($in['name'] ?? '');
        $phone = trim($in['phone'] ?? '');
        if ($name === '') { echo json_encode(['success' => false, 'error' => 'Please enter your name.']); exit; }
        try {
            $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?")->execute([$name, $phone, $userId]);
            $_SESSION['user_name'] = $name;
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Could not save changes.']); }
        exit;
    }

    if ($_GET['action'] === 'changePassword') {
        $current = (string)($in['current'] ?? '');
        $new     = (string)($in['new'] ?? '');
        if (strlen($new) < 6) { echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters.']); exit; }
        try {
            $s = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $s->execute([$userId]);
            $hash = $s->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                echo json_encode(['success' => false, 'error' => 'Your current password is incorrect.']); exit;
            }
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Could not update password.']); }
        exit;
    }

    if ($_GET['action'] === 'cancelSubscription') {
        session_write_close();   // external round-trips below — don't hold the session lock
        try {
            $s = $pdo->prepare("SELECT name, email, subscription_id, subscription_plan FROM users WHERE id = ?");
            $s->execute([$userId]);
            $sub = $s->fetch(PDO::FETCH_ASSOC);
            $plan = (string)($sub['subscription_plan'] ?? 'none');
            if ($plan === '' || $plan === 'none') {
                echo json_encode(['success' => false, 'error' => 'No active subscription to cancel.']); exit;
            }
            $subId = trim((string)($sub['subscription_id'] ?? ''));

            if ($subId !== '') {
                // Stripe-billed: cancel NOW at the source — no further charges.
                // The customer.subscription.deleted webhook re-applies the same
                // downgrade later; idempotent.
                require_once 'config/stripe_config.php';
                try {
                    $stripeSub = \Stripe\Subscription::retrieve($subId);
                    if (!in_array($stripeSub->status, ['canceled', 'incomplete_expired'], true)) {
                        $stripeSub->cancel();
                    }
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Stripe no longer knows this subscription — still downgrade
                    // locally so the account matches reality.
                    error_log('cancelSubscription: stripe lookup: ' . $e->getMessage());
                }
            }

            $pdo->prepare("UPDATE users SET subscription_id = NULL, subscription_status = 'canceled', subscription_plan = 'none', monthly_credits = 0 WHERE id = ?")
                ->execute([$userId]);

            if ($subId === '') {
                // No subscription id stored (customers who bought outside the
                // in-app checkout). Find them in Stripe by email. AUTO-CANCEL is
                // limited to prices approved for self-serve cancellation — the
                // $97 marketing-software subs + this app's own checkout prices.
                // Bundles like the $299 Partner Package are NOT auto-canceled:
                // the admin gets an ACTION NEEDED email and decides.
                require_once 'config/stripe_config.php';
                require_once 'config/subscription_config.php';
                $autoCancelPrices = array_values(array_filter([
                    'price_1SPolgKOBtDhxvblZ55yxU5h',   // All In One Marketing Software Monthly $97 (MP)
                    'price_1SOlXNKOBtDhxvbla4IkizrU',   // All In One Marketing Monthly $97
                    STRIPE_PRICE_STARTER, STRIPE_PRICE_GROWTH, STRIPE_PRICE_ENTERPRISE,
                ]));
                $canceledSubs = []; $otherSubs = [];
                try {
                    foreach (\Stripe\Customer::all(['email' => $sub['email'], 'limit' => 5])->data as $cust) {
                        foreach (\Stripe\Subscription::all(['customer' => $cust->id, 'status' => 'all', 'limit' => 10])->data as $ss) {
                            if (in_array($ss->status, ['canceled', 'incomplete_expired'], true)) continue;
                            $match = false; $descr = [];
                            foreach ($ss->items->data as $it) {
                                if (in_array($it->price->id, $autoCancelPrices, true)) { $match = true; }
                                $descr[] = '$' . number_format(($it->price->unit_amount ?? 0) / 100) . '/' . ($it->price->recurring->interval ?? '?') . ' [' . $it->price->id . ']';
                            }
                            if ($match) { $ss->cancel(); $canceledSubs[] = $ss->id . ' — ' . implode(', ', $descr); }
                            else { $otherSubs[] = $ss->id . ' — ' . implode(', ', $descr) . ' (' . $ss->status . ')'; }
                        }
                    }
                } catch (Throwable $e) { error_log('cancelSubscription: stripe email lookup: ' . $e->getMessage()); }

                try {
                    require_once 'includes/ghl_signup.php';
                    ghlAppendUsageMilestone($sub['email'], ['lead gen software subscription canceled'], []);
                } catch (Throwable $e) { error_log('cancelSubscription: ghl tag: ' . $e->getMessage()); }

                try {
                    require_once 'includes/email_service.php';
                    $mail = createMailer();
                    if ($mail) {
                        $needsAction = !empty($otherSubs);
                        $mail->addAddress(ADMIN_EMAIL);
                        $mail->isHTML(true);
                        $mail->Subject = ($needsAction ? 'ACTION NEEDED: ' : '') . 'Subscription canceled - ' . $sub['email'];
                        $planLabels = ['business' => 'Starter', 'agency' => 'Growth', 'enterprise' => 'Pro'];
                        $planLabel = $planLabels[$plan] ?? $plan;
                        $body = "<html><body>
                            <h2>User canceled their subscription</h2>
                            <p><strong>" . htmlspecialchars((string)$sub['name']) . "</strong> (" . htmlspecialchars((string)$sub['email']) . ") canceled their <strong>{$planLabel}</strong> plan from My Account. Their app access is downgraded to Free (remaining credits kept) and their CRM contact was tagged <em>lead gen software subscription canceled</em>.</p>";
                        if (!empty($canceledSubs)) {
                            $body .= "<p><strong>Stripe billing stopped automatically:</strong><br>" . implode('<br>', array_map('htmlspecialchars', $canceledSubs)) . "</p>";
                        }
                        if ($needsAction) {
                            $body .= "<p style='color:#b91c1c;font-weight:700;'>They still have other active Stripe subscription(s) that were NOT auto-canceled (e.g. a bundle like the Partner Package) — review and cancel in Stripe if appropriate:</p>
                                <p>" . implode('<br>', array_map('htmlspecialchars', $otherSubs)) . "</p>";
                        }
                        if (empty($canceledSubs) && empty($otherSubs)) {
                            $body .= "<p>No active Stripe billing was found for this email — nothing to stop.</p>";
                        }
                        $body .= "</body></html>";
                        $mail->Body = $body;
                        $mail->send();
                    }
                } catch (Throwable $e) { error_log('cancelSubscription: admin mail: ' . $e->getMessage()); }
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('cancelSubscription failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Could not cancel — please contact support at sales@allinonemarketing.com.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']); exit;
}

$name    = $u['name'] ?? '';
$email   = $u['email'] ?? '';
$phone   = $u['phone'] ?? '';
$credits = (int)($u['credits'] ?? 0);
$planKey = $u['subscription_plan'] ?? 'none';
$planLabels = ['none' => 'Free', 'business' => 'Starter', 'agency' => 'Growth', 'enterprise' => 'Pro'];
$planLabel  = $planLabels[$planKey] ?? ucfirst((string)$planKey);
$memberSince = '';
if (!empty($u['created_at'])) { $memberSince = date('F j, Y', strtotime($u['created_at'])); }
// Render path only (action handlers above exit): release the session lock so this
// page doesn't serialize behind other same-user requests.
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Account</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{ --ink:#141517; --muted:#5b6066; --faint:#7a8088; --line:rgba(20,21,23,.10); --bg:#f6f7f9; --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4; --green:#17813f; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  .wrap{max-width:680px;margin:0 auto;padding:40px 24px 64px}
  .head{margin-bottom:26px}
  .head .icon{width:56px;height:56px;border-radius:15px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:14px}
  h1{font-size:26px;font-weight:900;letter-spacing:-.02em}
  .head p{color:var(--muted);font-size:15px;margin-top:6px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;margin-bottom:18px}
  .card h2{font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin-bottom:16px;display:flex;align-items:center;gap:9px}
  .card h2 i{color:var(--accent);font-size:14px}
  .rows{display:grid;gap:2px}
  .row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:12px 0;border-bottom:1px solid var(--line)}
  .row:last-child{border-bottom:none}
  .row .k{font-size:13.5px;color:var(--faint);font-weight:600}
  .row .v{font-size:14.5px;font-weight:700;text-align:right;word-break:break-word}
  .pill{display:inline-block;background:var(--accent-soft);color:var(--accent-d);font-weight:800;font-size:12.5px;border-radius:999px;padding:5px 12px}
  .pill.green{background:#eaf7ef;color:var(--green)}
  .field{margin-bottom:14px}
  .field label{display:block;font-size:12.5px;font-weight:700;color:var(--muted);margin-bottom:6px}
  .field input{width:100%;font-family:inherit;font-size:16px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:#fbfbfc;color:var(--ink)}
  .field input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 4px rgba(200,87,25,.12);background:#fff}
  .field input:disabled{background:#f1f2f4;color:var(--faint);cursor:not-allowed}
  .btn{display:inline-flex;align-items:center;gap:8px;background:var(--accent);color:#fff;font-weight:800;font-size:14px;border:none;border-radius:10px;padding:12px 20px;cursor:pointer;font-family:inherit}
  .btn:hover{background:var(--accent-d)}
  .btn:disabled{opacity:.6;cursor:default}
  .msg{font-size:13px;font-weight:700;margin-top:12px;display:none}
  .msg.ok{color:var(--green);display:block}
  .msg.err{color:#c0392b;display:block}
  .hint{font-size:12px;color:var(--faint);margin-top:4px}
  .signout{display:inline-flex;align-items:center;gap:8px;color:#c0392b;font-weight:700;font-size:14px;text-decoration:none;margin-top:6px}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="icon"><i class="fas fa-user-gear"></i></div>
    <h1>My Account</h1>
    <p>Your account details and settings.</p>
  </div>

  <!-- ACCOUNT DETAILS -->
  <div class="card">
    <h2><i class="fas fa-id-card"></i> Account Details</h2>
    <div class="rows">
      <div class="row"><span class="k">Name</span><span class="v" id="dName"><?php echo htmlspecialchars($name !== '' ? $name : '—'); ?></span></div>
      <div class="row"><span class="k">Email <span style="font-weight:600;color:var(--faint)">(this is your login)</span></span><span class="v"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></span></div>
      <div class="row"><span class="k">Phone</span><span class="v" id="dPhone"><?php echo htmlspecialchars($phone !== '' ? $phone : '—'); ?></span></div>
      <?php if ($memberSince): ?><div class="row"><span class="k">Member since</span><span class="v"><?php echo htmlspecialchars($memberSince); ?></span></div><?php endif; ?>
    </div>
  </div>

  <!-- PLAN -->
  <div class="card">
    <h2><i class="fas fa-gem"></i> Plan &amp; Credits</h2>
    <div class="rows">
      <div class="row"><span class="k">Current plan</span><span class="v"><span class="pill"><?php echo htmlspecialchars($planLabel); ?></span></span></div>
      <div class="row"><span class="k">Credits available</span><span class="v"><span class="pill green"><?php echo number_format($credits); ?> credits</span></span></div>
    </div>
    <?php if ($planKey !== 'none' && $planKey !== ''): ?>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid rgba(20,21,23,.08);">
      <button id="cancelSubBtn" onclick="cancelSub()" style="background:#fff;border:1.5px solid #e5b4b4;color:#b91c1c;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">Cancel Subscription</button>
      <div class="hint" style="margin-top:7px;">Cancels immediately &mdash; you won&rsquo;t be charged again. Credits already on your account stay yours to use.</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- EDIT PROFILE -->
  <div class="card">
    <h2><i class="fas fa-pen"></i> Edit Profile</h2>
    <div class="field">
      <label for="fName">Name</label>
      <input type="text" id="fName" value="<?php echo htmlspecialchars($name); ?>" autocomplete="name">
    </div>
    <div class="field">
      <label for="fEmail">Email (login)</label>
      <input type="email" id="fEmail" value="<?php echo htmlspecialchars($email); ?>" disabled>
      <div class="hint">Contact support to change your login email.</div>
    </div>
    <div class="field">
      <label for="fPhone">Phone</label>
      <input type="tel" id="fPhone" value="<?php echo htmlspecialchars($phone); ?>" autocomplete="tel">
    </div>
    <button class="btn" id="saveProfile"><i class="fas fa-save"></i> Save Changes</button>
    <div class="msg" id="profileMsg"></div>
  </div>

  <!-- PASSWORD -->
  <div class="card">
    <h2><i class="fas fa-lock"></i> Change Password</h2>
    <div class="field">
      <label for="curPass">Current password</label>
      <input type="password" id="curPass" autocomplete="current-password">
    </div>
    <div class="field">
      <label for="newPass">New password</label>
      <input type="password" id="newPass" autocomplete="new-password">
      <div class="hint">At least 6 characters.</div>
    </div>
    <button class="btn" id="savePass"><i class="fas fa-key"></i> Update Password</button>
    <div class="msg" id="passMsg"></div>
  </div>

  <a class="signout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Sign out</a>
</div>

<script>
  async function api(action, body){
    const r = await fetch('account.php?action=' + action, {
      method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    return r.json();
  }
  function show(el, ok, text){ el.className = 'msg ' + (ok ? 'ok' : 'err'); el.textContent = text; }

  document.getElementById('saveProfile').addEventListener('click', async function(){
    const btn = this, msg = document.getElementById('profileMsg');
    const name = document.getElementById('fName').value.trim();
    const phone = document.getElementById('fPhone').value.trim();
    btn.disabled = true;
    try {
      const res = await api('updateProfile', {name, phone});
      if (res.success){
        show(msg, true, 'Saved.');
        document.getElementById('dName').textContent = name || '—';
        document.getElementById('dPhone').textContent = phone || '—';
      } else { show(msg, false, res.error || 'Could not save.'); }
    } catch(e){ show(msg, false, 'Network error. Please try again.'); }
    btn.disabled = false;
  });

  document.getElementById('savePass').addEventListener('click', async function(){
    const btn = this, msg = document.getElementById('passMsg');
    const current = document.getElementById('curPass').value;
    const nw = document.getElementById('newPass').value;
    if (nw.length < 6){ show(msg, false, 'New password must be at least 6 characters.'); return; }
    btn.disabled = true;
    try {
      const res = await api('changePassword', {current, new: nw});
      if (res.success){
        show(msg, true, 'Password updated.');
        document.getElementById('curPass').value = '';
        document.getElementById('newPass').value = '';
      } else { show(msg, false, res.error || 'Could not update password.'); }
    } catch(e){ show(msg, false, 'Network error. Please try again.'); }
    btn.disabled = false;
  });

  async function cancelSub(){
    if (!confirm('Cancel your subscription?\n\nThis takes effect immediately: your paid plan ends now and you will not be charged again. Credits already on your account stay yours to use.')) return;
    const btn = document.getElementById('cancelSubBtn');
    btn.disabled = true; btn.textContent = 'Canceling…';
    try {
      const res = await api('cancelSubscription', {});
      if (res.success){
        alert('Your subscription has been canceled. You will not be charged again.');
        location.reload();
      } else {
        alert(res.error || 'Could not cancel — please contact support.');
        btn.disabled = false; btn.textContent = 'Cancel Subscription';
      }
    } catch(e){
      alert('Network error — please try again.');
      btn.disabled = false; btn.textContent = 'Cancel Subscription';
    }
  }
</script>
</body>
</html>
