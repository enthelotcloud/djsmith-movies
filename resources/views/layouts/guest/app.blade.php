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
    <meta name="vapid-public-key" content="{{ env('VAPID_PUBLIC_KEY') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

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
    {{-- <script>
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
    </script> --}}

    <script>
        // 1. Register the Service Worker on page load
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(err => {
                    console.error('Service Worker registration failed:', err);
                });
            });
        }

        // 2. Helper function to decode VAPID key
        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        // 3. The function triggered by your "Enable Notifications" button
        function subscribeToPushNotifications() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                alert('Push notifications are not supported by your browser.');
                return;
            }

            navigator.serviceWorker.ready.then((registration) => {
                const vapidPublicKey = document.querySelector('meta[name="vapid-public-key"]').getAttribute('content');
                const convertedVapidKey = urlBase64ToUint8Array(vapidPublicKey);

                // Trigger the browser permission prompt
                registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: convertedVapidKey
                }).then((subscription) => {

                    // Send subscription to Laravel backend
                    fetch('/push-subscribe', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify(subscription)
                    }).then(response => {
                        if(response.ok) {
                            alert('Notifications enabled successfully!');
                        }
                    }).catch(error => console.error('Error saving subscription:', error));

                }).catch((error) => {
                    console.error('Push subscription error: ', error);
                    if (Notification.permission === 'denied') {
                        alert('You have blocked notifications. Please allow them in your browser settings.');
                    }
                });
            });
        }
    </script>

    <!-- 📱 GLASSY PWA INSTALL & NOTIFY POPUP -->
<div x-data="pwaPrompt()" x-cloak>
    <div x-show="showModal"
         x-transition:enter="transition ease-out duration-500"
         x-transition:enter-start="opacity-0 translate-y-10 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-10 scale-95"
         class="fixed bottom-6 left-0 right-0 z-[100] mx-auto w-full max-w-[360px] px-4">

        <!-- Glassmorphism Container -->
        <div class="relative overflow-hidden rounded-2xl bg-slate-900/70 backdrop-blur-xl border border-white/10 shadow-[0_8px_32px_rgba(0,0,0,0.5)] p-6 text-center">

            <!-- STEP 1: INSTALL APP -->
            <div x-show="step === 'install'">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-500/20 mb-4 border border-blue-500/30">
                    <svg class="h-6 w-6 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </div>
                <h3 class="text-lg font-bold text-white tracking-tight mb-2">Install App</h3>
                <p class="text-sm text-slate-300 leading-relaxed mb-6">
                    Get notified when we post new movies and live events. Install our Web App for the best experience and allow notifications.
                </p>
                <div class="flex flex-col gap-3">
                    <button @click="installApp()" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white hover:bg-blue-500 transition shadow-lg shadow-blue-600/25 active:scale-95">
                        Install Now
                    </button>
                    <button @click="dismiss()" class="text-xs font-semibold text-slate-400 hover:text-white transition uppercase tracking-wider mt-1">
                        Maybe Later
                    </button>
                </div>
            </div>

            <!-- STEP 2: ENABLE NOTIFICATIONS -->
            <div x-show="step === 'notify'" style="display: none;">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/20 mb-4 border border-emerald-500/30">
                    <svg class="h-6 w-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </div>
                <h3 class="text-lg font-bold text-white tracking-tight mb-2">Enable Notifications</h3>
                <p class="text-sm text-slate-300 leading-relaxed mb-6">
                    Almost done! Allow notifications so you never miss a movie drop or a live match broadcast.
                </p>
                <div class="flex flex-col gap-3">
                    <button @click="requestNotifications()" class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-500 transition shadow-lg shadow-emerald-600/25 active:scale-95">
                        Allow Notifications
                    </button>
                    <button @click="dismiss()" class="text-xs font-semibold text-slate-400 hover:text-white transition uppercase tracking-wider mt-1">
                        Not Now
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pwaPrompt', () => ({
                showModal: false,
                step: 'install', // Starts at 'install', flips to 'notify'
                deferredPrompt: null,

                init() {
                    // Check if the user dismissed this in the last 24 hours so we don't spam them
                    const lastDismissed = localStorage.getItem('pwa_prompt_dismissed');
                    if (lastDismissed && (Date.now() - lastDismissed < 86400000)) {
                        return;
                    }

                    // Check if they are currently using the installed app (standalone mode)
                    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

                    // 1. Capture the browser's install event
                    window.addEventListener('beforeinstallprompt', (e) => {
                        e.preventDefault();
                        this.deferredPrompt = e;

                        // Show popup after 3 seconds if they aren't already using the installed app
                        if (!isStandalone) {
                            setTimeout(() => { this.showModal = true; }, 3000);
                        }
                    });

                    // 2. If they are ALREADY using the installed app, jump straight to checking notifications
                    if (isStandalone) {
                        if ('Notification' in window && Notification.permission === 'default') {
                            this.step = 'notify';
                            setTimeout(() => { this.showModal = true; }, 3000);
                        }
                    }

                    // 3. Listen for successful installation completion
                    window.addEventListener('appinstalled', () => {
                        this.deferredPrompt = null;

                        // Switch seamlessly to the notification step immediately after they install
                        if ('Notification' in window && Notification.permission === 'default') {
                            this.step = 'notify';
                            this.showModal = true;
                        } else {
                            this.showModal = false; // Hide if they already allowed notifications
                        }
                    });
                },

                async installApp() {
                    if (this.deferredPrompt) {
                        // Show the native browser install prompt
                        this.deferredPrompt.prompt();
                        const { outcome } = await this.deferredPrompt.userChoice;
                        this.deferredPrompt = null;

                        // If they accept, the 'appinstalled' listener above handles the transition
                    }
                },

                requestNotifications() {
                    // Call the function from your previous setup
                    if (typeof subscribeToPushNotifications === 'function') {
                        subscribeToPushNotifications();

                        // Hide popup shortly after they click
                        setTimeout(() => { this.showModal = false; }, 1000);
                    }
                },

                dismiss() {
                    this.showModal = false;
                    // Record the timestamp so we leave them alone for 24 hours
                    localStorage.setItem('pwa_prompt_dismissed', Date.now());
                }
            }));
        });
    </script>
    @livewireScripts
</body>
</html>
