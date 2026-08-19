<?php
session_start();
require_once 'includes/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit(); }
session_write_close();  // release the per-user session lock; these pages only read the session
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Support</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{ --ink:#141517; --muted:#5b6066; --line:rgba(20,21,23,.10); --bg:#f6f7f9; --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 24px}
  .card{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 24px 60px rgba(16,20,30,.10);padding:44px 40px;max-width:520px;width:100%;text-align:center}
  .icon{width:64px;height:64px;border-radius:16px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 20px}
  h1{font-size:24px;font-weight:900;letter-spacing:-.02em;margin-bottom:10px}
  p{font-size:16px;color:var(--muted);margin-bottom:24px}
  a.btn{display:inline-flex;align-items:center;gap:9px;background:var(--accent);color:#fff;font-weight:800;font-size:15px;text-decoration:none;padding:14px 26px;border-radius:12px;box-shadow:0 8px 24px rgba(200,87,25,.28)}
  a.btn:hover{background:var(--accent-d)}
  .email{margin-top:16px;font-size:13px;color:var(--muted)}
  .email a{color:var(--accent-d);font-weight:700;text-decoration:none}
</style>
</head>
<body>
  <div class="card">
    <div class="icon"><i class="fas fa-life-ring"></i></div>
    <h1>Need help or have questions?</h1>
    <p>Email <strong>sales@allinonemarketing.com</strong> for assistance and our team will get back to you.</p>
    <a class="btn" href="mailto:sales@allinonemarketing.com?subject=Support"><i class="fas fa-envelope"></i> Email us</a>
  </div>
</body>
</html>
