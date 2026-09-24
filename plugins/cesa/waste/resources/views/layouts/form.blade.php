<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? __('waste::waste.public_title') }}</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}" type="image/x-icon">
    @vite(['resources/css/filament/public/theme.css', 'plugins/cesa/waste/resources/js/public-waste.js'])
    @filamentStyles
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-50 cesa-public waste-public">
    {{ $slot }}
    @livewireScriptConfig
    @filamentScripts(withCore: true)
    @stack('scripts')
</body>
</html>
