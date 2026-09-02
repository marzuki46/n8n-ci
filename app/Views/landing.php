<?php

use App\Services\InquiryService;

$svc     = new InquiryService();
$p       = $svc->getPublicProfile();
$captcha = $svc->recaptchaEnabled() ? null : $svc->issueCaptcha();

// Fallback bila belum diatur di Settings.
$name    = $p['profile_name'] !== '' ? $p['profile_name'] : 'Juki';
$tagline = $p['profile_tagline'] !== ''
    ? $p['profile_tagline']
    : 'Web Developer & Search Engine Optimization Specialist';
$bio = $p['profile_bio'] !== ''
    ? $p['profile_bio']
    : 'I help businesses turn their websites into hard-working assets — fast, secure, and engineered to rank. From deep technical audits to content optimization and authority building, every decision is measured against one question: does it grow your business?';
$location  = $p['profile_location'] !== '' ? $p['profile_location'] : 'Solo, Indonesia';
$email     = $p['contact_email'];
$whatsapp  = $p['contact_whatsapp'];
$website   = $p['contact_website'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($name) ?> — Web Development & SEO That Delivers Results</title>
<meta name="description" content="<?= esc($name) ?> — professional web development and full-stack SEO (technical, on-page, off-page). Based in <?= esc($location) ?>, working worldwide.">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  :root {
    --bg: #07090d; --surface: #151a23; --surface2: #1c2230; --border: #2e3648;
    --border-strong: #3c465e;
    --text: #f4f6fb; --dim: #b6bfd0; --faint: #7a8499;
    --brand: #ff6d5a; --brand-dark: #ff5742; --ok: #3ddba0;
  }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg); color: var(--text); line-height: 1.65; font-size: 16px;
  }
  .container { max-width: 1080px; margin: 0 auto; padding: 0 24px; }

  /* ===== Hero ===== */
  .hero { position: relative; padding: 110px 0 90px; text-align: center;
    background:
      radial-gradient(600px 300px at 50% -80px, rgba(255,109,90,.16), transparent),
      radial-gradient(400px 260px at 85% 20%, rgba(96,165,250,.09), transparent);
    border-bottom: 1px solid var(--border);
  }
  .badge-pill {
    display: inline-block; padding: 6px 16px; border-radius: 999px; font-size: 13px;
    background: rgba(255,109,90,.14); color: #ffb1a6; border: 1px solid rgba(255,109,90,.4);
    margin-bottom: 26px; letter-spacing: .4px; font-weight: 600;
  }
  h1 { font-size: clamp(30px, 5vw, 46px); line-height: 1.18; letter-spacing: -.5px; max-width: 760px; margin: 0 auto 18px; color: var(--text); }
  h1 em { font-style: normal; background: linear-gradient(92deg, #ff8a5a, #ff6d5a 55%, #ffa07a); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .hero-sub { color: #ccd3e0; font-size: 17px; max-width: 620px; margin: 0 auto 36px; }
  .hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
  .btn-primary, .btn-ghost2 {
    padding: 13px 30px; border-radius: 9px; font-size: 15px; font-weight: 700; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all .15s;
  }
  .btn-primary { background: linear-gradient(135deg, #ff7a63, #ff5742); color: #fff; border: none; box-shadow: 0 6px 22px rgba(255,87,66,.35); }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 26px rgba(255,87,66,.45); }
  .btn-ghost2 { background: var(--surface2); color: var(--text); border: 1px solid var(--border-strong); }
  .btn-ghost2:hover { border-color: var(--faint); }
  .trust { margin-top: 42px; display: flex; gap: 40px; justify-content: center; flex-wrap: wrap; }
  .trust div { text-align: center; }
  .trust b { display: block; font-size: 21px; color: #fff; }
  .trust span { font-size: 12.5px; color: var(--faint); }

  /* ===== Sections ===== */
  section { padding: 76px 0; }
  .kicker { color: #ff8a73; font-size: 13px; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; margin-bottom: 10px; }
  h2 { font-size: 28px; letter-spacing: -.3px; margin-bottom: 12px; color: var(--text); }
  .section-sub { color: var(--dim); max-width: 600px; margin-bottom: 38px; }

  /* Services */
  .services-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
  @media (max-width: 900px) { .services-grid { grid-template-columns: 1fr; } }
  .service {
    position: relative;
    background: linear-gradient(180deg, var(--surface2), var(--surface));
    border: 1px solid var(--border-strong); border-radius: 13px;
    padding: 26px; transition: all .18s;
  }
  .service::before {
    content: ""; position: absolute; inset: 0 auto auto 0; width: 44px; height: 3px;
    background: var(--brand); border-radius: 0 0 3px 0;
  }
  .service:hover { transform: translateY(-3px); box-shadow: 0 14px 34px rgba(0,0,0,.45); border-color: #4a5570; }
  .service .ico { font-size: 26px; margin-bottom: 14px; }
  .service h3 { font-size: 16.5px; margin-bottom: 8px; color: #fff; }
  .service p { color: #aab4c6; font-size: 13.5px; }

  /* Approach */
  .approach { background: var(--surface); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
  .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; counter-reset: step; }
  @media (max-width: 900px) { .steps { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 620px) { .steps { grid-template-columns: 1fr; } }
  .step::before {
    counter-increment: step; content: "0" counter(step);
    display: block; font-size: 13px; font-weight: 800; color: var(--brand); margin-bottom: 10px;
  }
  .step h3 { font-size: 15px; margin-bottom: 6px; color: #fff; }
  .step p { color: var(--dim); font-size: 13px; }

  /* ===== Inquiry ===== */
  .inquiry-card {
    position: relative;
    background: linear-gradient(180deg, #1d2534, var(--surface));
    border: 1px solid #46516c; border-radius: 18px;
    padding: 32px 30px;
    box-shadow: 0 24px 60px rgba(0,0,0,.5);
  }
  .inquiry-card::before {
    content: ""; position: absolute; inset: 0 0 auto 0; height: 4px;
    background: linear-gradient(90deg, #ff8a5a, #ff5742, #ff8a5a);
    border-radius: 18px 18px 0 0;
  }
  .reply-chip {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(61,219,160,.1); color: #6ee7bd; border: 1px solid rgba(61,219,160,.35);
    font-size: 12.5px; font-weight: 600; padding: 6px 14px; border-radius: 999px; margin-bottom: 16px;
  }
  .benefits { list-style: none; margin: 0 0 20px; padding: 0; }
  .benefits li { display: flex; gap: 9px; align-items: baseline; color: #cfd6e4; font-size: 13.5px; padding: 5px 0; }
  .benefits li::before { content: "✓"; color: var(--ok); font-weight: 800; }

  label { display: block; font-size: 12.5px; color: #cbd3e1; margin: 15px 0 5px; font-weight: 600; letter-spacing: .2px; }
  input, textarea {
    width: 100%; background: #0d1016; border: 1px solid #39435a; color: #fff;
    border-radius: 9px; padding: 12px 14px; font-size: 14.5px; font-family: inherit; outline: none; transition: border-color .15s, box-shadow .15s;
  }
  input::placeholder, textarea::placeholder { color: #5d6678; }
  input:focus, textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(255,109,90,.16); }
  textarea { resize: vertical; min-height: 110px; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  @media (max-width: 520px) { .row2 { grid-template-columns: 1fr; } }
  .captcha-row { display: flex; gap: 10px; align-items: stretch; }
  .captcha-q {
    background: var(--surface2); border: 1px solid var(--border-strong); border-radius: 9px;
    padding: 11px 16px; font-weight: 800; white-space: nowrap; user-select: none; letter-spacing: 2px; color: #ffd9d2;
  }
  button.submit {
    width: 100%; margin-top: 24px; background: linear-gradient(135deg, #ff7a63, #ff5742); color: #fff; border: none;
    border-radius: 10px; padding: 15px; font-size: 15.5px; font-weight: 800; cursor: pointer; transition: all .15s;
    box-shadow: 0 8px 24px rgba(255,87,66,.3);
  }
  button.submit:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(255,87,66,.42); }
  button.submit:disabled { opacity: .55; cursor: wait; transform: none; }
  .msg { display: none; margin-top: 14px; padding: 12px 16px; border-radius: 9px; font-size: 13.5px; font-weight: 500; }
  .msg.ok { display: block; background: rgba(61,219,153,.1); border: 1px solid rgba(61,219,153,.4); color: var(--ok); }
  .msg.err { display: block; background: rgba(255,107,107,.1); border: 1px solid rgba(255,107,107,.4); color: #ff8a8a; }
  .privacy-note {
    display: flex; gap: 8px; align-items: baseline;
    color: var(--faint); font-size: 12px; margin-top: 16px;
  }

  .map-frame {
    border-radius: 14px; overflow: hidden; border: 1px solid var(--border-strong);
    box-shadow: 0 18px 44px rgba(0,0,0,.45); line-height: 0;
  }
  .map-frame iframe { width: 100%; height: 300px; border: 0; filter: saturate(.9); }
  .contact-line { display: flex; align-items: baseline; gap: 10px; padding: 11px 2px; border-bottom: 1px dashed #333d52; font-size: 14.5px; }
  .contact-line:last-of-type { border-bottom: none; }
  .contact-line span.k { color: var(--faint); min-width: 92px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .8px; font-weight: 700; }
  .contact-line a { color: #8abaff; text-decoration: none; word-break: break-all; font-weight: 500; }
  .contact-line a:hover { text-decoration: underline; }

  footer { border-top: 1px solid var(--border); padding: 26px 0; text-align: center; color: var(--faint); font-size: 12.5px; background: #05070a; }
  @media (max-width: 960px) { .inq-grid { grid-template-columns: 1fr !important; } }
</style>
</head>
<body>

<header class="hero">
  <div class="container">
    <span class="badge-pill">● Available for new projects</span>
    <h1>Your website should be your best salesperson.<br><em>I make sure it is.</em></h1>
    <p class="hero-sub">
      I'm <strong><?= esc($name) ?></strong> — a web developer and SEO specialist from <?= esc($location) ?>.
      I build websites that load fast, stay secure, and climb the search rankings on purpose, not by luck.
    </p>
    <div class="hero-actions">
      <a href="#inquiry" class="btn-primary">Start a project →</a>
      <?php if ($whatsapp): ?><a class="btn-ghost2" href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $whatsapp) ?>" target="_blank" rel="noopener">💬 WhatsApp me</a><?php endif; ?>
    </div>
    <div class="trust">
      <div><b>Technical</b><span>SEO foundations done right</span></div>
      <div><b>Data-driven</b><span>Every change is measurable</span></div>
      <div><b>Long-term</b><span>Growth over quick tricks</span></div>
    </div>
  </div>
</header>

<section id="services">
  <div class="container">
    <div class="kicker">Services</div>
    <h2>What I can do for you</h2>
    <p class="section-sub">One person, full stack of skills — so nothing gets lost between developer and marketer.</p>

    <div class="services-grid">
      <div class="service"><div class="ico">⚙️</div><h3>Web Development</h3><p>Custom websites and business systems built clean under the hood: fast, maintainable, and easy to grow with.</p></div>
      <div class="service"><div class="ico">🔍</div><h3>Technical SEO</h3><p>Crawlability, site architecture, Core Web Vitals, structured data — the invisible work that makes everything else possible.</p></div>
      <div class="service"><div class="ico">📄</div><h3>On-Page SEO</h3><p>Keyword research with real intent, content structure that answers questions, and metadata that earns the click.</p></div>
      <div class="service"><div class="ico">🔗</div><h3>Off-Page SEO</h3><p>Sustainable link building and brand signals that build authority without gambling your domain's reputation.</p></div>
      <div class="service"><div class="ico">🤖</div><h3>Automation & AI</h3><p>Workflow automation and AI-powered content pipelines that save your team hours every single week.</p></div>
      <div class="service"><div class="ico">📈</div><h3>SEO Audits & Consulting</h3><p>A clear, prioritized roadmap of what's holding your site back — and exactly what to fix first.</p></div>
    </div>
  </div>
</section>

<section class="approach" id="approach">
  <div class="container">
    <div class="kicker">How I work</div>
    <h2>A process, not guesswork</h2>
    <p class="section-sub">Whether it's a new website or rescuing one from page five of Google, the approach stays the same.</p>
    <div class="steps">
      <div class="step"><h3>Discover</h3><p>We map your goals, audience, and what "success" actually looks like in numbers.</p></div>
      <div class="step"><h3>Audit & Plan</h3><p>Deep technical and content analysis produces a prioritized, no-fluff action plan.</p></div>
      <div class="step"><h3>Build & Optimize</h3><p>Development and SEO executed together — each sprint shipped and verified.</p></div>
      <div class="step"><h3>Measure & Grow</h3><p>Rankings, traffic, and conversions tracked openly. We double down on what works.</p></div>
    </div>
  </div>
</section>

<section id="inquiry">
  <div class="container">
    <div class="kicker">Contact</div>
    <h2>Tell me about your project</h2>
    <p class="section-sub" style="margin-bottom:30px">No obligation, no sales pressure. Just an honest assessment of how I can help.</p>

    <div style="display:grid; grid-template-columns: 7fr 5fr; gap:26px; align-items:start;" class="inq-grid">
      <!-- FORM -->
      <div class="inquiry-card">
        <span class="reply-chip">⚡ Usually replies within one business day</span>
        <ul class="benefits">
          <li>Free initial consultation — no commitment required</li>
          <li>Honest assessment: if SEO isn't your problem, I'll say so</li>
          <li>Clear pricing after we scope the work together</li>
        </ul>

        <form id="inq-form">
          <label>Name *</label>
          <input name="name" required maxlength="190" placeholder="Your name or company" />
          <div class="row2">
            <div>
              <label>Email *</label>
              <input name="email" type="email" required maxlength="190" placeholder="you@company.com" />
            </div>
            <div>
              <label>WhatsApp / Phone <span style="color:#7a8499">(optional)</span></label>
              <input name="phone" maxlength="32" placeholder="+62 …" />
            </div>
          </div>
          <label>Project details *</label>
          <textarea name="message" required minlength="10" maxlength="5000" placeholder="What are you building, and what does success look like? The more detail, the better my first answer will be."></textarea>

          <!-- Honeypot -->
          <div style="position:absolute;left:-9999px;" aria-hidden="true">
            <label>Website</label><input name="website" tabindex="-1" autocomplete="off" />
          </div>

          <div id="captcha-box">
            <label>Quick human check *</label>
            <div class="captcha-row">
              <span class="captcha-q" id="cap-q">…</span>
              <input name="captcha_answer" id="cap-answer" placeholder="Answer" inputmode="numeric" />
            </div>
          </div>
          <button class="submit" id="btn" type="submit">Send inquiry →</button>
          <div class="msg" id="msg"></div>
          <p class="privacy-note">🔒 Your details are only used to reply to this inquiry. Protected by rate limiting and spam filtering.</p>
        </form>
      </div>

      <!-- MAP + CONTACT -->
      <div>
        <div class="map-frame">
          <iframe src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d3954.41264099521!2d110.8159495!3d-7.6386959!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e7a3da40ed41c69%3A0x2601c6922ac80dd4!2sJuki%20Website%20Developer%20Solo!5e0!3m2!1sid!2sid!4v1787544684990!5m2!1sid!2sid" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Office location"></iframe>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border-strong); border-radius:12px; padding:8px 18px; margin-top:16px;">
          <div class="contact-line"><span class="k">Location</span><span><?= esc($location) ?></span></div>
          <?php if ($email): ?><div class="contact-line"><span class="k">Email</span><a href="mailto:<?= esc($email) ?>"><?= esc($email) ?></a></div><?php endif; ?>
          <?php if ($whatsapp): ?><div class="contact-line"><span class="k">WhatsApp</span><a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $whatsapp) ?>" target="_blank" rel="noopener"><?= esc($whatsapp) ?></a></div><?php endif; ?>
          <?php if ($website): ?><div class="contact-line"><span class="k">Website</span><a href="<?= esc($website, 'url') ?>" target="_blank" rel="noopener nofollow"><?= esc($website) ?></a></div><?php endif; ?>
        </div>
        <p style="color:var(--dim); font-size:13.5px; margin-top:18px;">
          Prefer talking face-to-face? The map shows where I'm based in <?= esc($location) ?> —
          meetings by appointment, coffee's on me ☕
        </p>
      </div>
    </div>
  </div>
</section>

<footer>
  © <?= date('Y') ?> <?= esc($name) ?> · <?= esc($location) ?> · Web Development & SEO
</footer>

<script>
(function () {
  var token = '';

  function loadCaptcha() {
    fetch('/api/public/captcha').then(function (r) { return r.json(); }).then(function (res) {
      var d = res.data || {};
      if (d.mode === 'recaptcha') return;
      token = d.token || '';
      document.getElementById('cap-q').textContent = d.question || '';
    });
  }
  loadCaptcha();

  document.getElementById('inq-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    var btn = document.getElementById('btn');
    var msg = document.getElementById('msg');
    msg.className = 'msg';
    btn.disabled = true;

    fetch('/api/public/inquiry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: f.name.value,
        email: f.email.value,
        phone: f.phone.value,
        message: f.message.value,
        website: f.website.value,
        captcha_token: token,
        captcha_answer: f.captcha_answer ? f.captcha_answer.value : '',
        recaptcha_token: window.grecaptcha ? grecaptcha.getResponse() : ''
      })
    }).then(function (r) { return r.json(); }).then(function (res) {
      msg.textContent = res.success ? (res.message || 'Sent.') : (res.message || 'Failed.');
      msg.className = 'msg ' + (res.success ? 'ok' : 'err');
      if (res.success) { f.reset(); }
      loadCaptcha();
    }).catch(function () {
      msg.textContent = 'Connection failed. Please try again.';
      msg.className = 'msg err';
    }).finally(function () { btn.disabled = false; });
  });
})();
</script>
</body>
</html>
