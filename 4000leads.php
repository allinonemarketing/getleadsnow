<?php
/**
 * /4000leads — HIDDEN invite channel. Account creation only (no marketing
 * sections, no Meta pixel, noindex). Signups through this page are stamped
 * signup_source=free_4000leads and register.php grants them 4,000 credits
 * instead of the standard free tier. Do not link this page anywhere public.
 */
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) { header('Location: /dashboard'); exit; }
$appName = defined('APP_NAME') ? APP_NAME : 'All In One Leads Tool';
$appLogo = (defined('APP_LOGO') && APP_LOGO) ? APP_LOGO : '/assets/logo.svg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Private Invite — <?php echo htmlspecialchars($appName); ?></title>
<link rel="icon" type="image/jpeg" href="<?php echo htmlspecialchars($appLogo); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;background:#f6f7f9;color:#141517;-webkit-font-smoothing:antialiased;line-height:1.5}
  .logo{display:flex;justify-content:center;padding:26px 0 8px}
  .logo img{height:42px;width:auto}
  .wrap{max-width:520px;margin:18px auto 40px;padding:0 18px}
  .card{background:#fff;border:1px solid rgba(20,21,23,.09);border-radius:20px;box-shadow:0 30px 80px rgba(16,20,30,.12);padding:32px 28px;text-align:center}
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#c85719;background:#fdeee4;padding:7px 13px;border-radius:999px;margin-bottom:16px}
  h1{font-size:clamp(1.5rem,4.5vw,1.95rem);font-weight:900;letter-spacing:-.02em;line-height:1.15}
  h1 .hl{color:#c85719}
  .sub{color:#5b6066;font-size:14.5px;margin:10px 0 22px}
  .field{margin-bottom:13px;text-align:left}
  .field label{display:block;font-size:13px;font-weight:700;margin-bottom:5px}
  .field input{width:100%;background:#fff;border:1.5px solid #d5d8dc;border-radius:10px;padding:13px 14px;font-size:15px;font-family:inherit}
  .field input:focus{outline:none;border-color:#c85719;box-shadow:0 0 0 3px rgba(200,87,25,.15)}
  .fe{display:none;color:#c0392b;font-size:12.5px;font-weight:500;margin-top:4px}
  .bad{border-color:#c0392b !important}
  .btn{width:100%;margin-top:6px;background:#c85719;color:#fff;border:none;border-radius:12px;padding:16px;font-size:17px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 8px 24px rgba(200,87,25,.32);transition:background .12s}
  .btn:hover{background:#a8460f}
  .btn:disabled{opacity:.6;cursor:default}
  .err{display:none;background:#fdecea;color:#c0392b;border-radius:9px;padding:10px 13px;font-size:13.5px;font-weight:500;margin-bottom:12px;text-align:left}
  .err a{color:#c0392b;font-weight:700}
  .tcpa{margin-top:14px;font-size:10px;line-height:1.55;color:#9aa0a8;text-align:left}
  .tcpa a{color:#9aa0a8}
  .micro{margin-top:12px;font-size:12.5px;font-weight:600;color:#5b6066}
</style>
</head>
<body>

<div class="logo"><img src="<?php echo htmlspecialchars($appLogo); ?>" alt="<?php echo htmlspecialchars($appName); ?>"></div>

<div class="wrap">
  <div class="card">
    <div class="eyebrow">Private invite</div>
    <h1>Create your account and get <span class="hl">4,000 free leads</span></h1>
    <p class="sub">You&rsquo;ve been given special access &mdash; 4,000 lead credits are added to your account the moment it&rsquo;s created. No credit card.</p>

    <div class="err" id="err" role="alert" aria-live="assertive"></div>
    <form id="leadForm" novalidate>
      <div class="field"><label for="f_name">Full name</label><input type="text" id="f_name" placeholder="Jordan Blake" autocomplete="name" autocapitalize="words" required><div class="fe" id="fe_name">Please enter your name.</div></div>
      <div class="field"><label for="f_email">Email</label><input type="email" id="f_email" placeholder="you@company.com" autocomplete="email" inputmode="email" autocapitalize="none" required><div class="fe" id="fe_email">Please enter a valid email.</div></div>
      <div class="field"><label for="f_phone">Phone</label><input type="tel" id="f_phone" placeholder="(555) 123-4567" autocomplete="tel-national" inputmode="tel" required><div class="fe" id="fe_phone">Please enter a valid 10-digit phone number.</div></div>
      <button type="submit" class="btn" id="submitBtn"><span id="btnLabel">Create My Account &rarr;</span></button>
      <div class="micro">Instant access &middot; Your login details are emailed to you</div>
      <div class="tcpa">By clicking &ldquo;Create My Account&rdquo;, I consent to receive calls, texts, and emails from All In One Marketing.com via automated calling and prerecorded voice; consent not required to purchase &mdash; opt out anytime at info@allinonemarketing.com. I agree to the <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a> &amp; <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a> (incl. arbitration). Msg &amp; data rates may apply.</div>
    </form>
  </div>
</div>

<script>
(function(){
  const F={name:document.getElementById('f_name'),email:document.getElementById('f_email'),phone:document.getElementById('f_phone')};
  const err=document.getElementById('err'), form=document.getElementById('leadForm');

  // Phone mask: (xxx) xxx-xxxx; leading 1/0 (country code) stripped.
  F.phone.addEventListener('input',function(){
    var d=F.phone.value.replace(/\D+/g,'').replace(/^[01]+/,'').slice(0,10);
    F.phone.value = d.length>6 ? '('+d.slice(0,3)+') '+d.slice(3,6)+'-'+d.slice(6)
                  : d.length>3 ? '('+d.slice(0,3)+') '+d.slice(3)
                  : d.length>0 ? '('+d : '';
  });

  function setField(el,ok){ el.classList.toggle('bad',!ok); const fe=document.getElementById('fe_'+el.id.slice(2)); if(fe) fe.style.display=ok?'none':'block'; return ok; }
  function vName(){ return setField(F.name,F.name.value.trim().length>=2); }
  function vEmail(){ return setField(F.email,/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(F.email.value.trim())); }
  function vPhone(){ return setField(F.phone,F.phone.value.replace(/\D+/g,'').length===10); }
  [F.name,F.email,F.phone].forEach(el=>el.addEventListener('input',function(){ if(el.classList.contains('bad')) setField(el,true); }));

  form.addEventListener('submit',function(e){
    e.preventDefault();
    const a=vName(), b=vEmail(), c=vPhone();
    if(!(a&&b&&c)){ (!a?F.name:!b?F.email:F.phone).focus(); return; }
    const btn=document.getElementById('submitBtn'), lbl=document.getElementById('btnLabel');
    btn.disabled=true; lbl.textContent='Creating your account…'; err.style.display='none';
    var settled=false;
    var hangTimer=setTimeout(function(){ if(settled) return; settled=true; btn.disabled=false; lbl.textContent='Create My Account →';
      err.textContent='That took longer than expected — please try again.'; err.style.display='block'; },15000);
    const fd=new FormData();
    fd.append('name',F.name.value.trim()); fd.append('email',F.email.value.trim());
    fd.append('phone',F.phone.value.trim()); fd.append('wants_ownership','no');
    fd.append('signup_source','free_4000leads');   // /4000leads = hidden invite channel (4,000 credits)
    fd.append('event_source_url',location.href);
    try{ fd.append('timezone',Intl.DateTimeFormat().resolvedOptions().timeZone||''); }catch(e2){}
    fd.append('referrer',document.referrer||'');
    fetch('register.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if(settled) return; settled=true; clearTimeout(hangTimer);
      if(data.success){ window.location.href='/dashboard'; }
      else{
        btn.disabled=false; lbl.textContent='Create My Account →';
        err.textContent=(data.message||'Something went wrong — please try again.')+' ';
        if(/already|exists/i.test(data.message||'')){ var l=document.createElement('a'); l.href='/login'; l.textContent='Log in →'; err.appendChild(l); }
        err.style.display='block';
      }
    }).catch(function(){
      if(settled) return; settled=true; clearTimeout(hangTimer);
      btn.disabled=false; lbl.textContent='Create My Account →';
      err.textContent='Network error — please try again.'; err.style.display='block';
    });
  });
})();

// Landing-page analytics beacon (admin "Landing Pages" card).
(function(){try{
  var vid=localStorage.getItem('aiom_vid');
  if(!vid){vid=Date.now().toString(36)+Math.random().toString(36).slice(2,10);localStorage.setItem('aiom_vid',vid);}
  var page=(location.pathname.split('/')[1]||'start').toLowerCase();
  var t0=Date.now(),maxs=0,rowId=null;
  var fd=new FormData();fd.append('a','view');fd.append('p',page);fd.append('v',vid);
  fetch('/lp_track.php',{method:'POST',body:fd,keepalive:true}).then(function(r){return r.json()}).then(function(j){rowId=j&&j.id||null;}).catch(function(){});
  addEventListener('scroll',function(){var d=document.documentElement;var p=Math.round((window.scrollY+window.innerHeight)/d.scrollHeight*100);if(p>maxs)maxs=Math.min(100,p);},{passive:true});
  function curp(){var d=document.documentElement;return Math.min(100,Math.round((window.scrollY+window.innerHeight)/d.scrollHeight*100));}
  function fin(){if(!rowId)return;var f=new FormData();f.append('a','fin');f.append('id',rowId);f.append('s',Math.round((Date.now()-t0)/1000));f.append('sc',Math.max(maxs,curp()));navigator.sendBeacon('/lp_track.php',f);}
  addEventListener('pagehide',fin);
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')fin();});
}catch(e){}})();
</script>
</body>
</html>
