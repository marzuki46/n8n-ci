<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Halaman Tidak Ditemukan</title>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{
            min-height:100vh;
            display:flex;align-items:center;justify-content:center;
            background:linear-gradient(135deg,#0f0f23 0%,#1a1a3e 50%,#0f0f23 100%);
            font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            color:#e2e8f0;
            overflow:hidden;
        }
        .bg-grid{
            position:fixed;inset:0;
            background-image:
                linear-gradient(rgba(99,102,241,.06) 1px,transparent 1px),
                linear-gradient(90deg,rgba(99,102,241,.06) 1px,transparent 1px);
            background-size:60px 60px;
            pointer-events:none;
        }
        .bg-glow{
            position:fixed;
            width:600px;height:600px;
            border-radius:50%;
            filter:blur(120px);
            opacity:.12;
            pointer-events:none;
        }
        .bg-glow.a{background:#6366f1;top:-200px;left:-100px}
        .bg-glow.b{background:#ec4899;bottom:-200px;right:-100px}
        .container{
            position:relative;z-index:1;
            text-align:center;
            padding:2rem;
            max-width:560px;
        }
        .code{
            font-size:clamp(6rem,15vw,10rem);
            font-weight:800;
            line-height:1;
            background:linear-gradient(135deg,#6366f1,#a78bfa,#ec4899);
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            margin-bottom:1rem;
            user-select:none;
        }
        .line{
            width:80px;height:3px;
            background:linear-gradient(90deg,#6366f1,#ec4899);
            border-radius:2px;
            margin:0 auto 1.5rem;
        }
        h1{
            font-size:1.5rem;
            font-weight:700;
            margin-bottom:.5rem;
            color:#f8fafc;
        }
        p{
            font-size:1rem;
            color:#94a3b8;
            line-height:1.6;
            margin-bottom:2rem;
        }
        .btn{
            display:inline-flex;align-items:center;gap:.5rem;
            padding:.75rem 1.75rem;
            border-radius:8px;
            text-decoration:none;
            font-weight:600;
            font-size:.95rem;
            transition:all .2s;
        }
        .btn-primary{
            background:linear-gradient(135deg,#6366f1,#818cf8);
            color:#fff;
            box-shadow:0 4px 15px rgba(99,102,241,.3);
        }
        .btn-primary:hover{
            transform:translateY(-1px);
            box-shadow:0 6px 20px rgba(99,102,241,.4);
        }
        .btn-ghost{
            border:1px solid rgba(148,163,184,.2);
            color:#94a3b8;
            margin-left:.75rem;
        }
        .btn-ghost:hover{
            border-color:rgba(148,163,184,.4);
            color:#e2e8f0;
        }
        .hint{
            margin-top:2.5rem;
            padding-top:1.5rem;
            border-top:1px solid rgba(148,163,184,.1);
            font-size:.8rem;
            color:#475569;
        }
        .hint code{
            background:rgba(99,102,241,.1);
            padding:.15rem .4rem;
            border-radius:4px;
            font-family:'JetBrains Mono',monospace;
            font-size:.78rem;
            color:#a78bfa;
        }
        @media(max-width:480px){
            .btn-ghost{margin-left:0;margin-top:.5rem}
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="bg-glow a"></div>
    <div class="bg-glow b"></div>
    <div class="container">
        <div class="code">404</div>
        <div class="line"></div>
        <h1>Halaman Tidak Ditemukan</h1>
        <p>
            <?php if (! empty($message)) : ?>
                <?= $message ?>
            <?php else : ?>
                Sepertinya halaman yang kamu cari sudah dipindahkan atau tidak tersedia.
            <?php endif; ?>
        </p>
        <a href="/" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/></svg>
            Kembali ke Beranda
        </a>
        <a href="javascript:history.back()" class="btn btn-ghost">Halaman Sebelumnya</a>
        <div class="hint">
            <strong>n8n-CI</strong> &mdash; Workflow Automation Indonesia<br>
            Butuh bantuan? Hubungi admin atau kunjungi <code>/api</code> untuk dokumentasi API.
        </div>
    </div>
</body>
</html>
