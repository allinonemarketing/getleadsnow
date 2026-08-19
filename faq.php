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
<title>FAQs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{ --ink:#141517; --muted:#5b6066; --faint:#7a8088; --line:rgba(20,21,23,.10); --bg:#f6f7f9; --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4; }
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.55}
  .wrap{max-width:760px;margin:0 auto;padding:40px 24px 60px}
  .head{text-align:center;margin-bottom:28px}
  .head .icon{width:60px;height:60px;border-radius:15px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 14px}
  h1{font-size:28px;font-weight:900;letter-spacing:-.02em}
  .head p{color:var(--muted);font-size:16px;margin-top:8px}
  .faq{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:12px;overflow:hidden}
  .faq summary{list-style:none;cursor:pointer;padding:18px 20px;font-weight:700;font-size:15.5px;color:var(--ink);display:flex;align-items:center;justify-content:space-between;gap:14px}
  .faq summary::-webkit-details-marker{display:none}
  .faq summary::after{content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;font-size:13px;color:var(--accent);transition:transform .2s;flex:none}
  .faq[open] summary::after{transform:rotate(180deg)}
  .faq .body{padding:0 20px 18px;font-size:14.5px;line-height:1.65;color:var(--muted)}
  .faq .body b{color:var(--ink)}
  .foot{text-align:center;margin-top:26px;color:var(--faint);font-size:14px}
  .foot a{color:var(--accent-d);font-weight:700;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="icon"><i class="fas fa-circle-question"></i></div>
    <h1>Frequently Asked Questions</h1>
    <p>Everything you need to know about pulling and working your leads.</p>
  </div>

  <details class="faq" open>
    <summary>I got 100 leads but only some have emails &mdash; why?</summary>
    <div class="body">Not every business has an email available. We pull <b>every matching business</b> from Google Maps, so every lead comes with a name, phone, website, rating and address. Our free enrichment then scans each lead&rsquo;s website for emails and social profiles &mdash; but businesses that don&rsquo;t have a website, or don&rsquo;t list an email publicly, simply won&rsquo;t return one. You&rsquo;ll still have their phone and socials to reach out.</div>
  </details>

  <details class="faq">
    <summary>How do I find emails for a lead that doesn&rsquo;t have one yet?</summary>
    <div class="body">Click <b>enrich</b> on the lead (or <b>Re-Enrich All</b> for the whole list). We scan the business&rsquo;s website for emails and social media. If the business has no website there&rsquo;s usually nothing to find &mdash; reach them by phone instead. Enrichment is always <b>free</b> and never costs credits.</div>
  </details>

  <details class="faq">
    <summary>How much does a search cost?</summary>
    <div class="body"><b>1 credit per lead returned.</b> Enriching leads with emails &amp; socials is always free, and so is exporting. If a search returns no leads, you aren&rsquo;t charged.</div>
  </details>

  <details class="faq">
    <summary>What information comes with each lead?</summary>
    <div class="body">Business name, phone number, website, rating, review count, address and category &mdash; plus emails and social profiles after free enrichment.</div>
  </details>

  <details class="faq">
    <summary>Where does the lead data come from?</summary>
    <div class="body">Live from Google Maps&rsquo; public business listings &mdash; pulled fresh the moment you search, never a recycled or resold list. You can verify any lead against Google yourself.</div>
  </details>

  <details class="faq">
    <summary>How many leads can I pull at once?</summary>
    <div class="body">Up to <b>500 results per city</b>. Select multiple cities, or whole states, to pull thousands of leads in a single search. Your &ldquo;Results per City&rdquo; option and available credits set the cap.</div>
  </details>

  <details class="faq">
    <summary>How far does a city search reach?</summary>
    <div class="body">There&rsquo;s no fixed radius &mdash; we search Google Maps for your keyword in each city you pick (e.g. &ldquo;dentists in Austin, TX&rdquo;). Google decides the area for that place and returns the matching businesses, up to your per-city limit. Bigger, well-known cities return denser results.</div>
  </details>

  <details class="faq">
    <summary>Which countries can I search?</summary>
    <div class="body">United States, United Kingdom and Europe. Pick the country at the top of the Add Leads window, then choose your states/regions and cities.</div>
  </details>

  <details class="faq">
    <summary>Can I export my leads?</summary>
    <div class="body">Yes. Use the <b>Export</b> menu to download a CSV, or push your leads straight into <b>GoHighLevel</b>. You can also import your own leads from a CSV.</div>
  </details>

  <details class="faq">
    <summary>Can I track my outreach?</summary>
    <div class="body">Yes &mdash; every list has a built-in CRM. Move leads through pipeline stages (New, Contacted, Engaged, Client, No Response), mark emailed/DM&rsquo;d/visited, add notes, save email templates, and see everything on a map.</div>
  </details>

  <details class="faq">
    <summary>Do my credits expire?</summary>
    <div class="body">Your free starter credits are a one-time batch. On a paid plan, unused credits roll over each month, so nothing is wasted.</div>
  </details>

  <details class="faq">
    <summary>Can I get leads even cheaper?</summary>
    <div class="body">Yes &mdash; check the <b>Get Leads For Less Than 1&cent;</b> tab. Own the software, pull leads at cost, and even resell it to your own clients.</div>
  </details>

  <div class="foot">Still stuck? Email <a href="mailto:sales@allinonemarketing.com?subject=Support">sales@allinonemarketing.com</a> and we&rsquo;ll help.</div>
</div>
</body>
</html>
