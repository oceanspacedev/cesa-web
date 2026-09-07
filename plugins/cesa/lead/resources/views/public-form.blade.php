<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $config['i18n']['title'] ?? 'Lead Form' }}</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}" type="image/x-icon">

    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @if (! empty($config['recaptcha']['enabled']) && ! empty($config['recaptcha']['siteKey']))
        <script src="https://www.google.com/recaptcha/api.js?render={{ $config['recaptcha']['siteKey'] }}" defer></script>
    @endif

    @vite(['resources/css/app.css', 'plugins/cesa/lead/resources/js/public-lead.js'])
</head>
<body class="min-h-screen bg-[#EFF6FF] text-gray-900 font-sans antialiased">
    <script>
        window.__LEAD_CONFIG__ = @json($config);
    </script>

    <div id="lead-public-form"></div>
</body>
</html>
