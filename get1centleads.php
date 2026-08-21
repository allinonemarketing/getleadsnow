<?php
/**
 * /get1centleads — minimal Facebook ad landing page (gray card, stacked form,
 * big UNLOCK button). Two-part form: name/email/phone, then the reseller
 * question + TCPA on the final part. Password is auto-generated server-side.
 * Attribution: signup_source fb_get1centleads (sheet + GHL tag + admin stats).
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
<title>Get Leads For Just 1 Cent — <?php echo htmlspecialchars($appName); ?></title>
<meta name="description" content="Get leads for just 1 cent. No catch, works for any industry — and your first 100 leads are free.">
<link rel="icon" type="image/jpeg" href="<?php echo htmlspecialchars($appLogo); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap"></noscript>

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
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Roboto',system-ui,sans-serif;background:#fff;color:#1a1a1a;-webkit-font-smoothing:antialiased;line-height:1.45}
  .logo{display:flex;justify-content:center;padding:22px 0 14px}
  .logo img{height:44px;width:auto}
  .card{max-width:1180px;margin:0 auto 26px;background:#f2f2f2;border-radius:14px;padding:52px 24px 44px}
  .inner{max-width:1000px;margin:0 auto;text-align:center}
  #leadForm{max-width:620px;margin:0 auto}
  .inner > .err{max-width:620px;margin-left:auto;margin-right:auto}
  .eyebrow{font-size:17px;color:#333;margin-bottom:14px}
  h1{font-size:clamp(1.7rem,3.6vw,2.6rem);font-weight:900;line-height:1.18;letter-spacing:-.01em;color:#111;margin-bottom:30px}
  .field{margin-bottom:14px;text-align:left}
  .field input{width:100%;background:#fff;border:1px solid #cfcfcf;border-radius:6px;padding:16px 14px;font-size:15px;font-family:inherit;text-align:center}
  .field input:focus{outline:2px solid #5468ff;border-color:#5468ff}
  .phone{display:flex;align-items:center;background:#fff;border:1px solid #cfcfcf;border-radius:6px}
  .phone:focus-within{outline:2px solid #5468ff;border-color:#5468ff}
  .phone .flag{display:flex;align-items:center;padding-left:14px;flex:none;width:52px}
  .phone .flag svg{border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
  .phone::after{content:"";flex:none;width:52px}  /* balances the flag so the placeholder centers */
  .phone input{border:none;flex:1;text-align:center;min-width:0}
  .phone input:focus{outline:none}
  .fe{display:none;color:#c0392b;font-size:12.5px;font-weight:500;margin-top:5px;text-align:center}
  .bad{border-color:#c0392b !important}
  .btn{width:100%;max-width:380px;margin:14px auto 0;display:block;background:#5468ff;color:#fff;border:none;border-radius:12px;
    padding:20px 20px;font-size:26px;font-weight:700;letter-spacing:.04em;cursor:pointer;font-family:inherit;transition:background .12s}
  .btn:hover{background:#4356e0}
  .btn:disabled{opacity:.6;cursor:default}
  .tcpa{max-width:560px;margin:16px auto 0;font-size:7px;line-height:1.6;color:#8a8f96;text-transform:uppercase;letter-spacing:.02em}
  .tcpa a{color:#8a8f96}
  .qbox{background:#fff;border:1px solid #d8d8d8;border-radius:10px;padding:20px 18px;text-align:left;margin-bottom:6px}
  .qbox .qh{font-weight:700;font-size:15px;margin-bottom:8px;color:#111}
  .qbox p{font-size:17px;font-weight:500;color:#222;margin-bottom:14px}
  .qbox .qsub{color:#888;font-size:13px;font-weight:400}
  .qopts{display:grid;gap:9px}
  .qopts label{display:flex;align-items:center;gap:10px;border:1px solid #d8d8d8;border-radius:8px;padding:12px 14px;cursor:pointer;font-size:14.5px;font-weight:500;background:#fafafa}
  .qopts label:hover{border-color:#5468ff}
  .qopts input{accent-color:#5468ff;width:17px;height:17px}
  .err{display:none;background:#fdecea;color:#c0392b;border-radius:8px;padding:11px 14px;font-size:13.5px;font-weight:500;margin-bottom:12px}
  .err a{color:#c0392b;font-weight:700}
  .trust{display:flex;align-items:center;justify-content:center;gap:34px;flex-wrap:wrap;margin:40px 0 30px;font-weight:700;font-size:17px;color:#222}
  .trust .tp .star{color:#00b67a}
  .trust .gr .g{color:#4285f4}.trust .gr .o{color:#ea4335}.trust .gr .y{color:#fbbc05}.trust .gr .gn{color:#34a853}
  .trust .stars{color:#f5a623;letter-spacing:2px}
  .reviews{display:grid;grid-template-columns:1fr 1fr;gap:26px 40px;max-width:900px;margin:0 auto;text-align:left}
  .rev{display:flex;gap:13px;align-items:flex-start}
  .rev .av{width:46px;height:46px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:18px}
  .rev p{font-size:15px;color:#222;line-height:1.5}
  .rev b{color:#111}
  .foot{text-align:center;padding:10px 0 30px;color:#666;font-size:13px}
  .foot .links{margin-top:8px;font-size:11px}
  .foot a{color:#666;text-decoration:none;margin:0 8px}
  @media(max-width:760px){ .card{margin:0 10px 20px;padding:34px 16px 30px} .reviews{grid-template-columns:1fr} .btn{font-size:22px} }
</style>
</head>
<body>

<div class="logo"><img src="<?php echo htmlspecialchars($appLogo); ?>" alt="<?php echo htmlspecialchars($appName); ?>"></div>

<div class="card">
  <div class="inner">
    <div class="eyebrow">Even Fortune 500 Companies Can&rsquo;t Believe It...</div>
    <h1>Get Leads For Just 1 Cent. There Is No Catch. Works For Any Industry. Get 100 Free Leads Now...</h1>

    <div class="err" id="err" role="alert" aria-live="assertive"></div>
    <form id="leadForm" novalidate>
      <!-- Part 1: name / email / phone -->
      <div class="fstep" data-step="1">
        <div class="field"><input type="text" id="f_name" placeholder="Name" autocomplete="name" autocapitalize="words" enterkeyhint="next" required><div class="fe" id="fe_name">Please enter your name.</div></div>
        <div class="field"><input type="email" id="f_email" placeholder="Email" autocomplete="email" inputmode="email" autocapitalize="none" enterkeyhint="next" required><div class="fe" id="fe_email">Please enter a valid email.</div></div>
        <div class="field"><div class="phone"><span class="flag" aria-hidden="true"><svg viewBox="0 0 24 16" width="24" height="16" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="16" fill="#fff"/><g fill="#b22234"><rect y="0" width="24" height="1.23"/><rect y="2.46" width="24" height="1.23"/><rect y="4.92" width="24" height="1.23"/><rect y="7.38" width="24" height="1.23"/><rect y="9.85" width="24" height="1.23"/><rect y="12.31" width="24" height="1.23"/><rect y="14.77" width="24" height="1.23"/></g><rect width="9.6" height="8.6" fill="#3c3b6e"/><g fill="#fff"><circle cx="1.6" cy="1.5" r=".45"/><circle cx="4.8" cy="1.5" r=".45"/><circle cx="8" cy="1.5" r=".45"/><circle cx="3.2" cy="3" r=".45"/><circle cx="6.4" cy="3" r=".45"/><circle cx="1.6" cy="4.5" r=".45"/><circle cx="4.8" cy="4.5" r=".45"/><circle cx="8" cy="4.5" r=".45"/><circle cx="3.2" cy="6" r=".45"/><circle cx="6.4" cy="6" r=".45"/><circle cx="1.6" cy="7.4" r=".45"/><circle cx="4.8" cy="7.4" r=".45"/><circle cx="8" cy="7.4" r=".45"/></g></svg></span><input type="tel" id="f_phone" placeholder="Phone" autocomplete="tel-national" inputmode="tel" enterkeyhint="go" required></div><div class="fe" id="fe_phone">Please enter a valid 10-digit phone number.</div></div>
        <button type="submit" class="btn">UNLOCK</button>
      </div>

      <!-- Part 2: reseller question + TCPA + final submit -->
      <div class="fstep" data-step="2" hidden>
        <fieldset class="qbox" id="ownOpts" style="border:none;">
          <p>Do you want to get leads for less than 1 penny, plus add a new massive revenue stream? <span class="qsub">Either answer still gets you your 100 free leads.</span></p>
          <div class="qopts">
            <label for="own_yes"><input type="radio" name="own" id="own_yes" value="yes"><span>Yes, show me</span></label>
            <label for="own_no"><input type="radio" name="own" id="own_no" value="no"><span>No, just the leads</span></label>
          </div>
          <div class="fe" id="ownErr" aria-live="polite">Please pick Yes or No.</div>
        </fieldset>
        <button type="submit" class="btn" id="submitBtn"><span id="btnLabel">GET MY 100 FREE LEADS</span></button>
        <div class="tcpa">By clicking &ldquo;Get My 100 Free Leads&rdquo;, I give AllInOneMarketing.com and its partners express written permission to contact me at the number and email I provided above, via email, phone, text message (SMS/MMS) and/or cell phone (including use of automated dialing equipment and pre-recorded calls and/or messages). This consent is not a condition of receiving services. I agree to the <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a> &amp; <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a> (incl. arbitration). Msg &amp; data rates may apply.</div>
      </div>
    </form>

    <div class="trust">
      <span class="tp"><span class="star">&#9733;</span> Trustpilot <b>4.7</b></span>
      <span class="gr"><span class="g">G</span><span class="o">o</span><span class="y">o</span><span class="g">g</span><span class="gn">l</span><span class="o">e</span> Reviews <span class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span></span>
    </div>

    <!-- Real Trustpilot reviews (same verified set used on the other pages) -->
    <div class="reviews">
      <div class="rev"><div class="av" style="background:#e0797b;">C</div><p>&ldquo;Managing my leads and communication has never been easier. I&rsquo;m really glad I found a solution that lets me automate so much.&rdquo; <b>&mdash; Christelle Cordier</b></p></div>
      <div class="rev"><div class="av" style="background:#5b8def;">C</div><p>&ldquo;A+ for efficiency. Generating ROI since day 1.&rdquo; <b>&mdash; Conrad Ambroise</b></p></div>
      <div class="rev"><div class="av" style="background:#58b57c;">A</div><p>&ldquo;The ease of use is what stands out to me the most. Whether I&rsquo;m building a funnel or sending a quick email, everything just works.&rdquo; <b>&mdash; Andy Berg</b></p></div>
      <div class="rev"><div class="av" style="background:#9a6fd0;">O</div><p>&ldquo;I&rsquo;ve been able to automate a lot of my processes, which has saved me tons of time.&rdquo; <b>&mdash; Olivia Adam</b></p></div>
    </div>
  </div>
</div>

<div class="foot">
  We Use All In One Marketing.com
  <div class="links">
    <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a>
    <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms &amp; Conditions</a>
  </div>
</div>

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
  function cookie(n){const m=document.cookie.match('(^|;)\\s*'+n+'\\s*=\\s*([^;]+)');return m?decodeURIComponent(m.pop()):'';}

  (function(){
    const form=document.getElementById('leadForm');
    const F={name:document.getElementById('f_name'),email:document.getElementById('f_email'),phone:document.getElementById('f_phone')};
    const err=document.getElementById('err');
    const steps=form.querySelectorAll('.fstep');
    let step=1, started=false;

    function setField(el,ok){ el.classList.toggle('bad',!ok); const fe=document.getElementById('fe_'+el.id.slice(2)); if(fe) fe.style.display=ok?'none':'block'; return ok; }
    function vName(){ return setField(F.name,F.name.value.trim().length>=2); }
    function vEmail(){ return setField(F.email,/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(F.email.value.trim())); }
    function vPhone(){ const d=F.phone.value.replace(/\D+/g,''); return setField(F.phone,d.length===10||(d.length===11&&d[0]==='1')); }
    [F.name,F.email,F.phone].forEach(el=>el.addEventListener('input',function(){ if(el.classList.contains('bad')) setField(el,true);
      if(!started){ started=true; try{ if(window.fbq){ fbq('trackCustom','FormStart'); fbq('track','ViewContent',{content_name:'get1cent_signup'}); } }catch(e){} } }));

    // Live phone mask: (xxx) xxx-xxxx, max 10 digits; a pasted/typed leading
    // "1" country code (11 digits) is stripped so numbers never come through
    // as 1516473431x.
    F.phone.addEventListener('input',function(){
      var d=F.phone.value.replace(/\D+/g,'');
      if(d.length>10 && d.charAt(0)==='1') d=d.slice(1);
      d=d.slice(0,10);
      F.phone.value = d.length>6 ? '('+d.slice(0,3)+') '+d.slice(3,6)+'-'+d.slice(6)
                    : d.length>3 ? '('+d.slice(0,3)+') '+d.slice(3)
                    : d.length>0 ? '('+d : '';
    });

    function showStep(n){ step=n; steps.forEach(s=>{ s.hidden = parseInt(s.getAttribute('data-step'),10)!==n; }); }

    form.addEventListener('submit',function(e){
      e.preventDefault();
      if(step===1){
        const a=vName(), b=vEmail(), c=vPhone();
        if(!(a&&b&&c)){ (!a?F.name:!b?F.email:F.phone).focus(); return; }
        showStep(2);
        return;
      }
      const own=document.querySelector('input[name="own"]:checked');
      const ownErr=document.getElementById('ownErr');
      ownErr.style.display = own?'none':'block';
      if(!own) return;

      const btn=document.getElementById('submitBtn'), lbl=document.getElementById('btnLabel');
      btn.disabled=true; lbl.textContent='CREATING YOUR ACCOUNT…';
      err.style.display='none';
      var settled=false;
      var hangTimer=setTimeout(function(){ if(settled) return; settled=true; btn.disabled=false; lbl.textContent='GET MY 100 FREE LEADS';
        err.textContent='That took longer than expected — please try again.'; err.style.display='block'; },15000);
      const leadEventId='lead.'+Date.now()+'.'+Math.floor(Math.random()*1e9);
      const fd=new FormData();
      fd.append('name',F.name.value.trim()); fd.append('phone',F.phone.value.trim());
      fd.append('email',F.email.value.trim());
      fd.append('wants_ownership',own.value);
      Object.keys(track).forEach(k=>fd.append(k,track[k]));
      fd.append('event_id',leadEventId); fd.append('event_source_url',location.href);
      fd.append('signup_source','fb_get1centleads');   // /get1centleads = FB minimal 'UNLOCK' page signups
      fd.append('fbp',cookie('_fbp')); fd.append('fbc',cookie('_fbc'));

      fetch('register.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(settled) return; settled=true; clearTimeout(hangTimer);
        if(data.success){
          try{ if(window.fbq){ fbq('track','Lead',{},{eventID:leadEventId}); fbq('track','CompleteRegistration',{},{eventID:leadEventId}); } }catch(e){}
          window.location.href='/dashboard';
        } else {
          btn.disabled=false; lbl.textContent='GET MY 100 FREE LEADS';
          err.textContent=(data.message||'Something went wrong — please try again.')+' ';
          if(/already|exists/i.test(data.message||'')){ var a=document.createElement('a'); a.href='/login'; a.textContent='Log in →'; err.appendChild(a); }
          err.style.display='block';
        }
      }).catch(function(){
        if(settled) return; settled=true; clearTimeout(hangTimer);
        btn.disabled=false; lbl.textContent='GET MY 100 FREE LEADS';
        err.textContent='Network error — please try again.'; err.style.display='block';
      });
    });
  })();

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
