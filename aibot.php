<?php
session_start();
require_once 'includes/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit(); }
$TRIAL_URL = 'https://allinoneaibot.com/trial#get-started';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Get AI Calling Bot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{
    --ink:#101828; --muted:#5b6472; --faint:#7a828e; --line:rgba(15,24,40,.10);
    --bg:#f5f7fb; --navy:#12315c; --navy-d:#0d2547; --blue:#2563eb; --blue-d:#1d4ed8;
    --blue-soft:#eaf1ff; --green:#16a34a; --gold:#eab308;
  }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  a{color:inherit}
  .wrap{max-width:1060px;margin:0 auto;padding:0 24px}
  .sec{padding:52px 0}
  h1{font-size:clamp(2.1rem,4.6vw,3.1rem);font-weight:900;letter-spacing:-.025em;line-height:1.07;text-wrap:balance}
  h1 .hl{color:var(--blue)}
  h2{font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;letter-spacing:-.02em;line-height:1.12}
  .lede{font-size:17px;color:var(--muted);max-width:62ch;margin:16px auto 0}
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--blue-d);background:var(--blue-soft);padding:7px 14px;border-radius:999px}
  .btn{display:inline-flex;align-items:center;gap:9px;font-weight:800;font-size:15.5px;text-decoration:none;padding:15px 28px;border-radius:12px;cursor:pointer;border:none;font-family:inherit}
  .btn-primary{background:var(--navy);color:#fff;box-shadow:0 10px 26px rgba(18,49,92,.30)}
  .btn-primary:hover{background:var(--navy-d)}
  .btn-ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .btn-ghost:hover{border-color:#c7ced9}
  .center{text-align:center}
  /* integration bar */
  .intbar{background:linear-gradient(90deg,#0d2547,#1b3f70);color:#fff;text-align:center;font-size:14.5px;font-weight:600;padding:11px 18px;line-height:1.5}
  .intbar b{font-weight:800}
  .intbar i{color:#7fb0ff;margin-right:7px}
  /* hero */
  .hero{background:linear-gradient(180deg,#fff 0%,var(--bg) 100%);border-bottom:1px solid var(--line);text-align:center;padding:44px 0 46px}
  .hero .cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:26px}
  .hero .integ{display:inline-flex;align-items:flex-start;gap:10px;background:#eafaf0;border:1px solid #c9edd6;color:#14683a;font-weight:600;font-size:14.5px;border-radius:14px;padding:12px 18px;margin:18px auto 0;max-width:60ch;line-height:1.5;text-align:left}
  .hero .integ i{color:var(--green);flex:none;margin-top:2px;font-size:16px}
  .hero .integ b{font-weight:800;color:#0f5230}
  .trust{margin-top:16px;color:var(--faint);font-size:13px;font-weight:600}
  .trust i{color:var(--green);margin-right:5px}
  /* stat row */
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:36px}
  .stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 16px;text-align:center}
  .stat .n{font-size:26px;font-weight:900;letter-spacing:-.02em;color:var(--navy)}
  .stat .l{font-size:12.5px;color:var(--faint);margin-top:3px;font-weight:600}
  /* headings block */
  .h2c{text-align:center;max-width:720px;margin:0 auto 32px}
  .h2c p{color:var(--muted);font-size:16px;margin-top:10px}
  /* security strip */
  .secure{background:var(--navy);color:#fff}
  .secure .wrap{padding:26px 24px;text-align:center}
  .secure h3{font-size:15px;font-weight:800;letter-spacing:.01em;margin-bottom:16px;color:#cfe0ff}
  .secure .badges{display:flex;flex-wrap:wrap;gap:10px 22px;justify-content:center}
  .secure .badges span{font-size:13.5px;font-weight:700;color:#e6eefc;display:inline-flex;align-items:center;gap:8px}
  .secure .badges i{color:#7fb0ff}
  /* money cards */
  .money{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .mcard{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px 24px}
  .mcard .big{font-size:22px;font-weight:900;letter-spacing:-.02em;color:var(--blue);line-height:1.2;margin-bottom:10px}
  .mcard p{color:var(--muted);font-size:14px;line-height:1.6}
  /* generic cards row */
  .cards3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .c3{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px}
  .c3 .ci{width:40px;height:40px;border-radius:11px;background:var(--blue-soft);color:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:14px}
  .c3 h3{font-size:16px;font-weight:800;margin-bottom:7px}
  .c3 p{color:var(--muted);font-size:13.5px;line-height:1.6}
  .benef{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:22px}
  .benef span{font-size:13px;font-weight:700;color:var(--green);background:#eafaf0;border:1px solid #c9edd6;border-radius:999px;padding:8px 15px}
  .benef i{margin-right:6px}
  /* feature grid */
  .feats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
  .feat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px}
  .feat .fi{width:38px;height:38px;border-radius:10px;background:var(--blue-soft);color:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:12px}
  .feat h3{font-size:15px;font-weight:800;margin-bottom:6px}
  .feat p{font-size:13px;color:var(--muted);line-height:1.55}
  /* voice ai — dark */
  .voice{background:var(--navy);color:#fff}
  .voice .h2c h2{color:#fff}
  .voice .h2c p{color:#b9c9e4}
  .voice .feats .feat{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12)}
  .voice .feat .fi{background:rgba(127,176,255,.16);color:#9fc0ff}
  .voice .feat h3{color:#fff}
  .voice .feat p{color:#c3d0e6}
  /* steps */
  .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
  .step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px}
  .step .num{width:38px;height:38px;border-radius:11px;background:var(--navy);color:#fff;font-weight:900;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:14px}
  .step h3{font-size:15.5px;font-weight:800;margin-bottom:6px}
  .step p{font-size:13.5px;color:var(--muted);line-height:1.55}
  /* industries */
  .chips{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;max-width:820px;margin:0 auto}
  .chips span{font-size:14px;font-weight:700;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:999px;padding:10px 18px}
  /* testimonials */
  .tests{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .test{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px 24px;display:flex;flex-direction:column}
  .test .stars{color:var(--gold);font-size:14px;letter-spacing:1px;margin-bottom:12px}
  .test .q{font-size:14px;line-height:1.62;color:var(--ink);flex:1}
  .test .who{margin-top:18px;display:flex;align-items:center;gap:12px}
  .test .av{width:42px;height:42px;border-radius:50%;background:var(--navy);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:15px;flex:none}
  .test .nm{font-weight:800;font-size:13.5px}
  .test .rl{font-size:12px;color:var(--faint);margin-top:1px}
  .test .metric{margin-top:14px;font-size:12.5px;font-weight:800;color:var(--blue-d);background:var(--blue-soft);border-radius:8px;padding:7px 11px;align-self:flex-start}
  /* pricing */
  .price-block{background:#fff;border:1px solid var(--line);border-radius:22px;padding:38px 32px;max-width:520px;margin:0 auto;text-align:center}
  .price-block .plan{font-size:14px;font-weight:800;color:var(--blue-d);text-transform:uppercase;letter-spacing:.05em}
  .price-block .amt{font-size:52px;font-weight:900;letter-spacing:-.03em;margin:8px 0 2px}
  .price-block .amt span{font-size:17px;font-weight:700;color:var(--faint)}
  .price-block .then{font-size:14px;color:var(--muted);margin-bottom:8px}
  .plist{text-align:left;margin:20px 0;display:grid;gap:10px}
  .plist li{list-style:none;display:flex;gap:10px;align-items:flex-start;font-size:14px;color:var(--ink)}
  .plist i{color:var(--green);margin-top:3px;flex:none}
  .price-block .note{font-size:12.5px;color:var(--faint);margin-top:14px;line-height:1.5}
  /* faq */
  .faq{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:12px;overflow:hidden;max-width:780px;margin-left:auto;margin-right:auto}
  .faq summary{list-style:none;cursor:pointer;padding:18px 20px;font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:space-between;gap:14px}
  .faq summary::-webkit-details-marker{display:none}
  .faq summary::after{content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;font-size:12px;color:var(--blue);transition:transform .2s;flex:none}
  .faq[open] summary::after{transform:rotate(180deg)}
  .faq .body{padding:0 20px 18px;font-size:14px;line-height:1.65;color:var(--muted)}
  /* final cta */
  .final{background:linear-gradient(135deg,#0d2547,#1e4d8a);color:#fff;border-radius:24px;padding:48px 32px;text-align:center}
  .final .eyebrow{background:rgba(127,176,255,.16);color:#bcd4ff}
  .final h2{margin-top:14px}
  .final p{color:#c8d6ec;font-size:16px;margin:12px auto 22px;max-width:48ch}
  .final .fine{margin-top:14px;color:#9fb2ce;font-size:12.5px}
  footer.ft{border-top:1px solid var(--line);padding:28px 0;text-align:center;color:var(--faint);font-size:13px}
  @media(max-width:860px){
    .stats{grid-template-columns:repeat(2,1fr)}
    .money,.cards3,.feats,.steps,.tests{grid-template-columns:1fr}
  }
</style>
</head>
<body>

<!-- INTEGRATION BAR -->
<div class="intbar"><i class="fas fa-bolt"></i> <b>Integrates directly with your lead generation software</b> &mdash; every lead you pull gets called &amp; texted automatically. <b>A 14-day free trial, exclusively for you.</b></div>

<!-- HERO -->
<section class="hero">
  <div class="wrap">
    <span class="eyebrow"><i class="fas fa-robot"></i> AI Calling &amp; Texting Bot &middot; 14-Day Free Trial</span>
    <h1 style="margin:16px 0 0">Turn every lead into a <span class="hl">booked call</span> &mdash; free for 14 days.</h1>
    <p class="lede">All In One Ai Bot engages your leads with personalized, human-like text messages <strong>and live phone calls</strong> 24/7 &mdash; nurturing conversations and booking consultation calls while you focus on closing deals. Start in minutes with no A2P registration required.</p>
    <div class="integ"><i class="fas fa-plug"></i> <span>Plugs straight into your lead software &mdash; <b>the leads you generate here are automatically called and texted</b> by the AI until they book. This 14-day trial is exclusive to you as a member.</span></div>
    <div class="cta">
      <a class="btn btn-primary" href="<?php echo $TRIAL_URL; ?>" target="_blank" rel="noopener"><i class="fas fa-rocket"></i> Start Free Trial &mdash; 14 Days Free</a>
      <a class="btn btn-ghost" href="#how">See How It Works</a>
    </div>
    <div class="trust"><i class="fas fa-circle-check"></i> No A2P registration &middot; Set up in under 5 minutes &middot; Cancel anytime</div>

    <div class="stats">
      <div class="stat"><div class="n">3.2M+</div><div class="l">Messages Sent</div></div>
      <div class="stat"><div class="n">47%</div><div class="l">Avg Response Rate</div></div>
      <div class="stat"><div class="n">12,000+</div><div class="l">Calls Booked</div></div>
      <div class="stat"><div class="n">500+</div><div class="l">Active Businesses</div></div>
    </div>
  </div>
</section>

<!-- SECURITY STRIP -->
<section class="secure">
  <div class="wrap">
    <h3>Built With Enterprise-Grade Security &amp; Compliance</h3>
    <div class="badges">
      <span><i class="fas fa-lock"></i> 256-bit AES Encryption</span>
      <span><i class="fas fa-shield-halved"></i> SOC 2 Type II Ready</span>
      <span><i class="fas fa-circle-check"></i> TCPA Compliant</span>
      <span><i class="fas fa-user-shield"></i> HIPAA Aware</span>
      <span><i class="fas fa-signal"></i> 99.9% Uptime SLA</span>
    </div>
  </div>
</section>

<!-- LEAVING MONEY -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>You&rsquo;re leaving money on the table</h2></div>
    <div class="money">
      <div class="mcard"><div class="big">78% of leads buy from the first responder</div><p>Speed-to-lead is the #1 factor in conversion. All In One Ai Bot responds to every lead within seconds, 24/7.</p></div>
      <div class="mcard"><div class="big">98% of texts are opened within 3 minutes</div><p>SMS has 5x the engagement of email. Our AI crafts personalized messages that feel human and build trust.</p></div>
      <div class="mcard"><div class="big">3.5&times; more consultations booked on average</div><p>Businesses using All In One Ai Bot book 3.5x more calls than those doing manual follow-up. Every single month.</p></div>
    </div>
  </div>
</section>

<!-- NO A2P -->
<section class="sec" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>No A2P registration required &mdash; start texting today</h2><p>Traditional SMS platforms make you register your own A2P 10DLC brand &mdash; 3&ndash;7 business days and hundreds in fees. On the Starter plan, we handle it all for you.</p></div>
    <div class="cards3">
      <div class="c3"><div class="ci"><i class="fas fa-bolt"></i></div><h3>Instant Activation</h3><p>Your dedicated platform phone number is provisioned the moment you complete onboarding. No waiting, no paperwork.</p></div>
      <div class="c3"><div class="ci"><i class="fas fa-tower-broadcast"></i></div><h3>Platform-Managed Campaign</h3><p>We operate a registered A2P 10DLC campaign on your behalf. Your messages are compliant from day one &mdash; no registration fees on you.</p></div>
      <div class="c3"><div class="ci"><i class="fas fa-comment-sms"></i></div><h3>150 Messages Per Day</h3><p>Send up to 150 outbound SMS per day on the shared platform campaign. Ready to scale? Register your own brand anytime.</p></div>
    </div>
    <div class="benef">
      <span><i class="fas fa-check"></i> No A2P registration fees</span>
      <span><i class="fas fa-check"></i> Dedicated phone number included</span>
      <span><i class="fas fa-check"></i> Start texting in under 5 minutes</span>
    </div>
  </div>
</section>

<!-- FEATURE GRID -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>Everything you need to convert leads on autopilot</h2><p>A complete AI-powered SMS &amp; voice CRM built for professionals in any industry.</p></div>
    <div class="feats">
      <div class="feat"><div class="fi"><i class="fas fa-comments"></i></div><h3>AI-Powered Conversations</h3><p>Our AI understands your business, handles objections, and guides leads toward booking a call &mdash; all in a warm, human tone.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-bolt"></i></div><h3>Instant Lead Response</h3><p>New leads get a personalized text within seconds. No more lost opportunities from slow follow-up.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-calendar-check"></i></div><h3>Automated Call Booking</h3><p>The AI sends your booking link at the perfect moment and tracks when leads click, book, or complete calls.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-users"></i></div><h3>Smart Lead CRM</h3><p>Track every lead&rsquo;s status, conversation history, and engagement score in one organized dashboard.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-chart-line"></i></div><h3>Real-Time Analytics</h3><p>See your response rates, conversion funnel, booking rates, and ROI at a glance. Know exactly what&rsquo;s working.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-shield-halved"></i></div><h3>Compliance &amp; Consent</h3><p>Built-in SMS consent tracking, opt-out handling, and audit logs keep you TCPA compliant automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-graduation-cap"></i></div><h3>Trainable Knowledge Base</h3><p>Teach the AI about your specific services, company, and communication style. It learns how YOU engage clients.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-list-check"></i></div><h3>Multi-Step Follow-Up</h3><p>Automated drip sequences that nurture cold leads over days and weeks until they&rsquo;re ready to talk.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-hashtag"></i></div><h3>Platform Phone Number Included</h3><p>Get a dedicated number instantly &mdash; no A2P registration required. Your leads see a consistent number, your brand.</p></div>
    </div>
  </div>
</section>

<!-- VOICE AI -->
<section class="sec voice" id="how">
  <div class="wrap">
    <div class="h2c"><h2>Your AI answers and dials &mdash; around the clock</h2><p>Go beyond texting. Our Voice AI handles inbound calls and places outbound calls on your behalf &mdash; qualifying prospects and booking appointments while you focus on serving clients.</p></div>
    <div class="feats">
      <div class="feat"><div class="fi"><i class="fas fa-phone-volume"></i></div><h3>Inbound Calls</h3><p>Every incoming call is answered by your AI agent. It introduces your business, qualifies the caller, and books them right on the call &mdash; no voicemail, no missed opportunities.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-phone-flip"></i></div><h3>Outbound Calls</h3><p>Your AI proactively dials leads from your pipeline &mdash; delivering a personalized pitch, handling objections, and pushing toward a booked consultation without lifting a finger.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-file-lines"></i></div><h3>Real-Time Transcription</h3><p>Every call is transcribed live. Searchable transcripts are saved to the lead record automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-user-gear"></i></div><h3>Custom AI Personality</h3><p>Configure a unique voice, tone, script, and objection-handling playbook for each call direction.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-calendar-day"></i></div><h3>In-Call Booking</h3><p>The AI checks your real-time calendar availability and confirms appointments before hanging up.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-clipboard-list"></i></div><h3>Call Summaries</h3><p>After every call, an AI-generated summary and outcome label are added to the lead timeline.</p></div>
    </div>
  </div>
</section>

<!-- SETUP STEPS -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>Up and running in under 5 minutes</h2><p>No technical knowledge required. Your phone number is provisioned instantly &mdash; no A2P waiting period.</p></div>
    <div class="steps">
      <div class="step"><div class="num">1</div><h3>Start Your Free Trial</h3><p>Sign up and tell us about your business &mdash; 60 seconds to create your account.</p></div>
      <div class="step"><div class="num">2</div><h3>Get Your Phone Number</h3><p>We instantly provision a dedicated number on our platform campaign. No A2P registration required.</p></div>
      <div class="step"><div class="num">3</div><h3>Train Your AI</h3><p>Add your services, pricing, and FAQs. The AI learns to communicate the way you do.</p></div>
      <div class="step"><div class="num">4</div><h3>Watch It Convert</h3><p>Import the leads you generate here and let the AI engage them. You get notified when calls are booked.</p></div>
    </div>
  </div>
</section>

<!-- INDUSTRIES -->
<section class="sec" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>Built for every industry</h2></div>
    <div class="chips">
      <span>🏠 Real Estate</span><span>☀️ Solar</span><span>💆 Med Spa</span><span>🦷 Dental</span><span>⚖️ Legal</span><span>🔧 Home Services</span><span>🛡️ Insurance</span><span>💰 Financial Services</span><span>🏗️ Roofing</span><span>🚗 Auto Dealerships</span><span>🏋️ Health &amp; Wellness</span><span>✨ And More…</span>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>Businesses that switched to All In One Ai Bot</h2><p>See why top-performing businesses trust All In One Ai Bot to grow their revenue.</p></div>
    <div class="tests">
      <div class="test">
        <div class="stars">★★★★★</div>
        <p class="q">&ldquo;All In One Ai Bot booked 23 consultation calls in my first month. I converted 8 of them into new patients. That&rsquo;s incredible ROI from a tool that works while I sleep.&rdquo;</p>
        <div class="metric">23 calls booked in Month 1</div>
        <div class="who"><div class="av">M</div><div><div class="nm">Mike R.</div><div class="rl">Dental Practice Owner, Texas</div></div></div>
      </div>
      <div class="test">
        <div class="stars">★★★★★</div>
        <p class="q">&ldquo;I used to spend 3 hours a day texting leads manually. Now it handles it all and the conversations are honestly better than what I was writing. My response rate went from 12% to 51%.&rdquo;</p>
        <div class="metric">51% response rate</div>
        <div class="who"><div class="av">S</div><div><div class="nm">Sarah T.</div><div class="rl">Real Estate Agent, Florida</div></div></div>
      </div>
      <div class="test">
        <div class="stars">★★★★★</div>
        <p class="q">&ldquo;The AI handles objections so naturally. Leads don&rsquo;t even realize they&rsquo;re talking to a bot until I hop on the call. It&rsquo;s like having a full-time assistant for the price of lunch.&rdquo;</p>
        <div class="metric">3.5&times; more booked calls</div>
        <div class="who"><div class="av">D</div><div><div class="nm">David K.</div><div class="rl">Solar Company Owner, California</div></div></div>
      </div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section class="sec" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>Start free</h2><p>14 days free, then $99/month. Cancel anytime.</p></div>
    <div class="price-block">
      <div class="plan">Starter Plan</div>
      <div class="amt">$0<span> / 14 days</span></div>
      <div class="then">Then $99/month &mdash; cancel before your trial ends and you won&rsquo;t be charged.</div>
      <ul class="plist">
        <li><i class="fas fa-check"></i> Dedicated platform phone number &mdash; instant</li>
        <li><i class="fas fa-check"></i> No A2P registration required</li>
        <li><i class="fas fa-check"></i> 150 outbound SMS per day</li>
        <li><i class="fas fa-check"></i> Unlimited AI conversations</li>
        <li><i class="fas fa-check"></i> Inbound &amp; outbound Voice AI calling</li>
        <li><i class="fas fa-check"></i> Smart lead CRM</li>
        <li><i class="fas fa-check"></i> Automated call booking</li>
        <li><i class="fas fa-check"></i> Knowledge base training</li>
        <li><i class="fas fa-check"></i> Multi-step follow-up sequences</li>
        <li><i class="fas fa-check"></i> Real-time analytics dashboard</li>
        <li><i class="fas fa-check"></i> TCPA compliance tools</li>
      </ul>
      <a class="btn btn-primary" style="width:100%;justify-content:center" href="<?php echo $TRIAL_URL; ?>" target="_blank" rel="noopener"><i class="fas fa-rocket"></i> Start Free Trial</a>
      <div class="note">Need more volume? Register your own A2P 10DLC brand anytime from your dashboard to unlock higher daily sending limits &mdash; our team walks you through it.</div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>Frequently asked questions</h2></div>
    <details class="faq"><summary>Do I need to register an A2P 10DLC brand?</summary><div class="body">No. On the Starter plan your messages go through our platform-managed A2P 10DLC campaign &mdash; we&rsquo;ve already done the registration work, so you just start texting. You&rsquo;re capped at 150 outbound SMS per day on the shared campaign. Need higher volume? Upgrade and register your own brand from inside your dashboard.</div></details>
    <details class="faq"><summary>Will my leads know they&rsquo;re talking to an AI?</summary><div class="body">No. The AI is trained to mirror your personal communication style. Messages sound natural, warm, and human, and leads consistently rate conversations as feeling like real 1-on-1 texting. You can customize the AI&rsquo;s personality and tone.</div></details>
    <details class="faq"><summary>Does it really integrate with my lead software?</summary><div class="body">Yes &mdash; that&rsquo;s the whole point. The leads you generate in your lead tool flow straight into the AI, which texts and calls each one automatically until they book. No CSV shuffling, no manual copying.</div></details>
    <details class="faq"><summary>Is this TCPA compliant?</summary><div class="body">It&rsquo;s built with compliance in mind. We track SMS consent, handle opt-outs automatically, maintain full audit logs, and never message leads who haven&rsquo;t given consent.</div></details>
    <details class="faq"><summary>How does the AI know about my business?</summary><div class="body">You train it. The Knowledge Base lets you add your services, pricing, common questions and answers, and company info. The AI uses this context to have informed, accurate conversations.</div></details>
    <details class="faq"><summary>Can I see and control conversations?</summary><div class="body">Absolutely. Every conversation is visible in your dashboard in real time. Jump into any conversation, send manual messages, override the AI, pause automation, or take over completely at any point.</div></details>
    <details class="faq"><summary>What happens after my 14-day trial?</summary><div class="body">You&rsquo;ll be billed $99/month automatically. Cancel anytime from your billing dashboard before the trial ends and you won&rsquo;t be charged. No commitments, no cancellation fees.</div></details>
  </div>
</section>

<!-- FINAL CTA -->
<section class="sec">
  <div class="wrap">
    <div class="final">
      <span class="eyebrow"><i class="fas fa-gift"></i> 14-Day Free Trial &middot; Exclusive To You</span>
      <h2>Start converting leads today</h2>
      <p>Connect your leads to the AI and let it call and text every one of them until they book. No A2P registration &middot; up and running in under 5 minutes.</p>
      <a class="btn" style="background:#fff;color:var(--navy);font-weight:800" href="<?php echo $TRIAL_URL; ?>" target="_blank" rel="noopener"><i class="fas fa-rocket"></i> Start My 14-Day Free Trial</a>
      <div class="fine">No credit card required to start &middot; Cancel anytime before the trial ends.</div>
    </div>
  </div>
</section>

<footer class="ft">&copy; 2026 All In One Ai Bot &middot; All In One Marketing, Inc.</footer>

</body>
</html>
