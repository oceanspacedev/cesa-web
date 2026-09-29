<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="CESA — Portal Aplikasi Internal Complete Selular">
    <title>CESA — Portal Aplikasi Internal</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #EFF6FF;
            color: #111827;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ===== LAYOUT ===== */
        .page-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-top: 32px;
            padding-bottom: 32px;
        }

        .container {
            width: 100%;
            max-width: 896px; /* max-w-4xl */
            margin: 0 auto;
            padding-left: 16px;
            padding-right: 16px;
        }

        /* ===== NAV BAR ===== */
        .nav-bar {
            padding-top: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #E5E7EB;
            background: #fff;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .nav-brand-logo {
            width: 36px;
            height: 36px;
            background: #2563EB;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            flex-shrink: 0;
        }

        .nav-brand-name {
            font-size: 15px;
            font-weight: 700;
            color: #1F2937;
            letter-spacing: -0.2px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: #2563EB;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1D4ED8;
        }

        .btn-ghost {
            background: #fff;
            color: #374151;
            border: 1px solid #D1D5DB;
        }

        .btn-ghost:hover {
            background: #F3F4F6;
        }

        .btn svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        /* ===== APPS GRID ===== */
        .apps-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
        }

        /* ===== APP CARD (icon tile) ===== */
        .app-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px 12px 16px;
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: background-color 0.15s ease, border-color 0.15s ease;
            cursor: pointer;
        }

        .app-card:hover {
            background-color: #F9FAFB;
            border-color: #D1D5DB;
        }

        /* Icon */
        .app-card-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .app-card-icon svg {
            width: 26px;
            height: 26px;
            color: #fff;
        }

        .app-card-image {
            width: 52px;
            height: 52px;
            object-fit: contain;
            flex-shrink: 0;
        }

        /* Name */
        .app-card-name {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            text-align: center;
            line-height: 1.3;
            word-break: break-word;
            transition: color 0.15s ease;
        }

        .app-card:hover .app-card-name {
            color: #111827;
        }

        /* Icon color variants */
        .ic-blue    { background: #2563EB; }
        .ic-teal    { background: #0D9488; }
        .ic-purple  { background: #7C3AED; }
        .ic-pink    { background: #DB2777; }
        .ic-green   { background: #16A34A; }
        .ic-yellow  { background: #D97706; }
        .ic-red     { background: #DC2626; }
        .ic-slate   { background: #64748B; }
        .ic-orange  { background: #EA580C; }
        .ic-amber   { background: #CA8A04; }
        .ic-indigo  { background: #4F46E5; }
        .ic-navy    { background: #1E3A8A; }
        .ic-sky     { background: #0284C7; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 48px 24px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        .empty-icon {
            width: 48px;
            height: 48px;
            background: #F3F4F6;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .empty-icon svg {
            width: 24px;
            height: 24px;
            color: #9CA3AF;
        }

        .empty-title {
            font-size: 15px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
        }

        .empty-desc {
            font-size: 13px;
            color: #6B7280;
            max-width: 300px;
            margin: 0 auto 16px;
        }

        /* ===== FOOTER ===== */
        .footer {
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-top: 1px solid #E5E7EB;
        }

        .footer-text {
            font-size: 12px;
            color: #6B7280;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 960px) {
            .apps-grid { grid-template-columns: repeat(4, 1fr); }
        }

        @media (max-width: 640px) {
            .apps-grid { grid-template-columns: repeat(3, 1fr); gap: 12px; }
            .app-card { padding: 16px 8px 12px; }
            .app-card-icon { width: 44px; height: 44px; border-radius: 12px; }
            .app-card-icon svg { width: 22px; height: 22px; }
            .app-card-image { width: 44px; height: 44px; }
            .app-card-name { font-size: 12px; }
            .nav-brand-name { display: none; }
        }

        .local-network-dialog {
            position: fixed;
            inset: 0;
            height: fit-content;
            margin: auto;
            border: none;
            border-radius: 12px;
            padding: 24px;
            max-width: 420px;
            width: calc(100% - 32px);
            color: #111827;
            box-shadow: 0 20px 40px rgba(17, 24, 39, 0.18);
        }

        .local-network-dialog::backdrop {
            background: rgba(17, 24, 39, 0.45);
        }

        .local-network-dialog h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .local-network-dialog p {
            font-size: 14px;
            line-height: 1.5;
            color: #4B5563;
        }

        .local-network-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <!-- HEADER -->
    <header class="nav-bar">
        <div class="container" style="display:flex;align-items:center;justify-content:space-between;">
            <a href="/" class="nav-brand">
                @if(file_exists(public_path('images/logo.png')))
                    <img src="{{ asset('images/logo.png') }}" alt="CESA" style="height:36px;width:auto;border-radius:8px;">
                @else
                    <div class="nav-brand-logo">C</div>
                    <span class="nav-brand-name">CESA</span>
                @endif
            </a>

            <div class="nav-actions">
                @auth
                    <a href="/admin" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
                        </svg>
                        Dashboard
                    </a>
                @else
                    <a href="/admin/login" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        Masuk
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <!-- MAIN (centered) -->
    <main class="main-content">
        <div class="container">
            @if(count($apps) > 0)
                <div class="apps-grid">
                    @foreach($apps as $app)
                        @php
                            $isExternal = false;
                            if (! empty($app['url'])) {
                                $urlHost = parse_url($app['url'], PHP_URL_HOST);
                                if ($urlHost && $urlHost !== request()->getHost()) {
                                    $isExternal = true;
                                }
                            }
                        @endphp
                        <a
                            href="{{ $app['url'] }}"
                            class="app-card"
                            title="{{ $app['description'] }}"
                            id="app-{{ $app['key'] }}"
                            @if ($app['local_only'] ?? false)
                                data-local-only
                            @endif
                            @if ($isExternal)
                                target="_blank"
                                rel="noopener noreferrer"
                            @endif
                        >
                            @if(file_exists(public_path('svg/' . $app['key'] . '.svg')))
                                <img src="{{ asset('svg/' . $app['key'] . '.svg') }}" class="app-card-image" alt="{{ $app['name'] }}">
                            @else
                                <div class="app-card-icon ic-{{ $app['color'] }}">
                                    @include('cesa-home-icons', ['icon' => $app['icon']])
                                </div>
                            @endif
                            <span class="app-card-name">{{ $app['name'] }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <p class="empty-title">Belum ada plugin aktif</p>
                    <p class="empty-desc">Instal plugin CESA untuk menampilkan aplikasi di sini.</p>
                    <a href="/admin/plugins" class="btn btn-primary">Kelola Plugin</a>
                </div>
            @endif
        </div>
    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <span class="footer-text">© {{ date('Y') }} IT Support — CESA</span>
    </footer>

    <dialog class="local-network-dialog" id="local-network-dialog" aria-labelledby="local-network-title">
        <h2 id="local-network-title">Harus di WiFi kantor</h2>
        <p>Odoo hanya terbuka lewat jaringan WiFi kantor. Di luar jaringan itu, halaman ini tidak akan bisa diakses.</p>
        <div class="local-network-actions">
            <button type="button" class="btn btn-ghost" id="local-network-cancel">Batal</button>
            <button type="button" class="btn btn-primary" id="local-network-continue">Buka Odoo</button>
        </div>
    </dialog>

</div>
<script>
    const localNetworkDialog = document.getElementById('local-network-dialog');
    const localNetworkContinue = document.getElementById('local-network-continue');

    document.querySelectorAll('[data-local-only]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                return;
            }

            event.preventDefault();
            localNetworkDialog.dataset.url = link.href;
            localNetworkDialog.showModal();
        });
    });

    document.getElementById('local-network-cancel').addEventListener('click', () => {
        localNetworkDialog.close();
    });

    localNetworkContinue.addEventListener('click', () => {
        const url = localNetworkDialog.dataset.url;
        localNetworkDialog.close();

        if (url) {
            window.open(url, '_blank', 'noopener');
        }
    });

    localNetworkDialog.addEventListener('click', (event) => {
        if (event.target === localNetworkDialog) {
            localNetworkDialog.close();
        }
    });
</script>
</body>
</html>

