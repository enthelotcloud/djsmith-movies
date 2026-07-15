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
    {{-- Anti-piracy protection --}}
    <script>
        // Detect and block common download patterns
        (function() {
            // Block right-click on video elements
            document.addEventListener('DOMContentLoaded', function() {
                document.addEventListener('contextmenu', function(e) {
                    if (e.target.tagName === 'VIDEO' || e.target.closest('video')) {
                        e.preventDefault();
                        console.log('%c🚫 Right-click disabled on videos!', 'color: red; font-weight: bold;');
                        return false;
                    }
                });
            });

            // Detect download manager extensions
            const checkForDownloaders = setInterval(() => {
                if (window.external && window.external.AddSearchProvider) {
                    // Possible IDM detection
                    console.clear();
                }
            }, 2000);

            // Disable print screen (partially effective)
            window.addEventListener('keyup', function(e) {
                if (e.key === 'PrintScreen') {
                    navigator.clipboard.writeText('');
                    alert('Screenshots are not allowed on this content! 🚫');
                }
            });
        })();
    </script>
</head>
<body>
    <livewire:header />
    {{ $slot }}
    <livewire:footer />
    @livewireScripts
</body>
</html>
