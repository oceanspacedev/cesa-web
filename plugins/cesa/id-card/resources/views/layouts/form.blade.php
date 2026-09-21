<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('id-card::id-card.title') }}</title>
    <link rel="icon" href="{{ asset('images/favicon.ico') }}" type="image/x-icon">
    @vite('resources/css/filament/public/theme.css')
    @filamentStyles
    @livewireStyles
</head>
<body class="bg-gray-50 cesa-public">
    {{ $slot }}
    @livewireScripts
    @filamentScripts(withCore: true)
</body>
</html>
