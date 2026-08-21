<?php
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
    <title>Get 100 Business Leads + Free Marketing Software — <?php echo htmlspecialchars($appName); ?></title>
    <meta name="description" content="Search any industry + city and instantly pull business names, phone numbers, emails and socials live from Google Maps. 100 leads + a full marketing software suite included when you create your account — no card.">
    <link rel="icon" type="image/jpeg" href="<?php echo htmlspecialchars($appLogo); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Fonts loaded async so they never block first paint on mobile ad traffic. Icons are inline SVG (no icon-font download). -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Lilita+One&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Lilita+One&display=swap"></noscript>

    <!-- Meta Pixel -->
    <script>
      !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
      n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,
      'script','https://connect.facebook.net/en_US/fbevents.js');
      fbq('init','1131224344235309');fbq('track','PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1131224344235309&ev=PageView&noscript=1"/></noscript>

    <style>
      :root{
        --ink:#141517; --muted:#5b6066; --line:rgba(20,21,23,.09);
        --bg:#ffffff; --panel:#f6f7f9; --accent:#c85719; --accent-d:#a8460f; --accent-soft:#fdeee4;
        --gold:#9a7400; --green:#17813f; --red:#c0392b; --faint:#63696f;
        --shadow:0 1px 2px rgba(16,20,30,.04),0 12px 40px rgba(16,20,30,.08);
        --shadow-lg:0 30px 80px rgba(16,20,30,.16);
      }
      *{margin:0;padding:0;box-sizing:border-box}
      html{scroll-behavior:smooth}
      body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.5}
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1160px;margin:0 auto;padding:0 22px}
      .ic{width:1em;height:1em;display:inline-block;vertical-align:-.125em;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
      .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;background:var(--accent);color:#fff;font-weight:800;font-size:17px;
        padding:16px 26px;border:none;border-radius:13px;cursor:pointer;font-family:inherit;box-shadow:0 8px 24px rgba(200,87,25,.32);
        transition:transform .12s ease, box-shadow .12s ease, background .12s ease}
      .btn:hover{background:var(--accent-d);transform:translateY(-1px);box-shadow:0 12px 30px rgba(200,87,25,.4)}
      .btn:active{transform:translateY(0)}
      .btn:disabled{opacity:.6;cursor:default;transform:none;box-shadow:none}
      :focus-visible{outline:3px solid rgba(200,87,25,.55);outline-offset:2px;border-radius:6px}
      .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
        color:var(--accent);background:var(--accent-soft);padding:7px 13px;border-radius:999px}
      .eyebrow .ic{font-size:14px}
      .copy .eyebrow{margin-bottom:18px}

      /* top bar */
      .topbar{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.86);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
      .topbar .inner{display:flex;align-items:center;justify-content:center;height:64px}
      .topbar img{max-height:36px;width:auto;height:36px}

      /* hero */
      .hero{position:relative;overflow:hidden;background:
        radial-gradient(1100px 500px at 82% -8%, #fff2e9 0%, rgba(255,242,233,0) 60%),
        radial-gradient(900px 500px at -5% 0%, #eef4ff 0%, rgba(238,244,255,0) 55%),
        var(--bg)}
      .hero .grid{display:grid;grid-template-columns:1.08fr .92fr;gap:56px;align-items:start;padding-top:56px;padding-bottom:68px}
      h1{font-size:clamp(1.9rem,3.7vw,2.95rem);line-height:1.06;letter-spacing:-.03em;font-weight:900;text-wrap:balance}
      h1 .hl{color:var(--accent)}
      .lede{font-size:clamp(1.05rem,1.5vw,1.22rem);color:var(--muted);margin-top:18px;max-width:40ch}
      .lede strong{color:var(--ink)}
      .checks{margin-top:22px;display:grid;gap:11px}
      .checks li{list-style:none;display:flex;align-items:flex-start;gap:11px;font-size:15.5px;font-weight:500}
      .checks .ic{color:var(--green);margin-top:3px;font-size:16px}

      /* product preview mock (honest UI representation) */
      .preview{border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:var(--shadow);background:#fff}
      .hero .copy .preview{margin-top:28px}
      .preview .bar{display:flex;align-items:center;gap:7px;padding:11px 14px;background:#f1f2f4;border-bottom:1px solid var(--line)}
      .preview .dot{width:10px;height:10px;border-radius:50%;background:#d5d8dc}
      .preview .bar .q{margin-left:8px;font-size:12.5px;color:var(--muted);font-weight:600;background:#fff;border:1px solid var(--line);border-radius:7px;padding:5px 10px}
      .preview .q b{color:var(--ink)}
      .lead-row{display:grid;grid-template-columns:1.5fr 1fr .7fr;gap:10px;align-items:center;padding:12px 15px;border-bottom:1px solid var(--line);font-size:13px}
      .lead-row:last-of-type{border-bottom:none}
      .lead-row .biz{font-weight:700}
      .lead-row .sub{color:var(--faint);font-size:11.5px;font-weight:500}
      .lead-row .ph{font-variant-numeric:tabular-nums;color:var(--ink);font-weight:600}
      .lead-row .em{color:#17813f;font-size:11.5px;font-weight:600;margin-top:2px}
      .lead-row .rt{color:var(--gold);font-weight:800;text-align:right;font-size:12.5px}
      .preview .foot{padding:9px 15px;font-size:11.5px;color:var(--faint);background:#fafbfc;text-align:center;font-weight:600}
      .preview .samplenote{padding:7px 15px;font-size:11.5px;color:var(--faint);background:#fff;text-align:center;border-top:1px dashed var(--line)}
      .prov{display:flex;align-items:center;gap:8px;margin-top:16px;color:var(--muted);font-size:12.5px;font-weight:600}
      .prov .ic{color:var(--green);font-size:15px}

      /* mobile-only compact ad-scent hook, shown ABOVE the form on phones */
      .mobhook{display:none}
      .mobhook .eyebrow{margin-bottom:10px}
      .mh-title{font-size:1.6rem;line-height:1.12;letter-spacing:-.02em;font-weight:900;text-wrap:balance}
      .mh-title .hl{color:var(--accent)}
      .mh-proof{margin-top:13px;border:1px solid var(--line);border-radius:11px;background:#fff;box-shadow:var(--shadow);padding:9px 12px;font-size:12px;font-weight:600;color:var(--muted);line-height:1.55}
      .mh-proof .mh-q{display:block;color:var(--faint);font-size:11px;margin-bottom:2px}
      .mh-proof b{color:var(--ink)}
      .mh-proof .mh-ok{color:#17813f}
      .mh-proof .mh-rt{color:var(--gold);font-weight:800}
      .mh-proof .mh-more{color:var(--faint)}
      .mh-note{margin-top:9px;font-size:12px;font-weight:700;color:#17813f;text-align:center}

      /* form card */
      .card{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow-lg);padding:26px 24px;position:sticky;top:82px}
      .card .kicker{display:flex;align-items:center;gap:9px;font-weight:800;font-size:14px;color:var(--accent)}
      .card h2{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:8px 0 3px}
      .card .sub{color:var(--muted);font-size:14px;margin-bottom:8px}
      .anchor{display:flex;align-items:flex-start;line-height:1.45;gap:9px;font-size:13px;font-weight:600;color:#14713a;background:#eefaf1;border:1px solid #cdeed7;border-radius:9px;padding:10px 13px;margin-bottom:16px}
      .anchor .ic{margin-top:2px;font-size:15px}
      .anchor span{flex:1;min-width:0}
      .anchor .big{font-weight:900;color:var(--accent-d)}
      .anchor s{color:var(--faint);font-weight:600}
      .cardprov{display:flex;align-items:flex-start;gap:8px;font-size:12px;font-weight:600;color:var(--muted);margin:2px 0 10px;line-height:1.45}
      .cardprov .ic{color:var(--green);font-size:14px;margin-top:2px;flex:none}
      .btn .spin{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
      .btn.loading{pointer-events:none;opacity:.9}
      .btn.loading .spin{display:inline-block}
      @keyframes spin{to{transform:rotate(360deg)}}
      .field{margin-bottom:12px;position:relative}
      .field label{display:block;font-size:12.5px;font-weight:700;color:var(--ink);margin-bottom:6px}
      .field .hint{font-weight:500;color:var(--faint)}
      .field input{width:100%;padding:13px 14px;border:1.5px solid #e4e6ea;border-radius:11px;font-size:16px;font-family:inherit;background:#fbfbfc;outline:none;transition:border-color .12s, box-shadow .12s}
      .field input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(200,87,25,.12);background:#fff}
      .field input.bad{border-color:var(--red);background:#fef7f7}
      .field .fe{display:none;color:var(--red);font-size:11.5px;font-weight:600;margin-top:5px}
      .field.err-on .fe{display:block}
      .pwrap{position:relative}
      .pwrap input{padding-right:62px}
      .pwtoggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:transparent;color:var(--faint);font-size:12px;font-weight:800;cursor:pointer;padding:11px 10px;min-height:44px}
      .qbox{border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:2px 0 13px}
      .qbox .qh{color:var(--faint);font-weight:700;font-size:11.5px;margin-bottom:3px;display:block}
      .qbox p{font-size:15px;font-weight:600;line-height:1.45;margin-bottom:10px;color:var(--muted)}
      .qbox .qsub{display:block;font-weight:500;color:var(--faint);font-size:12.5px;margin-top:4px}
      .qopts{display:flex;gap:10px}
      .qopts label{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;border:1.5px solid #e0e2e6;border-radius:10px;padding:10px;font-weight:700;font-size:13px;cursor:pointer;background:#fff;transition:.12s;text-align:center}
      .qopts input{accent-color:var(--accent);width:15px;height:15px;flex:none}
      .qopts input:checked ~ span{color:var(--accent-d)}
      .qopts label:has(input:checked){border-color:var(--accent);background:var(--accent-soft);color:var(--accent-d)}
      .form-btn{width:100%;font-size:17px;margin-top:2px}
      /* multi-step form */
      .fprogress{height:5px;background:#edeef0;border-radius:999px;overflow:hidden;margin-bottom:8px}
      .fbar{display:block;height:100%;width:25%;background:var(--accent);border-radius:999px;transition:width .25s ease}
      .fstepnum{font-size:11px;font-weight:700;color:var(--faint);letter-spacing:.04em;text-transform:uppercase;margin-bottom:14px}
      .fstep[hidden]{display:none}
      .fnav{display:flex;gap:10px;align-items:stretch;margin-top:2px}
      .fnav .btn{flex:1;margin-top:0}
      .btn-ghost{display:inline-flex;align-items:center;justify-content:center;background:transparent;color:var(--muted);font-weight:700;font-size:15px;border:1.5px solid #e0e2e6;border-radius:13px;padding:14px 18px;cursor:pointer;font-family:inherit;transition:background .12s}
      .btn-ghost:hover{background:var(--panel)}
      .whyfree{font-size:11.5px;color:var(--faint);text-align:center;margin:10px 0 2px;line-height:1.5}
      .microtrust{text-align:center;font-size:12px;color:var(--faint);margin-top:11px;line-height:1.7}
      .microtrust .ic{margin-right:5px;vertical-align:-.15em}
      .tcpa{font-size:6px;color:var(--faint);line-height:1.4;margin-top:12px;text-align:left;opacity:.85}
      .tcpa a{color:var(--muted);text-decoration:underline;font-weight:700}
      .err{display:none;background:#fdeaea;color:var(--red);font-size:13px;font-weight:600;padding:10px 12px;border-radius:10px;margin-bottom:12px}
      .err a{color:var(--accent-d);text-decoration:underline;font-weight:800}

      /* capability strip (honest capabilities, not unverifiable social proof) */
      .metrics{border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:var(--panel)}
      .metrics .row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;padding-top:24px;padding-bottom:24px;text-align:center}
      .metrics .n{font-size:clamp(1.5rem,2.7vw,2.1rem);font-weight:900;letter-spacing:-.02em;display:flex;align-items:center;justify-content:center;gap:9px}
      .metrics .n .ic{color:var(--accent);font-size:.8em}
      .metrics .l{font-size:13px;color:var(--muted);font-weight:600;margin-top:3px}
      .worksrow{text-align:center;padding:16px 0 20px;color:var(--faint);font-size:12.5px;font-weight:600}

      section.blk{padding:70px 0}
      .h2c{text-align:center;max-width:700px;margin:0 auto 44px}
      .h2c h2{font-size:clamp(1.8rem,3.3vw,2.5rem);font-weight:900;letter-spacing:-.025em;line-height:1.1;text-wrap:balance}
      .h2c p{color:var(--muted);font-size:17px;margin-top:14px}

      .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
      .step{background:#fff;border:1px solid var(--line);border-radius:18px;padding:28px 24px;box-shadow:var(--shadow)}
      .step .num{width:38px;height:38px;border-radius:11px;background:var(--accent-soft);color:var(--accent);font-weight:900;display:flex;align-items:center;justify-content:center;font-size:16px}
      .step h3{font-size:19px;font-weight:800;margin:16px 0 8px;letter-spacing:-.01em}
      .step p{color:var(--muted);font-size:14.5px}

      .feats{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
      .feat{display:flex;gap:14px;padding:22px;border:1px solid var(--line);border-radius:16px;background:#fff}
      .feat .ic{color:var(--accent);font-size:20px;margin-top:2px}
      .feat h4{font-size:16px;font-weight:800;margin-bottom:4px}
      .feat p{color:var(--muted);font-size:13.5px}

      /* included-free marketing suite + CRM */
      .incl-save{display:inline-flex;align-items:center;gap:9px;background:#e7f6ed;color:#17813f;font-weight:800;font-size:15px;border-radius:999px;padding:10px 18px;margin-bottom:18px}
      .incl-save s{color:var(--faint);font-weight:700;text-decoration-color:var(--accent)}
      .incl-chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;max-width:760px;margin:0 auto 26px}
      .incl-chips span{font-size:12.5px;font-weight:600;color:var(--muted);background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 13px;text-decoration:line-through;text-decoration-color:rgba(200,87,25,.55)}
      .incl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px 26px;max-width:900px;margin:0 auto;text-align:left}
      .incl-grid .it{display:flex;gap:10px;align-items:flex-start;font-size:14px;font-weight:600;color:var(--ink)}
      .incl-grid .it .ic{color:var(--green);font-size:16px;margin-top:1px;flex:none}

      /* customer reviews (real, attributed) */
      .reviews{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
      .review{border:1px solid var(--line);border-radius:16px;background:#fff;padding:22px;box-shadow:var(--shadow);display:flex;flex-direction:column}
      .review .rstars{color:var(--gold);letter-spacing:1px;font-size:14px;font-weight:800}
      .review .rtext{color:var(--ink);font-size:14px;line-height:1.55;margin:10px 0 16px;flex:1}
      .review .rwho{display:flex;align-items:center;gap:11px}
      .review .ravatar{width:36px;height:36px;border-radius:50%;background:var(--accent-soft);color:var(--accent-d);font-weight:800;display:flex;align-items:center;justify-content:center;font-size:15px;flex:none}
      .review .rname{font-weight:800;font-size:13.5px}
      .review .rmeta{font-size:11.5px;color:var(--faint);font-weight:600;margin-top:1px}

      /* compare / anchor block */
      .compare{display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:820px;margin:0 auto}
      .comp{border:1px solid var(--line);border-radius:16px;padding:24px;background:#fff}
      .comp.old{opacity:.92}
      .comp h4{font-size:15px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px}
      .comp.old h4{color:var(--muted)}
      .comp.new{border-color:#cdeed7;background:#f7fdf9}
      .comp.new h4{color:#17813f}
      .comp li{list-style:none;display:flex;gap:9px;align-items:flex-start;font-size:14px;margin-bottom:9px;color:var(--muted)}
      .comp li .ic{margin-top:3px;font-size:14px}
      .comp.old li .ic{color:var(--red)}
      .comp.new li .ic{color:var(--green)}
      .comp.new li{color:var(--ink);font-weight:500}

      .cta{background:linear-gradient(135deg,#1a1c1f,#25292e);color:#fff;border-radius:26px;padding:56px 40px;text-align:center;position:relative;overflow:hidden}
      .cta::after{content:"";position:absolute;inset:0;background:radial-gradient(600px 240px at 50% -10%,rgba(200,87,25,.4),transparent 70%);pointer-events:none}
      .cta h2{font-size:clamp(1.9rem,3.4vw,2.6rem);font-weight:900;letter-spacing:-.025em;position:relative}
      .cta p{color:#c8ccd2;font-size:17px;margin:14px auto 26px;max-width:46ch;position:relative}
      .cta .btn{position:relative;font-size:18px;padding:18px 34px}
      .cta .fine{margin-top:16px;color:#9aa0a8;font-size:13px;position:relative}

      footer.wrap{border-top:1px solid var(--line);padding-top:40px;padding-bottom:40px;color:var(--faint);font-size:13px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}

      /* sticky mobile CTA bar */
      .mobbar{display:none}

      @media(max-width:900px){
        .topbar{position:static}
        .hero .grid{grid-template-columns:1fr;gap:26px;padding-top:24px;padding-bottom:40px}
        .mobhook{display:block;order:-3;margin-bottom:-8px;text-align:center}
        .mobhook .mh-proof{text-align:left}
        .card{order:-2;position:static}
        .lede{max-width:none}
        .copy{text-align:center}
        .copy .checks{text-align:left}
        .copy .checks li{justify-content:flex-start}
        .copy .prov{justify-content:flex-start;text-align:left}
        .mobhook .eyebrow{display:none}
        .mh-note{display:none}
        .copy .eyebrow{display:none}
        .copy h1{display:none}               /* headline already shown in .mobhook above the form */
        .copy .lede{display:none}            /* prose reads awkward on mobile — bullets below carry it */
        .copy .checks{margin-top:4px}
        .hero .copy .preview{display:none}   /* desktop preview hidden; compact mh-proof shows instead */
        .steps,.feats,.compare,.reviews{grid-template-columns:1fr}
        .incl-grid{grid-template-columns:1fr 1fr;gap:11px 16px;font-size:13.5px}
        .metrics .row{grid-template-columns:1fr;gap:16px;padding-top:20px;padding-bottom:20px}
        section.blk{padding:52px 0}
        .cta{padding:42px 22px}
        body{padding-bottom:calc(76px + env(safe-area-inset-bottom))}
        .mobbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:50;padding:11px 16px calc(11px + env(safe-area-inset-bottom));
          background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-top:1px solid var(--line);box-shadow:0 -6px 24px rgba(16,20,30,.1);transition:transform .2s ease}
        .mobbar .btn{width:100%;font-size:16px;padding:15px}
      }
      @media (prefers-reduced-motion: reduce){
        html{scroll-behavior:auto}
        *{transition:none !important;animation:none !important}
        .btn.loading .spin{display:none}
      }
    </style>
    <style>
      /* Cartoon ad-scent theme — this page must look like the FB creative that sent
         the click (sunburst rays, chunky outlined type, mascot). Body sections below
         the hero stay in the clean base style for readability. */
      .hero{background:
        linear-gradient(180deg, rgba(255,255,255,.84), rgba(255,255,255,.66) 55%, rgba(255,255,255,.9)),
        repeating-conic-gradient(from 8deg at 70% 22%, #ff5fa8 0 11deg, #ffb703 11deg 22deg, #29c7e8 22deg 33deg, #8ee000 33deg 44deg, #ff8f2d 44deg 55deg, #b45cff 55deg 66deg)}
      h1, .mh-title{font-family:'Lilita One','Inter',system-ui,sans-serif;font-weight:400;text-transform:uppercase;letter-spacing:.01em;color:#ffc400;
        text-shadow:-2px -2px 0 #22224a, 2px -2px 0 #22224a, -2px 2px 0 #22224a, 2px 2px 0 #22224a, 0 5px 0 #22224a, 0 9px 18px rgba(34,34,74,.35)}
      h1{font-size:clamp(2.1rem,4.2vw,3.35rem);line-height:1.05}
      .mh-title{font-size:1.75rem;line-height:1.1}
      h1 .hl, .mh-title .hl{color:#fff}
      .copy .eyebrow{background:#22224a;color:#ffd34d}
      .lede{color:#3c3f47}
      /* mascot popping out of the signup card (desktop) */
      .card .adgirl{display:flex;justify-content:center;margin:-64px 0 4px}
      .card .adgirl img{width:118px;height:118px;border-radius:50%;object-fit:cover;object-position:50% 12%;border:4px solid #ffc400;
        box-shadow:0 10px 26px rgba(255,95,168,.4);background:#ffe9f4}
      /* mascot above the mobile hook headline */
      .mh-girl{display:block;margin:0 auto 10px;width:96px;height:96px;border-radius:50%;object-fit:cover;object-position:50% 12%;
        border:4px solid #ffc400;box-shadow:0 8px 20px rgba(34,34,74,.25);background:#ffe9f4}
      /* hot CTA gradient to match the creative */
      .card .btn,.mobbar .btn{background:linear-gradient(135deg,#ff2f92,#ff8f2d);box-shadow:0 8px 24px rgba(255,47,146,.35)}
      .card .btn:hover,.mobbar .btn:hover{background:linear-gradient(135deg,#e91e7e,#f27f1b);box-shadow:0 12px 30px rgba(255,47,146,.45)}
      /* mascot above the how-it-works heading */
      .secgirl{display:block;margin:0 auto 18px;width:150px;filter:drop-shadow(0 10px 20px rgba(34,34,74,.18))}
      /* final CTA re-skinned as the ad creative: sunburst + outlined type + mascot */
      .cartooncta{background:
        radial-gradient(closest-side at 46% 42%, rgba(255,255,255,.6), rgba(255,255,255,0)),
        repeating-conic-gradient(from 0deg at 50% 28%, #ff5fa8 0 12deg, #ffb703 12deg 24deg, #29c7e8 24deg 36deg, #8ee000 36deg 48deg, #ff8f2d 48deg 60deg, #b45cff 60deg 72deg);
        padding-right:250px}
      .cartooncta h2{font-family:'Lilita One','Inter',system-ui,sans-serif;font-weight:400;text-transform:uppercase;letter-spacing:.01em;color:#fff;
        text-shadow:-2px -2px 0 #22224a, 2px -2px 0 #22224a, -2px 2px 0 #22224a, 2px 2px 0 #22224a, 0 4px 0 #22224a, 0 8px 16px rgba(34,34,74,.35)}
      .cartooncta p{color:#22224a;font-weight:700}
      .cartooncta .fine{color:#22224a;font-weight:700}
      .cartooncta .btn{background:linear-gradient(135deg,#ff2f92,#ff8f2d);box-shadow:0 8px 24px rgba(255,47,146,.4)}
      .cartooncta .btn:hover{background:linear-gradient(135deg,#e91e7e,#f27f1b)}
      .ctagirl{position:absolute;right:28px;bottom:0;width:200px;z-index:0;filter:drop-shadow(0 8px 18px rgba(34,34,74,.3))}
      .cartooncta::after{display:none}

      /* ===== colorful body sections (carry the ad energy all the way down) ===== */
      .h2c h2{font-family:'Lilita One','Inter',system-ui,sans-serif;font-weight:400;text-transform:uppercase;letter-spacing:.015em;color:#ffc400;
        text-shadow:-2px -2px 0 #22224a, 2px -2px 0 #22224a, -2px 2px 0 #22224a, 2px 2px 0 #22224a, 0 4px 0 #22224a, 0 7px 14px rgba(34,34,74,.25)}
      .h2c h2 .hl{color:#fff}
      .h2c p{color:#3c3f47;font-weight:600}

      /* metrics strip: navy band between the sunburst hero and the page */
      .metrics{background:#22224a !important;border-color:#22224a !important}
      .metrics .n{color:#fff}
      .metrics .row > div:nth-child(1) .n .ic{color:#ff5fa8}
      .metrics .row > div:nth-child(2) .n .ic{color:#29c7e8}
      .metrics .row > div:nth-child(3) .n .ic{color:#ffb703}
      .metrics .l{color:#b9bfe0}
      .metrics .worksrow{color:#8f96c2;border-color:rgba(255,255,255,.12)}

      /* confetti sprinkles on the light sections */
      section.blk:has(.steps){background:
        radial-gradient(circle 6px at 6% 22%, rgba(255,95,168,.4) 97%, transparent),
        radial-gradient(circle 5px at 94% 15%, rgba(41,199,232,.4) 97%, transparent),
        radial-gradient(circle 7px at 91% 82%, rgba(255,183,3,.45) 97%, transparent),
        radial-gradient(circle 5px at 7% 80%, rgba(142,224,0,.4) 97%, transparent),
        radial-gradient(circle 4px at 76% 6%, rgba(180,92,255,.35) 97%, transparent),
        radial-gradient(circle 4px at 22% 8%, rgba(255,143,45,.4) 97%, transparent), #fff}
      section.blk:has(.feats){background:
        radial-gradient(circle 5px at 5% 25%, rgba(255,95,168,.35) 97%, transparent),
        radial-gradient(circle 6px at 95% 20%, rgba(255,183,3,.4) 97%, transparent),
        radial-gradient(circle 5px at 92% 85%, rgba(142,224,0,.35) 97%, transparent),
        radial-gradient(circle 4px at 8% 85%, rgba(41,199,232,.4) 97%, transparent), #f4fcff !important}

      /* 3 steps: tilted cards, fat colored borders, cartoon number badges */
      .step{border:3px solid;border-radius:22px;box-shadow:0 10px 0 rgba(34,34,74,.07)}
      .step:nth-child(1){border-color:#ff5fa8;transform:rotate(-1.2deg)}
      .step:nth-child(2){border-color:#29c7e8;transform:rotate(1deg)}
      .step:nth-child(3){border-color:#8ee000;transform:rotate(-.8deg)}
      .step .num{width:46px;height:46px;border-radius:50%;color:#fff;font-size:21px;font-family:'Lilita One','Inter',sans-serif;box-shadow:0 4px 0 rgba(34,34,74,.2)}
      .step:nth-child(1) .num{background:#ff5fa8}
      .step:nth-child(2) .num{background:#29c7e8}
      .step:nth-child(3) .num{background:#8ee000}
      .step:nth-child(1) h3{color:#e0338a}
      .step:nth-child(2) h3{color:#0e9cbd}
      .step:nth-child(3) h3{color:#5ea300}

      /* comparison: red vs green with a VS badge */
      section.blk:has(.compare){background:#fff8e6 !important}
      .comp{border-width:3px;border-radius:20px}
      .comp.old{border-color:#ff8a8a;background:#fff3f3;transform:rotate(-1deg);opacity:1}
      .comp.new{border-color:#22c55e;background:#f0fdf4;transform:rotate(1deg);box-shadow:0 14px 34px rgba(34,197,94,.2)}
      .compare{position:relative}
      .compare::after{content:"VS";position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) rotate(-8deg);
        font-family:'Lilita One','Inter',sans-serif;font-size:30px;color:#ffc400;z-index:2;
        text-shadow:-2px -2px 0 #22224a, 2px -2px 0 #22224a, -2px 2px 0 #22224a, 2px 2px 0 #22224a, 0 4px 0 #22224a}

      /* features: colorful icon chips + matching borders */
      .feat{border-radius:18px;border-width:2px;box-shadow:0 6px 0 rgba(34,34,74,.05)}
      .feat > .ic{width:42px;height:42px;padding:10px;border-radius:13px;color:#fff;margin-top:0;box-sizing:border-box;box-shadow:0 3px 0 rgba(34,34,74,.15)}
      .feat:nth-child(6n+1){border-color:#ffc2dd} .feat:nth-child(6n+1) > .ic{background:#ff5fa8}
      .feat:nth-child(6n+2){border-color:#b8ecf7} .feat:nth-child(6n+2) > .ic{background:#29c7e8}
      .feat:nth-child(6n+3){border-color:#ffd9b8} .feat:nth-child(6n+3) > .ic{background:#ff8f2d}
      .feat:nth-child(6n+4){border-color:#e3ccff} .feat:nth-child(6n+4) > .ic{background:#b45cff}
      .feat:nth-child(6n+5){border-color:#d9f5b8} .feat:nth-child(6n+5) > .ic{background:#7ac800}
      .feat:nth-child(6n+6){border-color:#ffe9a8} .feat:nth-child(6n+6) > .ic{background:#eaa800}

      /* included-free software: pastel sunburst + navy savings pill + pill checklist */
      section.blk:has(.incl-grid){background:
        linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,255,255,.84)),
        repeating-conic-gradient(from 0deg at 50% -30%, #ff5fa8 0 14deg, #ffb703 14deg 28deg, #29c7e8 28deg 42deg, #8ee000 42deg 56deg, #ff8f2d 56deg 70deg, #b45cff 70deg 84deg) !important}
      .incl-save{background:#22224a;color:#ffd34d;box-shadow:0 6px 18px rgba(34,34,74,.25)}
      .incl-save .ic{color:#ffd34d}
      .incl-grid .it{background:#fff;border:2px solid #e6e9f2;border-radius:12px;padding:9px 12px;box-shadow:0 3px 0 rgba(34,34,74,.05)}
      .incl-grid .it:nth-child(4n+1){border-color:#ffd7ea} .incl-grid .it:nth-child(4n+1) .ic{color:#ff5fa8}
      .incl-grid .it:nth-child(4n+2){border-color:#c9f0f9} .incl-grid .it:nth-child(4n+2) .ic{color:#0eaacc}
      .incl-grid .it:nth-child(4n+3){border-color:#e2f5c6} .incl-grid .it:nth-child(4n+3) .ic{color:#5ea300}
      .incl-grid .it:nth-child(4n+4){border-color:#ffe6c9} .incl-grid .it:nth-child(4n+4) .ic{color:#f08300}

      /* reviews: pastel pink section, tilted cards, colorful avatars */
      section.blk:has(.reviews){background:#fff5f9 !important}
      .review{border-radius:20px;border-width:2px}
      .review:nth-child(3n+1){border-color:#ffc2dd;transform:rotate(-.7deg)}
      .review:nth-child(3n+2){border-color:#b8ecf7;transform:rotate(.6deg)}
      .review:nth-child(3n+3){border-color:#d9f5b8;transform:rotate(-.4deg)}
      .review .rstars{color:#ffb703;font-size:16px}
      .review:nth-child(3n+1) .ravatar{background:#ff5fa8;color:#fff}
      .review:nth-child(3n+2) .ravatar{background:#29c7e8;color:#fff}
      .review:nth-child(3n+3) .ravatar{background:#7ac800;color:#fff}

      @media(max-width:900px){
        .step,.comp,.review{transform:none !important}
        .compare::after{font-size:26px}
      }
      @media(max-width:900px){
        .card .adgirl{display:none}
        .cartooncta{padding-right:22px}
        .ctagirl{position:static;display:block;margin:0 auto -10px;width:150px}
      }
    </style>
</head>

<body>

<!-- Inline SVG icon sprite (no icon-font download; feather-style stroke icons) -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
  <symbol id="i-bolt" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
  <symbol id="i-check-circle" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
  <symbol id="i-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></symbol>
  <symbol id="i-gift" viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></symbol>
  <symbol id="i-tag" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
  <symbol id="i-activity" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></symbol>
  <symbol id="i-building" viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></symbol>
  <symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></symbol>
  <symbol id="i-share" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/><polyline points="3 6 4 7 6 5"/><polyline points="3 12 4 13 6 11"/><polyline points="3 18 4 19 6 17"/></symbol>
  <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></symbol>
  <symbol id="i-infinity" viewBox="0 0 24 24"><path d="M18.18 8c-2.04 0-3.08 1.23-4.18 2.5C12.9 9.23 11.86 8 9.82 8 7.7 8 6 9.79 6 12s1.7 4 3.82 4c2.04 0 3.08-1.23 4.18-2.5C15.1 14.77 16.14 16 18.18 16 20.3 16 22 14.21 22 12s-1.7-4-3.82-4z"/></symbol>
</defs></svg>

<header class="topbar">
  <div class="wrap inner">
    <?php if ($appLogo): ?><img src="<?php echo htmlspecialchars($appLogo); ?>" alt="<?php echo htmlspecialchars($appName); ?>"><?php else: ?><strong><?php echo htmlspecialchars($appName); ?></strong><?php endif; ?>
  </div>
</header>

<!-- HERO -->
<section class="hero">
  <div class="wrap grid">
    <div class="mobhook" aria-hidden="true">
      <img class="mh-girl" src="/assets/adgirl-3.png" alt="" onerror="if(!this.dataset.f){this.dataset.f=1;this.src='/assets/adgirl-1.png'}else{this.style.display='none'}">

      <span class="eyebrow"><svg class="ic"><use href="#i-bolt"/></svg> 100 leads included</span>
      <div class="mh-title" id="mh1">Get 100 business leads <span class="hl">+ marketing software</span> — free!</div>
      <div class="mh-proof"><span class="mh-q" id="mhq">🔍 Dentists in Austin, TX</span><b>Hill Country Dental Co.</b> · (512)&nbsp;448‑7290 · <span class="mh-ok">✓ email ✓ social</span> · <span class="mh-rt">★4.8</span> <span class="mh-more">+341 more found</span></div>
      <div class="mh-note">100 leads included · no credit card · no catch</div>
    </div>
    <div class="copy">
      <span class="eyebrow"><svg class="ic"><use href="#i-bolt"/></svg> For businesses of all types, agencies &amp; closers</span>
      <h1 id="h1">Get 100 business leads <span class="hl">+ marketing software</span> — free!</h1>
      <p class="lede">Included when you create your account — no credit card. Stop paying for stale, resold lists: type a niche and a city and instantly get business names, <strong>real phone numbers, emails and social profiles</strong>.</p>
      <ul class="checks">
        <li><svg class="ic"><use href="#i-check-circle"/></svg> Live phone numbers + one-click AI email enrichment (included)</li>
        <li><svg class="ic"><use href="#i-check-circle"/></svg> 100 leads included when you sign up — no credit card, no catch</li>
        <li><svg class="ic"><use href="#i-gift"/></svg> <span><strong>Plus a full marketing suite, AI software &amp; CRM — included free</strong> <span style="color:var(--faint);font-weight:600">(normally $97/mo)</span></span></li>
      </ul>

      <!-- Honest product preview: a representation of what a search returns -->
      <div class="preview" aria-hidden="true">
        <div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
          <span class="q" id="pvq">🔍 <b>Dentists</b> in <b>Austin, TX</b></span></div>
        <div class="lead-row"><div><div class="biz">Bright Smile Dental</div><div class="sub">Cosmetic dentist · 342 reviews</div><div class="em">✓ hello@brightsmiledental.com</div></div><div class="ph">(512) 704‑2318</div><div class="rt">★ 4.8</div></div>
        <div class="lead-row"><div><div class="biz">Lone Star Family Dentistry</div><div class="sub">General dentist · 118 reviews</div><div class="em">✓ front@lonestardental.com</div></div><div class="ph">(512) 386‑9075</div><div class="rt">★ 4.6</div></div>
        <div class="lead-row"><div><div class="biz">Congress Ave Orthodontics</div><div class="sub">Orthodontist · 261 reviews</div><div class="em">✓ info@congressortho.com</div></div><div class="ph">(512) 249‑6640</div><div class="rt">★ 4.9</div></div>
        <div class="foot">342 businesses found — your account pulls the first 100</div>
        <div class="samplenote">Example of a search result — every field is real data in the app</div>
      </div>
      <div class="prov"><svg class="ic"><use href="#i-shield"/></svg> Fresh results pulled the moment you search — never a recycled, resold list.</div>
    </div>

    <!-- SIGNUP CARD -->
    <div class="card" id="signup">
      <figure class="adgirl" aria-hidden="true"><img src="/assets/adgirl-1.png" alt="" onerror="this.parentNode.style.display='none'"></figure>

      <div class="kicker"><svg class="ic"><use href="#i-gift"/></svg> Create your account · search in ~20 seconds</div>
      <h2>Claim your 100 leads</h2>
      <div class="sub">Create your account and get leads immediately.</div>
      <div class="anchor"><svg class="ic"><use href="#i-tag"/></svg><span>Most lead tools charge <s>$99+/mo</s>. Your <b class="big">100 leads are included</b> when you create your account — phone, email &amp; socials on each.</span></div>
      <div class="err" id="err" role="alert" aria-live="assertive"></div>
      <form id="leadForm" novalidate>
        <div class="fprogress"><span class="fbar" id="fbar"></span></div>
        <div class="fstepnum" id="fstepnum">Step 1 of 4</div>

        <!-- Step 1: name + email -->
        <div class="fstep" data-step="1">
          <div class="field">
            <label for="f_name">Full name</label>
            <input type="text" id="f_name" placeholder="Jordan Blake" autocomplete="name" autocapitalize="words" enterkeyhint="next" required aria-describedby="fe_name">
            <div class="fe" id="fe_name">Please enter your name.</div>
          </div>
          <div class="field">
            <label for="f_email">Email</label>
            <input type="email" id="f_email" placeholder="you@company.com" autocomplete="email" inputmode="email" autocapitalize="none" enterkeyhint="next" required aria-describedby="fe_email">
            <div class="fe" id="fe_email">Please enter a valid email.</div>
          </div>
          <button type="submit" class="btn form-btn">Continue →</button>
        </div>

        <!-- Step 2: phone -->
        <div class="fstep" data-step="2" hidden>
          <div class="field">
            <label for="f_phone">Phone <span class="hint">· for account recovery — never sold or shared</span></label>
            <input type="tel" id="f_phone" placeholder="(555) 123-4567" autocomplete="tel-national" inputmode="tel" enterkeyhint="next" required aria-describedby="fe_phone">
            <div class="fe" id="fe_phone">Please enter a valid 10-digit phone number.</div>
          </div>
          <div class="fnav">
            <button type="button" class="btn-ghost backBtn">← Back</button>
            <button type="submit" class="btn">Continue →</button>
          </div>
        </div>

        <!-- Step 3: ownership question + submit (password is auto-generated and emailed) -->
        <div class="fstep" data-step="3" hidden>
          <fieldset class="qbox" id="ownOpts" aria-describedby="ownErr">
            <legend class="qh">One quick question</legend>
            <p>Do you want to get leads for less than 1 penny, plus sell this tool to create an additional revenue stream? <span class="qsub">Either answer still gets you your 100 leads.</span></p>
            <div class="qopts">
              <label for="own_yes"><input type="radio" name="own" id="own_yes" value="yes"><span>Yes, show me</span></label>
              <label for="own_no"><input type="radio" name="own" id="own_no" value="no"><span>No, just the leads</span></label>
            </div>
            <div class="fe" id="ownErr" style="display:none" aria-live="polite">Please pick Yes or No.</div>
          </fieldset>
          <div class="fnav">
            <button type="button" class="btn-ghost backBtn">← Back</button>
            <button type="submit" class="btn form-btn" id="submitBtn"><span class="spin" aria-hidden="true"></span><span class="btn-label">Get My 100 Leads →</span></button>
          </div>
          <div class="tcpa">By clicking “Get My 100 Leads”, I consent to receive calls, texts, and emails from All In One Marketing.com via automated calling and prerecorded voice; consent not required to purchase - opt out anytime at info@allinonemarketing.com. I agree to the <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a> &amp; <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a> (incl. arbitration). Msg &amp; data rates may apply.</div>
        </div>

        <div class="microtrust"><svg class="ic"><use href="#i-lock"/></svg> No card · Your first list on screen in ~20s · Delete anytime</div>
      </form>
    </div>
  </div>
</section>

<!-- CAPABILITY STRIP (honest capabilities, not unverifiable social proof) -->
<div class="metrics">
  <div class="wrap row">
    <div><div class="n"><svg class="ic"><use href="#i-activity"/></svg>Live</div><div class="l">Pulled from Google Maps in real time</div></div>
    <div><div class="n"><svg class="ic"><use href="#i-building"/></svg>Any U.S. city</div><div class="l">Search any niche in any market</div></div>
    <div><div class="n"><svg class="ic"><use href="#i-bolt"/></svg>$0 to start</div><div class="l">100 leads included — no card required</div></div>
  </div>
  <div class="wrap worksrow">Works with your stack — export straight to your Free CRM, Close, Instantly, or any CRM via CSV.</div>
</div>

<!-- HOW IT WORKS -->
<section class="blk">
  <div class="wrap">
    <img class="secgirl" src="/assets/adgirl-4.png" alt="" aria-hidden="true">
    <div class="h2c"><h2>From “I need leads” to a full pipeline in 3 steps</h2><p>No tech skills. No spreadsheets. No paying $2 a lead for stale lists.</p></div>
    <div class="steps">
      <div class="step"><div class="num">1</div><h3>Search a niche + city</h3><p>“Dentists in Austin.” “Roofers in Miami.” Anything. We pull every matching business from Google Maps.</p></div>
      <div class="step"><div class="num">2</div><h3>Get contact-ready leads</h3><p>Business name, phone, website, rating — then one click enriches each lead with emails &amp; socials, included.</p></div>
      <div class="step"><div class="num">3</div><h3>Work them &amp; close</h3><p>Track outreach in the built-in CRM or export to CSV and drop them into your dialer, CRM, or cold-email tool.</p></div>
    </div>
  </div>
</section>

<!-- WHY IT BEATS LISTS (anchor / risk reversal) -->
<section class="blk" style="background:var(--panel);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>Fresh leads you pull yourself &gt; a stale list someone sold you</h2></div>
    <div class="compare">
      <div class="comp old">
        <h4><svg class="ic"><use href="#i-x-circle"/></svg> Bought lead lists</h4>
        <ul>
          <li><svg class="ic"><use href="#i-x"/></svg> $1–$2 per lead, sold to 20 other people</li>
          <li><svg class="ic"><use href="#i-x"/></svg> Months (or years) old — half are disconnected</li>
          <li><svg class="ic"><use href="#i-x"/></svg> No socials, no ratings, no way to verify</li>
          <li><svg class="ic"><use href="#i-x"/></svg> Locked to one CSV you can’t refresh</li>
        </ul>
      </div>
      <div class="comp new">
        <h4><svg class="ic"><use href="#i-check-circle"/></svg> Leads from <?php echo htmlspecialchars($appName); ?></h4>
        <ul>
          <li><svg class="ic"><use href="#i-check"/></svg> Pulled fresh, live from Google Maps, on demand</li>
          <li><svg class="ic"><use href="#i-check"/></svg> Real phone numbers + AI-enriched emails included</li>
          <li><svg class="ic"><use href="#i-check"/></svg> Ratings + social profiles to qualify before you call</li>
          <li><svg class="ic"><use href="#i-check"/></svg> Verify any lead yourself — your first 100 are included</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- WHAT YOU GET -->
<section class="blk">
  <div class="wrap">
    <div class="h2c"><h2>Everything you need to fill your calendar</h2></div>
    <div class="feats">
      <div class="feat"><svg class="ic"><use href="#i-phone"/></svg><div><h4>Live phone numbers</h4><p>Direct business lines pulled live from Google Maps — not recycled list data.</p></div></div>
      <div class="feat"><svg class="ic"><use href="#i-mail"/></svg><div><h4>AI email enrichment included</h4><p>Find decision-maker emails with one click. Enrichment never costs extra credits.</p></div></div>
      <div class="feat"><svg class="ic"><use href="#i-share"/></svg><div><h4>Social profiles</h4><p>Instagram, Facebook &amp; more, so you can warm up leads before you reach out.</p></div></div>
      <div class="feat"><svg class="ic"><use href="#i-list"/></svg><div><h4>Built-in lead CRM</h4><p>Organize lists, tag statuses, and track who you’ve contacted — no extra tools.</p></div></div>
      <div class="feat"><svg class="ic"><use href="#i-file"/></svg><div><h4>One-click CSV export</h4><p>Send leads straight to your dialer, GHL, or cold-email platform in seconds.</p></div></div>
      <div class="feat"><svg class="ic"><use href="#i-infinity"/></svg><div><h4>Scale when ready</h4><p>Start with your 100 included, then unlock thousands of leads a month when it’s paying off.</p></div></div>
    </div>
  </div>
</section>

<!-- INCLUDED FREE: FULL MARKETING SUITE + CRM -->
<section class="blk" style="background:#f4fbf7;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap" style="text-align:center">
    <div class="incl-save"><svg class="ic" style="font-size:17px"><use href="#i-gift"/></svg> Normally $97/month &mdash; included 100% free. You save $1,164/year.</div>
    <div class="h2c" style="margin-bottom:26px">
      <h2>Plus complete marketing software, AI software &amp; a CRM &mdash; <span class="hl">included free</span></h2>
      <p>Your account comes with the entire all-in-one marketing platform, AI tools, and a CRM (the system that stores your leads and tracks every follow-up) at no cost &mdash; the software other companies charge $97/month for. One-click export your new leads straight into it and start closing. You only ever pay for the marketing usage you actually send (texts, calls, emails).</p>
    </div>

    <div class="incl-chips">
      <span>HubSpot</span><span>Mailchimp</span><span>Calendly</span><span>ClickFunnels</span><span>Hootsuite</span><span>Typeform</span><span>CallRail</span><span>ActiveCampaign</span><span>Podium</span><span>Kajabi</span><span>Keap</span><span>Monday.com</span>
    </div>

    <div class="incl-grid">
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Full CRM &amp; contact management</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Two-way SMS &amp; email marketing</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> AI voice &amp; conversation agents</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Visual workflow &amp; automation builder</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Website &amp; landing page builder</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Sales funnel builder with A/B testing</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Calendar &amp; appointment booking</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Pipeline &amp; opportunity management</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Reputation &amp; review management</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Social media planner &amp; scheduler</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Membership sites &amp; online courses</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Forms, surveys &amp; quizzes</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Live chat &amp; website chat widget</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Call tracking &amp; recording</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Invoicing, payments &amp; orders</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Video hosting &amp; course delivery</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Email campaign builder</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Advanced analytics &amp; reporting</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Affiliate manager</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Communities &amp; group builder</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Mobile app &mdash; iOS &amp; Android</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Unlimited users &amp; sub-accounts</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> Ad reporting &amp; attribution</div>
      <div class="it"><svg class="ic"><use href="#i-check-circle"/></svg> No monthly software fee, ever</div>
    </div>
  </div>
</section>

<!-- REVIEWS (real customer reviews from Trustpilot) -->
<section class="blk" style="background:var(--panel);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="wrap">
    <div class="h2c"><h2>What All In One Marketing customers say</h2><p>Real reviews from customers on Trustpilot.</p></div>
    <div class="reviews">
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“Managing my leads and communication has never been easier. I’m really glad I found a solution that lets me automate so much and keep everything organized in one place.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">C</div><div><div class="rname">Christelle Cordier</div><div class="rmeta">Trustpilot review · FR</div></div></div>
      </div>
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“A+ for efficiency. Generating ROI since day 1.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">C</div><div><div class="rname">Conrad Ambroise</div><div class="rmeta">Trustpilot review · US</div></div></div>
      </div>
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“Having everything in one place has been incredibly efficient. The automation options really help me stay on top of everything without getting bogged down in the details.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">M</div><div><div class="rname">Mia Nickel</div><div class="rmeta">Trustpilot review · DE</div></div></div>
      </div>
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“The ease of use is what stands out to me the most. Whether I’m building a funnel or sending a quick email, everything just works.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">A</div><div><div class="rname">Andy Berg</div><div class="rmeta">Trustpilot review · DE</div></div></div>
      </div>
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“The flexibility of the platform is great. I can customize everything to fit my business needs and I love how I can easily track customer interactions across different channels.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">B</div><div><div class="rname">Beatrice Vitale</div><div class="rmeta">Trustpilot review · IT</div></div></div>
      </div>
      <div class="review">
        <div class="rstars" aria-label="Rated 5 out of 5">★★★★★</div>
        <p class="rtext">“I’ve been able to automate a lot of my processes, which has saved me tons of time.”</p>
        <div class="rwho"><div class="ravatar" aria-hidden="true">O</div><div><div class="rname">Olivia Adam</div><div class="rmeta">Trustpilot review · FR</div></div></div>
      </div>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="blk">
  <div class="wrap">
    <div class="cta cartooncta">
      <img class="ctagirl" src="/assets/adgirl-5.png" alt="" aria-hidden="true">
      <h2>Your next 100 customers are already on Google Maps.</h2>
      <p>Your competitor can pull the same list tomorrow. Grab your 100 leads and get there first — before you close this tab.</p>
      <a href="#signup" class="btn js-focus">Get My 100 Leads →</a>
      <div class="fine">No credit card · Instant access · Delete your account anytime</div>
    </div>
  </div>
</section>

<footer class="wrap">
  <div>&copy; 2026 All In One Marketing, Inc.</div>
  <div>
    <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms</a> ·
    <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy</a>
  </div>
</footer>

<!-- STICKY MOBILE CTA -->
<div class="mobbar"><a href="#signup" class="btn js-focus">Get My 100 Leads →</a></div>

<script>
  // Attribution capture (UTM/fb params persisted; timezone; referrer; fbp/fbc).
  const track = (function(){
    const p = new URLSearchParams(location.search);
    const keys=['utm_source','utm_medium','utm_campaign','fbcampaignid','fbplacement','fbadsetid','fbadid'];
    const t={}; let has=false;
    keys.forEach(k=>{const v=p.get(k); if(v){t[k]=v;has=true;}});
    try{ if(has) localStorage.setItem('signupTracking',JSON.stringify(t)); else {const s=localStorage.getItem('signupTracking'); if(s) Object.assign(t,JSON.parse(s));} }catch(e){}
    keys.forEach(k=>{if(!t[k])t[k]='';});
    try{ t.timezone=Intl.DateTimeFormat().resolvedOptions().timeZone||''; }catch(e){t.timezone='';}
    t.referrer=document.referrer||'';
    return t;
  })();
  function cookie(n){const m=document.cookie.match('(^|;)\\s*'+n+'\\s*=\\s*([^;]+)');return m?m.pop():'';}
  function esc(s){return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

  // Optional ad scent: if the ad passes ?niche= and/or ?city=, echo it in the H1 + preview query.
  (function(){
    const p=new URLSearchParams(location.search);
    const clean=s=>(s||'').replace(/[<>]/g,'').slice(0,40).trim();
    const niche=clean(p.get('niche')), city=clean(p.get('city'));
    if(niche || city){
      const subject = niche ? (esc(niche)+' leads') : 'local business leads';
      const where = city ? (' in '+esc(city)) : '';
      const h1=document.getElementById('h1');
      if(h1) h1.innerHTML='100 '+subject+where+' — <span class="hl">pulled live from Google Maps.</span>';
      const mh=document.getElementById('mh1');
      if(mh) mh.innerHTML='100 '+subject+where+' — <span class="hl">pulled live from Google Maps.</span>';
      const q=document.getElementById('pvq');
      if(q){
        if(niche) q.innerHTML='🔍 <b>'+esc(niche)+'</b>'+(city?(' in <b>'+esc(city)+'</b>'):'');
        else q.innerHTML='🔍 <b>Businesses</b> in <b>'+esc(city)+'</b>';
      }
      const mq=document.getElementById('mhq');
      if(mq){
        if(niche) mq.innerHTML='🔍 '+esc(niche)+(city?(' in '+esc(city)):' near you');
        else mq.innerHTML='🔍 Businesses in '+esc(city);
      }
    }
  })();

  // Hide the sticky mobile CTA while the in-card form is on screen (avoid duplicate CTAs).
  (function(){
    const bar=document.querySelector('.mobbar'), card=document.getElementById('signup');
    if(!bar||!card||!('IntersectionObserver' in window)) return;
    new IntersectionObserver(function(e){ bar.style.transform = e[0].isIntersecting ? 'translateY(120%)' : 'translateY(0)'; },{threshold:0})
      .observe(card);
  })();

  // Top-bar + final + sticky CTAs scroll to the form. On desktop we also focus the
  // first field; on touch we don't (deferred focus can't open the keyboard and leaves
  // a dead focus ring) — the anchor scroll to #signup is enough there.
  var isTouch = (window.matchMedia && matchMedia('(hover:none)').matches);
  document.querySelectorAll('.js-focus').forEach(function(a){
    a.addEventListener('click', function(){ if(isTouch) return; setTimeout(function(){ var n=document.getElementById('f_name'); if(n) n.focus({preventScroll:true}); }, 420); });
  });

  // Show/hide password.
  (function(){
    const t=document.getElementById('pwToggle'), pw=document.getElementById('f_pass');
    if(t&&pw) t.addEventListener('click',function(){ const s=pw.type==='password'; pw.type=s?'text':'password'; t.textContent=s?'Hide':'Show'; t.setAttribute('aria-label',s?'Hide password':'Show password'); pw.focus(); });
  })();

  // Live US phone masking → (123) 456-7890 as they type.
  (function(){
    const el=document.getElementById('f_phone'); if(!el) return;
    el.addEventListener('input',function(){
      let d=el.value.replace(/\D/g,'').slice(0,10);
      let out=d;
      if(d.length>6) out='('+d.slice(0,3)+') '+d.slice(3,6)+'-'+d.slice(6);
      else if(d.length>3) out='('+d.slice(0,3)+') '+d.slice(3);
      else if(d.length>0) out='('+d;
      el.value=out;
    });
  })();

  const form=document.getElementById('leadForm'), err=document.getElementById('err'), btn=document.getElementById('submitBtn');
  const btnLabel=btn.querySelector('.btn-label')||btn;
  const F={name:document.getElementById('f_name'),email:document.getElementById('f_email'),phone:document.getElementById('f_phone')};
  const emailOk=v=>/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v);

  // Fire a one-time form-start signal so Meta can optimize on form-starters, not just PageView.
  let started=false;
  [F.name,F.email,F.phone].forEach(el=>el.addEventListener('focus',function(){
    if(started) return; started=true;
    try{ if(window.fbq){ fbq('trackCustom','FormStart'); fbq('track','ViewContent',{content_name:'start_signup'}); } }catch(e){}
  },{once:false}));

  // Inline validation: mark a field good/bad, toggle its message + aria-invalid.
  function setField(el,ok){
    const wrap=el.closest('.field');
    el.classList.toggle('bad',!ok);
    wrap.classList.toggle('err-on',!ok);
    el.setAttribute('aria-invalid', ok?'false':'true');
    return ok;
  }
  function vName(){return setField(F.name,F.name.value.trim().length>0);}
  function vEmail(){return setField(F.email,emailOk(F.email.value.trim()));}
  function vPhone(){return setField(F.phone,F.phone.value.trim().replace(/\D/g,'').length===10);}
  F.name.addEventListener('blur',vName); F.email.addEventListener('blur',vEmail);
  F.phone.addEventListener('blur',vPhone);
  [F.name,F.email,F.phone].forEach(el=>el.addEventListener('input',function(){ if(el.classList.contains('bad')) setField(el,true); }));

  // Render error text safely (never inject server-supplied strings as HTML).
  function fail(msg){ err.textContent=msg; err.style.display='block'; btn.classList.remove('loading'); btn.disabled=false; btnLabel.textContent='Get My 100 Leads →'; }
  function focusInvalid(el){ if(!el) return; try{ el.scrollIntoView({behavior:'smooth',block:'center'}); }catch(e){ try{el.scrollIntoView();}catch(_){} } setTimeout(function(){ try{ el.focus({preventScroll:true}); }catch(e){} }, 300); }
  // Clear the radio error as soon as the user picks an option.
  document.querySelectorAll('input[name="own"]').forEach(r=>r.addEventListener('change',function(){ document.getElementById('ownErr').style.display='none'; }));

  // ---- Multi-step flow ----
  const fsteps=[].slice.call(form.querySelectorAll('.fstep'));
  const fbarEl=document.getElementById('fbar'), fstepnumEl=document.getElementById('fstepnum');
  const TOTAL=fsteps.length;
  let currentStep=1;
  function showStep(n,doFocus){
    currentStep=n;
    fsteps.forEach(function(s){ s.hidden=(parseInt(s.getAttribute('data-step'),10)!==n); });
    if(fbarEl) fbarEl.style.width=(n/TOTAL*100)+'%';
    if(fstepnumEl) fstepnumEl.textContent='Step '+n+' of '+TOTAL;
    err.style.display='none';
    if(doFocus){
      var fe = n===1?F.name : n===2?F.phone : document.getElementById('own_yes');
      if(fe){ try{ fe.focus({preventScroll:true}); }catch(e){} }
    }
  }
  function validateStep(n){
    if(n===1){ var a=vName(), b=vEmail(); if(!(a&&b)){ focusInvalid(!a?F.name:F.email); return false; } return true; }
    if(n===2){ if(!vPhone()){ focusInvalid(F.phone); return false; } return true; }
    return true;
  }
  form.querySelectorAll('.backBtn').forEach(function(b){ b.addEventListener('click',function(){ if(currentStep>1) showStep(currentStep-1,true); }); });
  showStep(1,false);

  form.addEventListener('submit', function(e){
    e.preventDefault();
    // Steps 1–3: validate the visible step, then advance to the next.
    if(currentStep < TOTAL){
      if(validateStep(currentStep)) showStep(currentStep+1,true);
      return;
    }
    // Final step: re-validate everything, jumping back to any invalid step.
    err.style.display='none';
    const okN=vName(), okE=vEmail(), okP=vPhone();
    if(!okN||!okE){ showStep(1,true); focusInvalid(!okN?F.name:F.email); return fail('Please fix the highlighted fields.'); }
    if(!okP){ showStep(2,true); return fail('Please enter a valid 10-digit phone number.'); }
    const own=document.querySelector('input[name="own"]:checked');
    const ownErr=document.getElementById('ownErr'); ownErr.style.display = own ? 'none':'block';
    if(!own){ focusInvalid(document.getElementById('own_yes')); return fail('Please answer the question above.'); }

    btn.disabled=true; btn.classList.add('loading'); btnLabel.textContent='Creating your account…';
    var settled=false;
    var hangTimer=setTimeout(function(){ if(settled) return; settled=true; fail('That took longer than expected — please try again.'); }, 15000);
    const leadEventId='lead.'+Date.now()+'.'+Math.floor(Math.random()*1e9);
    const fd=new FormData();
    fd.append('name',F.name.value.trim()); fd.append('phone',F.phone.value.trim());
    fd.append('email',F.email.value.trim());
    fd.append('wants_ownership',own.value);
    Object.keys(track).forEach(k=>fd.append(k,track[k]));
    fd.append('event_id',leadEventId); fd.append('event_source_url',location.href);
    fd.append('signup_source','fb_100leads');   // /100leads = FB cartoon '100 leads + software' ad signups
    fd.append('fbp',cookie('_fbp')); fd.append('fbc',cookie('_fbc'));

    fetch('register.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if(settled) return; settled=true; clearTimeout(hangTimer);
      if(data.success){
        try{ if(window.fbq){ fbq('track','Lead',{},{eventID:leadEventId}); fbq('track','CompleteRegistration',{},{eventID:leadEventId}); } }catch(e){}
        window.location.href='/dashboard';
      } else if(/already exists/i.test(data.message||'')){
        // Build the login link via the DOM so no server string is injected as HTML.
        fail('An account with this email already exists. ');
        var a=document.createElement('a'); a.href='login.php'; a.textContent='Log in →'; err.appendChild(a);
      } else {
        fail(data.message||'Something went wrong. Please try again.');
      }
    }).catch(function(){ if(settled) return; settled=true; clearTimeout(hangTimer); fail('Network error — please try again.'); });
  });
</script>
<script>
// Landing-page analytics beacon (views / dwell time / scroll depth -> admin stats).
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
