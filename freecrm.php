<?php
session_start();
require_once 'includes/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Get Free CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{ --ink:#141517; --muted:#5b6066; --faint:#7a8088; --line:rgba(20,21,23,.10); --bg:#f6f7f9; --card:#fff;
         --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4; --green:#16a34a; --green-d:#15803d; --green-soft:#e9f9ef; --navy:#14315c; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  .wrap{max-width:1040px;margin:0 auto;padding:0 24px}
  .sec{padding:44px 0}
  .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--green-d);background:var(--green-soft);padding:7px 14px;border-radius:999px}
  h1{font-size:clamp(2rem,4.4vw,3rem);font-weight:900;letter-spacing:-.025em;line-height:1.08;text-wrap:balance}
  h1 .hl{color:var(--green)}
  .lede{font-size:17px;color:var(--muted);max-width:62ch;margin:16px auto 0}
  .btn{display:inline-flex;align-items:center;gap:9px;font-weight:800;font-size:15px;text-decoration:none;padding:14px 26px;border-radius:12px;cursor:pointer;border:none;font-family:inherit}
  .btn-primary{background:var(--green);color:#fff;box-shadow:0 8px 24px rgba(22,163,74,.28)}
  .btn-primary:hover{background:var(--green-d)}
  .btn-ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .center{text-align:center}
  /* hero */
  .hero{background:linear-gradient(180deg,#fff 0%,var(--bg) 100%);border-bottom:1px solid var(--line);text-align:center;padding:46px 0 40px}
  .hero .cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:24px}
  /* stat row */
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:34px}
  .stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;text-align:center}
  .stat i{font-size:18px;margin-bottom:8px;display:block}
  .stat .n{font-size:22px;font-weight:900;letter-spacing:-.02em}
  .stat .l{font-size:12px;color:var(--faint);margin-top:2px}
  /* headings */
  .h2c{text-align:center;max-width:720px;margin:0 auto 30px}
  .h2c h2{font-size:clamp(1.6rem,3vw,2.1rem);font-weight:900;letter-spacing:-.02em;line-height:1.12}
  .h2c p{color:var(--muted);font-size:16px;margin-top:10px}
  /* replace chips */
  .chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;max-width:760px;margin:0 auto}
  .chips span{font-size:13px;font-weight:600;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 14px;text-decoration:line-through;text-decoration-color:rgba(200,87,25,.6)}
  .replaced{text-align:center;margin-top:18px;color:var(--green-d);font-size:13.5px;font-weight:700}
  /* feature grid */
  .feats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
  .feat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px}
  .feat .fi{width:38px;height:38px;border-radius:10px;background:var(--green-soft);color:var(--green-d);display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:12px}
  .feat h3{font-size:15px;font-weight:800;margin-bottom:6px}
  .feat p{font-size:13px;color:var(--muted);line-height:1.55}
  /* pricing / usage */
  .price-block{background:#fff;border:1px solid var(--line);border-radius:22px;padding:40px 32px}
  .save{display:inline-flex;align-items:center;gap:8px;background:var(--green-soft);color:var(--green-d);font-weight:800;font-size:14px;border-radius:999px;padding:9px 16px;margin:0 auto 6px}
  .rate-table{max-width:720px;margin:22px auto 0;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff}
  .rate-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 18px;border-bottom:1px solid var(--line);font-size:14.5px}
  .rate-row:last-child{border-bottom:none}
  .rate-row .k{font-weight:600;color:var(--ink)}
  .rate-row .v{font-weight:800;color:var(--green-d);white-space:nowrap}
  .usage-note{max-width:720px;margin:14px auto 0;text-align:center;font-size:12.5px;color:var(--faint);line-height:1.6}
  .badges{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:22px}
  .badges span{font-size:12.5px;font-weight:600;color:var(--muted);background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:7px 14px;display:inline-flex;align-items:center;gap:6px}
  .badges i{color:var(--green)}
  /* grow section */
  .grow{display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:center}
  .grow p{color:var(--muted);font-size:15px;margin-top:12px}
  .grow .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grow .g{background:#fff;border:1px solid var(--line);border-radius:12px;padding:13px 15px;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:9px}
  .grow .g i{color:var(--green)}
  /* final cta */
  .final{background:linear-gradient(135deg,#0f2647,#1e3a5f);color:#fff;border-radius:24px;padding:48px 32px;text-align:center}
  .final h2{font-size:clamp(1.6rem,3vw,2.2rem);font-weight:900;letter-spacing:-.02em}
  .final p{color:#c8d3e2;font-size:16px;margin:12px auto 22px;max-width:46ch}
  .final .fine{margin-top:14px;color:#9fb0c6;font-size:13px}
  @media(max-width:820px){ .stats{grid-template-columns:repeat(2,1fr)} .feats{grid-template-columns:1fr} .grow{grid-template-columns:1fr} .grow .grid2{grid-template-columns:1fr} }
</style>
</head>
<body>

<!-- HERO -->
<section class="hero">
  <div class="wrap">
    <span class="eyebrow"><i class="fas fa-gift"></i> The Complete Platform — Free</span>
    <h1 style="margin:16px 0 0">The Last Marketing &amp; CRM Tool<br><span class="hl">You&rsquo;ll Ever Need — Free.</span></h1>
    <p class="lede">We give you the entire all-in-one marketing &amp; CRM platform <strong>free</strong> &mdash; no monthly software fee. You only pay for the marketing usage you actually use (texts, calls, emails), billed as-you-go. Replace 20+ tools and run your whole business from one dashboard.</p>
    <div class="cta">
      <a class="btn btn-primary" href="https://free.allinonemarketing.com/getleadsnowfreeaccount" target="_blank" rel="noopener"><i class="fas fa-rocket"></i> Claim Your Free Account</a>
    </div>

    <div class="stats">
      <div class="stat"><i class="fas fa-circle-dollar-to-slot" style="color:var(--green)"></i><div class="n" style="color:var(--green)">$0/mo</div><div class="l">Software cost</div></div>
      <div class="stat"><i class="fas fa-layer-group" style="color:#7c3aed"></i><div class="n">22+</div><div class="l">Tools replaced</div></div>
      <div class="stat"><i class="fas fa-infinity" style="color:#2563eb"></i><div class="n">Unlimited</div><div class="l">Contacts &amp; users</div></div>
      <div class="stat"><i class="fas fa-hand-holding-dollar" style="color:var(--accent)"></i><div class="n">Pay-as-you-go</div><div class="l">Only for usage</div></div>
    </div>
  </div>
</section>

<!-- REPLACE SUBSCRIPTIONS -->
<section class="sec">
  <div class="wrap">
    <div class="h2c"><h2>Cancel All Your Other Subscriptions</h2><p>This replaces every one of these tools &mdash; and then some.</p></div>
    <div class="chips">
      <span>HubSpot</span><span>Mailchimp</span><span>Calendly</span><span>ClickFunnels</span><span>WordPress</span><span>Hootsuite</span><span>Kajabi</span><span>Typeform</span><span>CallRail</span><span>Stripe Billing</span><span>Teachable</span><span>Wix</span><span>ActiveCampaign</span><span>Buffer</span><span>Thinkific</span><span>Keap / Infusionsoft</span><span>Podium</span><span>Monday.com</span>
    </div>
    <div class="replaced"><i class="fas fa-circle-check"></i> All replaced &mdash; in one platform</div>
  </div>
</section>

<!-- FEATURE GRID -->
<section class="sec" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>Everything Included. Nothing Held Back.</h2><p>Every feature below is on. No upsells, no locked tiers, no surprises.</p></div>
    <div class="feats">
      <div class="feat"><div class="fi"><i class="fas fa-users"></i></div><h3>Full CRM &amp; Contact Management</h3><p>Organize every lead, client, and contact in one place. Tag, segment, filter, and manage your entire database with custom fields and smart lists.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-comments"></i></div><h3>Two-Way SMS &amp; Email Marketing</h3><p>Send and receive SMS and emails from a single inbox. Broadcast campaigns, drip sequences, and one-on-one conversations — all in one thread.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-diagram-project"></i></div><h3>Visual Workflow &amp; Automation Builder</h3><p>Build complex multi-step automations without touching code. Trigger actions based on form fills, appointments, tags, payments, and more.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-globe"></i></div><h3>Website &amp; Landing Page Builder</h3><p>Build beautiful websites and high-converting landing pages with a drag-and-drop editor. Hosting, SSL, custom domains, and templates included.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-filter"></i></div><h3>Sales Funnel Builder</h3><p>Create full multi-step funnels: opt-in pages, upsell flows, order bumps, and thank-you pages. Built-in A/B split testing included.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-calendar-check"></i></div><h3>Calendar &amp; Appointment Booking</h3><p>Embed booking calendars anywhere. Set availability, collect payments at booking, send automatic reminders, and sync with Google Calendar.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-chart-line"></i></div><h3>Pipeline &amp; Opportunity Management</h3><p>Visualize your entire sales pipeline with drag-and-drop deal cards. Track deal value, stage, close probability, and conversion rates.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-star"></i></div><h3>Reputation &amp; Review Management</h3><p>Automatically request Google &amp; Facebook reviews. Respond to reviews and track your average rating over time — all automated.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-share-nodes"></i></div><h3>Social Media Planner &amp; Scheduler</h3><p>Schedule and publish posts across Facebook, Instagram, LinkedIn, TikTok, and Google Business from a single content calendar.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-graduation-cap"></i></div><h3>Membership Sites &amp; Online Courses</h3><p>Deliver digital products, courses, and memberships behind a paywall. Drip-release content, track progress, and revoke access instantly.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-list-check"></i></div><h3>Forms, Surveys &amp; Quizzes</h3><p>Build unlimited forms and surveys that feed directly into your CRM. Embed anywhere, trigger automations on submission, view response analytics.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-comment-dots"></i></div><h3>Live Chat &amp; Website Chat Widget</h3><p>Add a live chat or AI-powered chat widget to any website. Route conversations to team members and capture leads automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-phone"></i></div><h3>Call Tracking &amp; Recording</h3><p>Get dedicated tracking numbers for every campaign. Record inbound and outbound calls and review transcriptions automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-file-invoice-dollar"></i></div><h3>Invoicing, Payments &amp; Orders</h3><p>Send invoices, sell products, and collect one-time or recurring payments via Stripe. Build order forms with upsells and track revenue inside your CRM.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-video"></i></div><h3>Video Hosting &amp; Course Delivery</h3><p>Host your own videos with built-in player controls, chapters, and engagement tracking — no third-party branding on your content.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-envelope-open-text"></i></div><h3>Email Marketing with Drag-and-Drop Builder</h3><p>Design stunning email campaigns from scratch or with templates. A/B test subject lines, track open/click rates, manage unsubscribes automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-chart-pie"></i></div><h3>Advanced Analytics &amp; Reporting</h3><p>Track revenue, appointment show rates, funnel conversion, ad attribution, email performance, and more — all in one dashboard.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-user-group"></i></div><h3>Affiliate Manager</h3><p>Run your own affiliate program. Set commission rates, generate unique referral links, track conversions, and pay affiliates automatically.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-people-group"></i></div><h3>Communities &amp; Group Builder</h3><p>Create private or public communities around your brand. Host discussions, post announcements, manage members, and monetize with paid access.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-mobile-screen"></i></div><h3>Mobile App — iOS &amp; Android</h3><p>Manage your business from anywhere. Respond to leads, check pipelines, book appointments, and get notified of every new contact — on your phone.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-shield-halved"></i></div><h3>Unlimited Users &amp; Sub-Accounts</h3><p>Add your entire team to your account. Set role-based permissions so everyone sees only what they need. No per-seat pricing — ever.</p></div>
      <div class="feat"><div class="fi"><i class="fas fa-bullseye"></i></div><h3>Ad Reporting &amp; Attribution</h3><p>Connect Facebook and Google Ads to see exactly which campaigns drive leads, appointments, and revenue — closed-loop attribution.</p></div>
    </div>
  </div>
</section>

<!-- PRICING / USAGE -->
<section class="sec">
  <div class="wrap">
    <div class="price-block center">
      <div class="save"><i class="fas fa-piggy-bank"></i> Normally $97/month &mdash; you save $1,164/year</div>
      <h2 style="font-size:clamp(1.6rem,3vw,2.1rem);font-weight:900;letter-spacing:-.02em;margin-top:6px">The Software Is Free. You Only Pay Usage.</h2>
      <p style="color:var(--muted);font-size:16px;max-width:640px;margin:10px auto 0">Most platforms charge <strong>$97/month</strong> just for the software. With us the platform is <strong>free</strong> &mdash; you only cover the marketing usage you actually use, billed as-you-go from a prepaid wallet. Here&rsquo;s what usage costs:</p>

      <div class="rate-table">
        <div class="rate-row"><span class="k"><i class="fas fa-comment-sms" style="color:var(--faint);margin-right:8px"></i>Text messages (SMS, send &amp; receive)</span><span class="v">$0.032 / segment</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-image" style="color:var(--faint);margin-right:8px"></i>Picture messages (MMS)</span><span class="v">$0.08 / message</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-phone-volume" style="color:var(--faint);margin-right:8px"></i>Outbound calls</span><span class="v">$0.084 / minute</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-phone" style="color:var(--faint);margin-right:8px"></i>Inbound calls</span><span class="v">$0.051 / minute</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-envelope" style="color:var(--faint);margin-right:8px"></i>Emails</span><span class="v">$2.70 / 1,000</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-hashtag" style="color:var(--faint);margin-right:8px"></i>Local phone number</span><span class="v">$4.60 / month</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-phone-flip" style="color:var(--faint);margin-right:8px"></i>Toll-free number</span><span class="v">$8.60 / month</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-robot" style="color:var(--faint);margin-right:8px"></i>Voice AI</span><span class="v">$0.52 / minute</span></div>
        <div class="rate-row"><span class="k"><i class="fas fa-wand-magic-sparkles" style="color:var(--faint);margin-right:8px"></i>Conversation AI</span><span class="v">$0.08 / message</span></div>
      </div>
      <div class="usage-note">Usage is billed as-you-go from a prepaid wallet &mdash; you only pay for what you send. Nothing to use = nothing to pay. Rates are subject to carrier changes.</div>

      <div class="badges">
        <span><i class="fas fa-circle-check"></i> No monthly software fee</span>
        <span><i class="fas fa-circle-check"></i> No setup fees</span>
        <span><i class="fas fa-circle-check"></i> No contracts</span>
        <span><i class="fas fa-circle-check"></i> No per-seat pricing</span>
      </div>
    </div>
  </div>
</section>

<!-- WE GROW WHEN YOU GROW -->
<section class="sec" style="background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap grow">
    <div>
      <span class="eyebrow" style="color:var(--accent-d);background:var(--accent-soft)"><i class="fas fa-seedling"></i> Why free?</span>
      <h2 style="font-size:clamp(1.5rem,2.8vw,2rem);font-weight:900;letter-spacing:-.02em;margin-top:14px">We Grow When You Grow</h2>
      <p>We give you the whole platform free because you deserve the best tools to grow &mdash; not a watered-down trial. You only pay for the usage you send, so our incentives line up with yours: when you succeed, we succeed.</p>
      <p>It&rsquo;s a full account with every feature turned on &mdash; yours to keep as long as you&rsquo;re a client.</p>
    </div>
    <div class="grid2">
      <div class="g"><i class="fas fa-circle-check"></i> Full account — not a trial</div>
      <div class="g"><i class="fas fa-circle-check"></i> Unlimited contacts</div>
      <div class="g"><i class="fas fa-circle-check"></i> Unlimited team members</div>
      <div class="g"><i class="fas fa-circle-check"></i> Every feature unlocked</div>
      <div class="g"><i class="fas fa-circle-check"></i> No monthly software fee</div>
      <div class="g"><i class="fas fa-circle-check"></i> Yours as long as you're a client</div>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="sec">
  <div class="wrap">
    <div class="final">
      <h2>Ready to Claim Your Free Account?</h2>
      <p>Get the entire platform free &mdash; you only pay for the usage you use. Takes less than 2 minutes to set up.</p>
      <a class="btn" style="background:#fff;color:var(--navy)" href="https://free.allinonemarketing.com/getleadsnowfreeaccount" target="_blank" rel="noopener"><i class="fas fa-rocket"></i> Claim Your Free Account</a>
      <div class="fine">Normally $97/month &mdash; free for you.</div>
    </div>
  </div>
</section>

</body>
</html>
