<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    {{-- Primary Meta --}}
    <title>{{ $title ?? "Dj Smith Movies" }}</title>
    <meta name="description" content="{{ $description ?? 'Watch all your favourite Dj Smith Movies online for a monthly subscription.' }}">

    {{-- Open Graph / Facebook --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title ?? 'Dj Smith Movies' }}">
    <meta property="og:description" content="{{ $description ?? 'Watch all your favourite Dj Smith Movies online.' }}">
    <meta property="og:image" content="{{ asset('fav.png') }}">
    <meta property="og:url" content="{{ url()->current() }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title ?? 'Dj Smith Movies' }}">
    <meta name="twitter:description" content="{{ $description ?? 'Watch all your favourite Dj Smith Movies online.' }}">
    <meta name="twitter:image" content="{{ asset('fav.png') }}">

    {{-- Icons --}}
    <link rel="icon" type="image/png" href="{{ asset('fav.png') }}">
    <link rel="apple-touch-icon" type="image/png" href="{{ asset('fav.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-900 text-white font-sans antialiased">
    <livewire:header />
    {{ $slot }}

    {{-- Footer – hidden on pages where $hideFooter is true --}}
    @unless(isset($hideFooter) && $hideFooter)
        <livewire:footer />
    @endunless

    @livewireScripts
</body>
</html>
