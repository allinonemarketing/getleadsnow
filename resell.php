<?php
session_start();
require_once 'includes/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit(); }
$appLogo = defined('APP_LOGO') ? APP_LOGO : '/assets/logo.svg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Get Leads For Less Than 1 Penny</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{ --ink:#141517; --muted:#5b6066; --faint:#7a8088; --line:rgba(20,21,23,.10); --bg:#f6f7f9;
         --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4; --green:#17813f; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  .wrap{max-width:900px;margin:0 auto;padding:36px 24px 60px}
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);background:var(--accent-soft);padding:7px 13px;border-radius:999px}
  h1{font-size:clamp(1.9rem,4vw,2.7rem);font-weight:900;letter-spacing:-.02em;line-height:1.08;margin:16px 0 10px;text-wrap:balance}
  h1 .hl{color:var(--accent)}
  .lede{font-size:17px;color:var(--muted);max-width:60ch}
  /* video placeholder — swap the inner markup for your <iframe> embed when ready */
  .video{position:relative;margin:26px 0 8px;border-radius:18px;overflow:hidden;box-shadow:0 24px 60px rgba(16,20,30,.18);background:#1a1c1f}
  .cost{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:22px 0 2px}
  .cost .big{font-size:clamp(2rem,5vw,3rem);font-weight:900;color:var(--green);letter-spacing:-.02em}
  .cost .sub{font-size:14px;color:var(--muted)}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:26px 0}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px}
  .card i{color:var(--accent);font-size:20px}
  .card h3{font-size:15px;font-weight:800;margin:12px 0 5px}
  .card p{font-size:13.5px;color:var(--muted)}
  .points{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-top:6px}
  .points h2{font-size:18px;font-weight:800;margin-bottom:12px}
  .points li{list-style:none;display:flex;gap:11px;align-items:flex-start;font-size:14.5px;margin-bottom:11px;color:var(--ink)}
  .points li i{color:var(--green);margin-top:3px}
  .cta{margin-top:26px;background:linear-gradient(135deg,#1a1c1f,#25292e);color:#fff;border-radius:18px;padding:28px;text-align:center}
  .cta h2{font-size:20px;font-weight:800;margin-bottom:6px}
  .cta p{color:#c8ccd2;font-size:14.5px;margin-bottom:16px}
  .cta a{display:inline-flex;align-items:center;gap:9px;background:var(--accent);color:#fff;font-weight:800;font-size:15px;text-decoration:none;padding:13px 24px;border-radius:12px}
  @media(max-width:720px){ .grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="wrap">
  <span class="eyebrow"><i class="fas fa-bolt"></i> Owner &amp; Reseller Program</span>
  <h1>Get leads for <span class="hl">less than 1 penny</span> each &mdash; and resell this tool for profit.</h1>
  <p class="lede">Right now each lead costs 1 credit. When you own the software, you plug in your own data source and pull leads at raw cost &mdash; fractions of a penny each &mdash; then sell the exact same tool to your clients as a recurring revenue stream.</p>

  <!-- Vidalytics video embed -->
  <div class="video">
    <div id="vidalytics_embed_xePpQ8RsRXz6j0ye" style="width: 100%; position:relative; padding-top: 56.25%;"></div>
    <script type="text/javascript">
    (function (v, i, d, a, l, y, t, c, s) {
        y='_'+d.toLowerCase();c=d+'L';if(!v[d]){v[d]={};}if(!v[c]){v[c]={};}if(!v[y]){v[y]={};}var vl='Loader',vli=v[y][vl],vsl=v[c][vl + 'Script'],vlf=v[c][vl + 'Loaded'],ve='Embed';
        if (!vsl){vsl=function(u,cb){
            if(t){cb();return;}s=i.createElement("script");s.type="text/javascript";s.async=1;s.src=u;
            if(s.readyState){s.onreadystatechange=function(){if(s.readyState==="loaded"||s.readyState=="complete"){s.onreadystatechange=null;vlf=1;cb();}};}else{s.onload=function(){vlf=1;cb();};}
            i.getElementsByTagName("head")[0].appendChild(s);
        };}
        vsl(l+'loader.min.js',function(){if(!vli){var vlc=v[c][vl];vli=new vlc();}vli.loadScript(l+'player.min.js',function(){var vec=v[d][ve];t=new vec();t.run(a);});});
    })(window, document, 'Vidalytics', 'vidalytics_embed_xePpQ8RsRXz6j0ye', 'https://quick.vidalytics.com/embeds/rkXHVyr9/xePpQ8RsRXz6j0ye/');
    </script>
  </div>

  <div class="cost">
    <div class="big">&lt; $0.01</div>
    <div class="sub">per lead when you own the platform<br>vs. 1 credit per lead today</div>
  </div>

  <div class="cta">
    <h2>Interested In Getting This?</h2>
    <p>Watch the video above, then reach out and we&rsquo;ll walk you through getting set up.</p>
    <a href="sms:+13479211788?&body=Software"><i class="fas fa-comment-dots"></i> Text &ldquo;Software&rdquo; to 347-921-1788</a>
  </div>

  <div class="grid">
    <div class="card"><i class="fas fa-coins"></i><h3>Leads at cost</h3><p>We give you direct access to our partners so you get the leads at cost!</p></div>
    <div class="card"><i class="fas fa-tags"></i><h3>Sell it yourself</h3><p>You own the software and resell it to your clients at whatever price you choose.</p></div>
    <div class="card"><i class="fas fa-arrow-trend-up"></i><h3>Recurring revenue</h3><p>Turn a tool you already use into a monthly income stream you fully control.</p></div>
  </div>

  <div class="points">
    <h2>How it works</h2>
    <ul>
      <li><i class="fas fa-check-circle"></i> You own this exact software.</li>
      <li><i class="fas fa-check-circle"></i> We give you direct access to the data at cost.</li>
      <li><i class="fas fa-check-circle"></i> You add your branding and set your own prices for clients.</li>
      <li><i class="fas fa-check-circle"></i> You keep the recurring revenue from every client you sign up.</li>
    </ul>
  </div>

</div>
</body>
</html>
