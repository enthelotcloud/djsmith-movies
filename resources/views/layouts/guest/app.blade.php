<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    {{-- PWA settings --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#dc2626">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Dj Smith Movies">

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
    <script>
        // 1. Register Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(registration => console.log('PWA ServiceWorker registered'))
                    .catch(err => console.log('PWA ServiceWorker failed: ', err));
            });
        }

        // 2. Smart Notification Permission Request
        // Browsers require a user interaction to ask for notifications.
        // This script waits for the user to click anywhere on the page for the first time,
        // then politely asks for permission and saves their choice so it doesn't bug them again.
        document.addEventListener('click', function requestNotification() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().then(permission => {
                    if (permission === 'granted') {
                        console.log('Push notifications enabled by user.');
                        // In the future, you would send the push subscription to Laravel here
                    }
                });
            }
            // Remove the event listener so it only triggers once
            document.removeEventListener('click', requestNotification);
        }, { once: true });
    </script>
    @livewireScripts
</body>
</html>
