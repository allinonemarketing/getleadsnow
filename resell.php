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
  .video{position:relative;margin:26px 0 8px;border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#1a1c1f,#25292e);box-shadow:0 24px 60px rgba(16,20,30,.18);aspect-ratio:16/9;display:flex;align-items:center;justify-content:center}
  .video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
  .video .play{width:78px;height:78px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;box-shadow:0 10px 30px rgba(200,87,25,.45)}
  .video .vnote{position:absolute;bottom:16px;left:0;right:0;text-align:center;color:#c8ccd2;font-size:13px;font-weight:600}
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

  <!-- ===== VIDEO PLACEHOLDER — replace the inner content with your embed, e.g.:
       <iframe src="https://www.youtube.com/embed/VIDEO_ID" allowfullscreen></iframe>  ===== -->
  <div class="video">
    <div class="play"><i class="fas fa-play"></i></div>
    <div class="vnote">Your video goes here</div>
  </div>

  <div class="cost">
    <div class="big">&lt; $0.01</div>
    <div class="sub">per lead when you own the platform<br>vs. 1 credit per lead today</div>
  </div>

  <div class="grid">
    <div class="card"><i class="fas fa-coins"></i><h3>Leads at cost</h3><p>Pull leads at raw data cost &mdash; a fraction of a penny each &mdash; instead of spending credits.</p></div>
    <div class="card"><i class="fas fa-tags"></i><h3>Sell it yourself</h3><p>White-label the whole platform and resell it to your clients at whatever price you choose.</p></div>
    <div class="card"><i class="fas fa-arrow-trend-up"></i><h3>Recurring revenue</h3><p>Turn a tool you already use into a monthly income stream you fully control.</p></div>
  </div>

  <div class="points">
    <h2>How it works</h2>
    <ul>
      <li><i class="fas fa-check-circle"></i> You get your own white-label copy of this lead + CRM platform.</li>
      <li><i class="fas fa-check-circle"></i> You connect it to the data source directly, so leads cost you pennies &mdash; not credits.</li>
      <li><i class="fas fa-check-circle"></i> You add your branding and set your own prices for clients.</li>
      <li><i class="fas fa-check-circle"></i> You keep the recurring revenue from every client you sign up.</li>
    </ul>
  </div>

  <div class="cta">
    <h2>Want the full breakdown?</h2>
    <p>Watch the video above, then reach out and we&rsquo;ll walk you through getting set up.</p>
    <a href="mailto:sales@allinonemarketing.com?subject=Owner%20%26%20Reseller%20Program"><i class="fas fa-envelope"></i> Email sales@allinonemarketing.com</a>
  </div>
</div>
</body>
</html>
