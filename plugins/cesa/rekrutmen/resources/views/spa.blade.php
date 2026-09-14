<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50 text-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Rekrutmen - CESA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
    </style>
    @vite(['resources/css/app.css', 'plugins/cesa/rekrutmen/resources/js/app.js'])
</head>
<body class="h-full antialiased bg-slate-50 text-slate-900 overflow-x-hidden">
    <div id="rekrutmen-app" data-user="{{ json_encode($user) }}" data-plugins="{{ json_encode($plugins ?? []) }}"></div>
</body>
</html>
