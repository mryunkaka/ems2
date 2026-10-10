<?php
$targetUrl = 'http://roxwoodhospitalime.my.id/';
$pageTitle = 'Website Roxwood Pindah';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg-deep: #05131f;
            --bg-mid: #0a2740;
            --bg-soft: #eef6fb;
            --panel: rgba(255, 255, 255, 0.9);
            --panel-strong: rgba(255, 255, 255, 0.98);
            --line: rgba(148, 163, 184, 0.24);
            --text: #0f172a;
            --muted: #5b6b82;
            --primary: #0f7ae5;
            --primary-deep: #0958aa;
            --accent: #12b981;
            --accent-soft: rgba(18, 185, 129, 0.14);
            --shadow: 0 24px 80px rgba(3, 15, 28, 0.22);
            --radius-xl: 32px;
            --radius-lg: 24px;
            --radius-md: 18px;
            --radius-sm: 14px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(24, 119, 242, 0.28), transparent 28%),
                radial-gradient(circle at bottom right, rgba(18, 185, 129, 0.16), transparent 24%),
                linear-gradient(145deg, #04101b 0%, #0c2f4e 45%, #d8eaf6 100%);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            inset: auto;
            border-radius: 999px;
            filter: blur(24px);
            opacity: 0.55;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            width: 360px;
            height: 360px;
            top: -110px;
            right: -100px;
            background: rgba(47, 150, 255, 0.26);
        }

        body::after {
            width: 280px;
            height: 280px;
            bottom: -80px;
            left: -70px;
            background: rgba(18, 185, 129, 0.18);
        }

        .shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 36px 18px;
        }

        .frame {
            width: min(1120px, 100%);
            display: grid;
            grid-template-columns: 420px minmax(0, 1fr);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(16px);
        }

        .hero {
            position: relative;
            padding: 38px 34px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0)),
                linear-gradient(160deg, #051523 0%, #0a2f4c 52%, #0e4970 100%);
            color: #fff;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0 8%, transparent 8% 100%),
                radial-gradient(circle at 82% 22%, rgba(255, 255, 255, 0.14), transparent 24%),
                radial-gradient(circle at 18% 85%, rgba(18, 185, 129, 0.15), transparent 20%);
            pointer-events: none;
        }

        .hero > * {
            position: relative;
            z-index: 1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .badge::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #34d399;
            box-shadow: 0 0 0 5px rgba(52, 211, 153, 0.15);
        }

        .brand {
            display: flex;
            gap: 18px;
            align-items: center;
            margin: 26px 0 22px;
        }

        .logo-wrap {
            width: 86px;
            height: 86px;
            border-radius: 28px;
            display: grid;
            place-items: center;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(232, 244, 255, 0.92));
            box-shadow: 0 18px 36px rgba(3, 15, 28, 0.25);
            flex-shrink: 0;
        }

        .brand small {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            color: rgba(226, 232, 240, 0.9);
        }

        .brand h1 {
            margin: 0;
            font-size: 36px;
            line-height: 1.04;
            letter-spacing: -0.04em;
        }

        .hero-copy {
            margin: 0 0 28px;
            max-width: 32ch;
            color: rgba(226, 232, 240, 0.92);
            font-size: 15px;
            line-height: 1.75;
        }

        .hero-cards {
            display: grid;
            gap: 14px;
        }

        .hero-card {
            padding: 16px 18px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .hero-card span {
            display: block;
            margin-bottom: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(186, 230, 253, 0.82);
        }

        .hero-card strong,
        .hero-card p {
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .hero-card strong {
            font-size: 16px;
            color: #fff;
        }

        .panel {
            padding: 42px 38px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 250, 252, 0.95));
        }

        .eyebrow {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eaf4ff;
            color: var(--primary-deep);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .panel h2 {
            margin: 18px 0 10px;
            font-size: 38px;
            line-height: 1.08;
            letter-spacing: -0.04em;
            color: #0b1628;
        }

        .lead {
            margin: 0;
            max-width: 58ch;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.8;
        }

        .notice {
            margin-top: 28px;
            padding: 18px 20px;
            border-radius: var(--radius-md);
            border: 1px solid #cfe4fb;
            background: linear-gradient(180deg, #f5fbff, #eef7ff);
            color: #19426a;
            font-size: 14px;
            line-height: 1.7;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 22px;
        }

        .stat {
            padding: 18px 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--line);
            background: var(--panel-strong);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }

        .stat.label-green {
            background: linear-gradient(180deg, #f0fdf7, #ebfbf3);
            border-color: rgba(16, 185, 129, 0.22);
        }

        .stat span {
            display: block;
            margin-bottom: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7b91;
        }

        .stat strong {
            display: block;
            font-size: 20px;
            line-height: 1.3;
            color: #101828;
        }

        .link-box {
            margin-top: 22px;
            padding: 24px;
            border-radius: 26px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(245, 249, 253, 0.96));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }

        .link-box span {
            display: block;
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #708198;
        }

        .link-box a {
            color: var(--primary-deep);
            font-size: 20px;
            font-weight: 800;
            line-height: 1.5;
            text-decoration-thickness: 2px;
            word-break: break-word;
        }

        .link-box p {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 28px;
        }

        .btn {
            appearance: none;
            border: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 54px;
            padding: 0 22px;
            border-radius: 18px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.01em;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, #0f7ae5, #0a62bf);
            box-shadow: 0 18px 32px rgba(15, 122, 229, 0.26);
        }

        .btn-secondary {
            color: #0d3d72;
            background: #eef6ff;
            border: 1px solid #cfe4fb;
        }

        .footnote {
            margin-top: 20px;
            color: #6a7a90;
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 940px) {
            .frame {
                grid-template-columns: 1fr;
            }

            .hero,
            .panel {
                padding: 30px 22px;
            }

            .brand h1,
            .panel h2 {
                font-size: 30px;
            }
        }

        @media (max-width: 640px) {
            .shell {
                padding: 16px;
            }

            .frame {
                border-radius: 26px;
            }

            .brand {
                align-items: flex-start;
            }

            .brand h1,
            .panel h2 {
                font-size: 26px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .btn {
                width: 100%;
            }

            .link-box a {
                font-size: 17px;
            }
        }
    </style>
</head>

<body>
    <main class="shell">
        <section class="frame">
            <aside class="hero">
                <div class="badge">Portal Baru Aktif</div>

                <div class="brand">
                    <div class="logo-wrap" aria-hidden="true">
                        <svg width="54" height="54" viewBox="0 0 54 54" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="4" y="4" width="46" height="46" rx="16" fill="url(#g1)" />
                            <path d="M18 18H27.5C33.299 18 38 22.701 38 28.5C38 34.299 33.299 39 27.5 39H18V18Z" fill="white" />
                            <path d="M22 22V35H27C30.5899 35 33.5 32.0899 33.5 28.5C33.5 24.9101 30.5899 22 27 22H22Z" fill="#0A62BF" />
                            <path d="M26.5 12L29.1538 17.8462L35 20.5L29.1538 23.1538L26.5 29L23.8462 23.1538L18 20.5L23.8462 17.8462L26.5 12Z" fill="#7DD3FC" />
                            <defs>
                                <linearGradient id="g1" x1="27" y1="4" x2="27" y2="50" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#0F7AE5" />
                                    <stop offset="1" stop-color="#083B73" />
                                </linearGradient>
                            </defs>
                        </svg>
                    </div>
                    <div>
                        <small>Roxwood Hospital</small>
                        <h1>Website Sudah Pindah</h1>
                    </div>
                </div>

                <p class="hero-copy">
                    Portal lama tidak lagi dipakai sebagai akses utama. Untuk masuk ke website terbaru, gunakan domain baru yang sudah disiapkan.
                </p>

                <div class="hero-cards">
                    <div class="hero-card">
                        <span>Domain Baru</span>
                        <strong>roxwoodhospitalime.my.id</strong>
                    </div>
                    <div class="hero-card">
                        <span>Cara Akses</span>
                        <p>Klik tombol di sisi kanan untuk membuka portal baru secara manual.</p>
                    </div>
                </div>
            </aside>

            <section class="panel">
                <span class="eyebrow">Informasi Perpindahan</span>
                <h2>Lanjut ke Website Baru Roxwood</h2>
                <p class="lead">
                    Alamat website telah diperbarui. Silakan gunakan tautan resmi berikut untuk membuka portal terbaru. Halaman ini sengaja tidak melakukan redirect otomatis.
                </p>

                <div class="notice">
                    Domain lama hanya dipakai sebagai halaman pemberitahuan. Jika Anda menyimpan bookmark atau shortcut sebelumnya, arahkan ke domain baru agar akses berikutnya lebih cepat.
                </div>

                <div class="stats">
                    <div class="stat">
                        <span>Status</span>
                        <strong>Portal Baru Siap Digunakan</strong>
                    </div>
                    <div class="stat label-green">
                        <span>Akses</span>
                        <strong>Klik Manual oleh Pengguna</strong>
                    </div>
                </div>

                <div class="link-box">
                    <span>Tautan Resmi</span>
                    <a
                        href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>"
                        target="_blank"
                        rel="noopener noreferrer"><?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?></a>
                    <p>Tautan ini akan membuka website Roxwood pada domain yang baru.</p>
                </div>

                <div class="actions">
                    <a
                        href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>"
                        class="btn btn-primary"
                        target="_blank"
                        rel="noopener noreferrer">Buka Website Baru</a>
                    <a
                        href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>"
                        class="btn btn-secondary"
                        target="_blank"
                        rel="noopener noreferrer">Buka Link Cadangan</a>
                </div>

                <p class="footnote">
                    Jika website baru tidak terbuka, salin alamat domain di atas lalu buka manual di browser Anda.
                </p>
            </section>
        </section>
    </main>
</body>

</html>
