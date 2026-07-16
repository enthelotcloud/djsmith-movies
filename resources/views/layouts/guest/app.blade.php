<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ $title ?? "Dj Smith Movies" }}</title>
    <meta name="description" content="{{ $description ?? 'Watch all your favourite Dj Smith Movies online for a monthly subscription.' }}">
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="apple-touch-icon" type="image/png" href="{{ asset('images/logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-900 text-white font-sans antialiased">
    <livewire:header />
    {{ $slot }}
    <livewire:footer />
    @livewireScripts
</body>
</html>
