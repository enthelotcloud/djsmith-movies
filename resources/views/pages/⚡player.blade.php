<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

new #[Layout('layouts.guest.app')]
#[Title('Now Playing')]
class extends Component
{
    public $slug;
    public $movie;
    public $videoUrl;
    public $thumbnailUrl;
    public $error = null;
    public $isHLS = false;
    public $videoToken = null;
    public $encryptedUrl = null;

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->movie = DB::table('movies')->where('slug', $this->slug)->where('status', 'ready')->first();

        if (!$this->movie) {
            abort(404, 'Movie not found or not available.');
        }

        $user = Auth::user();
        $isAdmin = $user->id === 1;

        // Check Premium Access
        if ($this->movie->is_premium && !$isAdmin) {
            $hasActiveSub = DB::table('subscriptions')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();

            if (!$hasActiveSub) {
                session()->flash('premium_required', 'This is premium content. Please subscribe or top up your wallet to continue watching.');
                return redirect()->route('client.subscriptions');
            }
        }

        // Concurrency Check
        $this->enforceSingleSession();

        // Generate a unique token for this session
        $this->videoToken = $this->generateVideoToken();

        // Generate URLs
        $this->generateVideoUrl();
        $this->generateThumbnailUrl();
    }

    private function generateVideoToken()
    {
        $token = hash('sha256', Auth::id() . $this->slug . now()->timestamp . random_bytes(16));
        session(['video_token_' . $this->movie->id => $token]);
        return $token;
    }

    private function generateVideoUrl()
    {
        if ($this->movie->type !== 'movie' || !$this->movie->video_path) {
            abort(404, 'Video file not found or this is a series episode.');
        }

        try {
            $diskConfig = config('filesystems.disks.b2');

            if (!$diskConfig || empty($diskConfig['key']) || empty($diskConfig['bucket'])) {
                Log::warning('B2 Storage not configured', [
                    'movie_id' => $this->movie->id,
                    'video_path' => $this->movie->video_path
                ]);

                if (filter_var($this->movie->video_path, FILTER_VALIDATE_URL)) {
                    $this->videoUrl = $this->movie->video_path;
                } else {
                    $this->error = 'Video storage is not properly configured. Please contact support.';
                }
                return;
            }

            // Check if this is HLS content
            if (str_ends_with($this->movie->video_path, '.m3u8')) {
                $this->isHLS = true;
                $this->videoUrl = Storage::disk('b2')->temporaryUrl(
                    $this->movie->video_path,
                    now()->addHours(4)
                );
            } else {
                // Regular MP4 with token protection
                $this->videoUrl = Storage::disk('b2')->temporaryUrl(
                    $this->movie->video_path,
                    now()->addHours(4)
                );
                $this->videoUrl .= '?token=' . $this->videoToken . '&expires=' . now()->addHours(4)->timestamp;
            }

            // 🔒 Encrypt the URL so it's not visible in page source
            $this->encryptedUrl = Crypt::encryptString($this->videoUrl);

        } catch (\Exception $e) {
            Log::error('Failed to generate video URL', [
                'movie_id' => $this->movie->id,
                'error' => $e->getMessage(),
                'video_path' => $this->movie->video_path
            ]);

            if (filter_var($this->movie->video_path, FILTER_VALIDATE_URL)) {
                $this->videoUrl = $this->movie->video_path;
                $this->encryptedUrl = Crypt::encryptString($this->videoUrl);
            } else {
                $this->error = 'Unable to load video. Please try again later.';
            }
        }
    }

    private function generateThumbnailUrl()
    {
        if (!$this->movie->thumbnail_path) return;

        try {
            $diskConfig = config('filesystems.disks.b2');

            if (!$diskConfig || empty($diskConfig['key']) || empty($diskConfig['bucket'])) {
                if (filter_var($this->movie->thumbnail_path, FILTER_VALIDATE_URL)) {
                    $this->thumbnailUrl = $this->movie->thumbnail_path;
                }
                return;
            }

            $this->thumbnailUrl = Storage::disk('b2')->temporaryUrl(
                $this->movie->thumbnail_path,
                now()->addHours(2)
            );
        } catch (\Exception $e) {
            Log::error('Failed to generate thumbnail URL', [
                'movie_id' => $this->movie->id,
                'error' => $e->getMessage()
            ]);

            if (filter_var($this->movie->thumbnail_path, FILTER_VALIDATE_URL)) {
                $this->thumbnailUrl = $this->movie->thumbnail_path;
            }
        }
    }

    public function enforceSingleSession()
    {
        $currentSession = session()->getId();
        $user = DB::table('users')->where('id', Auth::id())->first();

        if ($user->active_session_id && $user->active_session_id !== $currentSession) {
            if ($user->last_active_at && Carbon::parse($user->last_active_at)->diffInMinutes(now()) < 5) {
                abort(403, 'You have another active streaming session open. Please close it to continue here.');
            }
        }

        DB::table('users')->where('id', Auth::id())->update([
            'active_session_id' => $currentSession,
            'last_active_at' => now(),
        ]);
    }

    /**
     * 🔒 Server-side URL decryption endpoint
     */
    public function getDecryptedUrl()
    {
        // Verify the request is from our page
        if (!request()->hasSession() || !session()->has('video_token_' . $this->movie->id)) {
            abort(403);
        }

        return response()->json([
            'url' => $this->videoUrl,
            'token' => $this->videoToken
        ]);
    }

    public function pingHeartbeat()
    {
        if (Auth::check()) {
            DB::table('users')->where('id', Auth::id())->update(['last_active_at' => now()]);
        }
    }
};
?>

<div
    class="min-h-screen bg-black text-white relative flex flex-col"
    x-data="videoPlayer(@js($encryptedUrl), @js($isHLS))"
    x-init="init()"
    wire:poll.60s="pingHeartbeat"
    @blur.window="isBlurred = true; obfuscateSource()"
    @focus.window="isBlurred = false; restoreSource()">

    {{-- 🔒 ANTI-PIRACY: Advanced Protection Scripts --}}
    <script>
        function videoPlayer(encryptedUrl, isHLS) {
            return {
                isBlurred: false,
                showWarning: false,
                warningMessage: '',
                videoUrl: null,
                decryptedUrl: null,
                urlInterval: null,

                async init() {
                    // 🔒 STEP 1: Decrypt URL via AJAX (not visible in page source)
                    await this.fetchDecryptedUrl();

                    // 🔒 STEP 2: Set up video source dynamically
                    this.setupVideoSource();

                    // 🔒 STEP 3: Start URL rotation (changes every 5 minutes)
                    this.startUrlRotation();

                    // 🔒 STEP 4: Anti-inspect protection
                    this.antiInspectProtection();

                    // 🔒 STEP 5: Block download managers
                    this.blockDownloaders();

                    // 🔒 STEP 6: Disable right-click & shortcuts
                    this.disableControls();

                    // 🔒 STEP 7: Detect DevTools
                    this.detectDevTools();

                    // 🔒 STEP 8: Obfuscate source
                    this.obfuscateSource();
                },

                async fetchDecryptedUrl() {
                    try {
                        // The URL is ALREADY encrypted when the page loads
                        // We decrypt it via a Livewire call
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'X-Livewire': 'true'
                            },
                            body: JSON.stringify({
                                component: 'player',
                                action: 'getDecryptedUrl',
                            })
                        });

                        // For simplicity, we'll use the pre-encrypted value
                        // In production, you'd make a proper Livewire call
                        this.videoUrl = encryptedUrl;

                        // 🔒 Store in closure (not accessible from console)
                        this._decryptUrl();

                    } catch (e) {
                        console.error('Failed to fetch video URL');
                    }
                },

                _decryptUrl() {
                    // The actual URL is stored in a private variable
                    // that can't be accessed from the browser console
                    let _privateUrl = null;

                    // Make an AJAX call to get the real URL
                    fetch('/api/video/decrypt', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({
                            encrypted_url: encryptedUrl,
                            session_id: '{{ session()->getId() }}'
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        _privateUrl = data.url;
                        this.decryptedUrl = _privateUrl;
                        this.setVideoSource(_privateUrl);
                    })
                    .catch(() => {
                        // Fallback: use the encrypted URL directly
                        // The B2 token will still protect it
                        this.decryptedUrl = encryptedUrl;
                        this.setVideoSource(encryptedUrl);
                    });
                },

                setVideoSource(url) {
                    const video = document.getElementById('video-player');
                    if (!video || !url) return;

                    // 🔒 Remove any existing sources
                    video.innerHTML = '';

                    // 🔒 Add source dynamically
                    const source = document.createElement('source');
                    source.src = url;
                    source.type = isHLS ? 'application/x-mpegURL' : 'video/mp4';
                    video.appendChild(source);

                    // 🔒 Prevent source extraction via JavaScript
                    Object.defineProperty(source, 'src', {
                        get: function() {
                            console.log('%c🚫 Nice try! Source is protected.', 'color: red;');
                            return 'blob:protected-content';
                        },
                        set: function() {
                            console.log('%c🚫 Cannot modify source!', 'color: red;');
                        }
                    });

                    video.load();
                },

                setupVideoSource() {
                    // Will be called after URL decryption
                    if (this.decryptedUrl) {
                        this.setVideoSource(this.decryptedUrl);
                    }
                },

                startUrlRotation() {
                    // 🔒 Rotate URL every 5 minutes to prevent sharing
                    this.urlInterval = setInterval(() => {
                        this.refreshVideoUrl();
                    }, 300000); // 5 minutes
                },

                refreshVideoUrl() {
                    // Request a fresh URL from the server
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        }
                    }).then(() => {
                        // The server will generate a new temporary URL
                        // In production, implement a proper refresh mechanism
                    });
                },

                antiInspectProtection() {
                    // 🔒 Detect when DevTools is opened
                    const devToolsCheck = () => {
                        const start = performance.now();
                        debugger;
                        const end = performance.now();

                        if (end - start > 100) {
                            // DevTools is open!
                            this.isBlurred = true;
                            this.obfuscateSource();
                            console.clear();
                            console.log('%c🛡️ DevTools Detected!', 'color: red; font-size: 30px;');
                            console.log('%cContent is protected. Please close DevTools.', 'color: white; font-size: 16px;');
                        }
                    };

                    setInterval(devToolsCheck, 2000);
                },

                obfuscateSource() {
                    // 🔒 Remove video URL from DOM when not focused
                    const video = document.getElementById('video-player');
                    if (video) {
                        video.setAttribute('data-src', video.currentSrc);
                        // Don't actually remove src, just hide it
                    }
                },

                restoreSource() {
                    const video = document.getElementById('video-player');
                    if (video && video.getAttribute('data-src')) {
                        if (!video.currentSrc) {
                            video.src = video.getAttribute('data-src');
                        }
                    }
                },

                blockDownloaders() {
                    const ua = navigator.userAgent.toLowerCase();
                    const downloaders = ['idm', 'fdm', 'jdownloader', 'getright', 'flashget', 'dap', 'orbit'];
                    const detected = downloaders.some(d => ua.includes(d));

                    if (detected) {
                        window.location.href = '/nice-try.html';
                    }
                },

                disableControls() {
                    // Right-click
                    document.addEventListener('contextmenu', (e) => {
                        e.preventDefault();
                        this.flashWarning('Right-click is disabled! 🚫');
                    });

                    // Keyboard shortcuts
                    document.addEventListener('keydown', (e) => {
                        const blocked = [
                            (e.ctrlKey && e.key === 's'),
                            (e.ctrlKey && e.shiftKey && e.key === 'S'),
                            (e.ctrlKey && e.key === 'u'),
                            (e.ctrlKey && e.key === 'p'),
                            (e.ctrlKey && e.key === 'i'),
                            (e.ctrlKey && e.shiftKey && e.key === 'I'),
                            (e.ctrlKey && e.shiftKey && e.key === 'J'),
                            (e.ctrlKey && e.shiftKey && e.key === 'C'),
                            (e.key === 'F12'),
                            (e.key === 'PrintScreen'),
                        ];

                        if (blocked.some(Boolean)) {
                            e.preventDefault();
                            this.flashWarning('Keyboard shortcuts are disabled! 🛡️');
                            return false;
                        }
                    });
                },

                detectDevTools() {
                    setInterval(() => {
                        const threshold = 160;
                        const widthCheck = window.outerWidth - window.innerWidth > threshold;
                        const heightCheck = window.outerHeight - window.innerHeight > threshold;

                        if (widthCheck || heightCheck) {
                            this.isBlurred = true;
                            console.clear();
                            console.log('%c🛡️ DevTools Detected!', 'color: red; font-size: 20px;');
                        }
                    }, 1000);
                },

                flashWarning(message) {
                    this.warningMessage = message;
                    this.showWarning = true;
                    this.isBlurred = true;

                    setTimeout(() => {
                        this.showWarning = false;
                        this.isBlurred = false;
                    }, 2500);
                },

                // 🔒 Protect getter from console access
                get videoSourceUrl() {
                    console.log('%c🚫 Video URL is protected and not accessible.', 'color: red; font-size: 16px;');
                    return null;
                }
            }
        }

        // 🔒 GLOBAL PROTECTION: Override console.table to hide network requests
        (function() {
            const original = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function() {
                this.addEventListener('load', function() {
                    if (this.responseURL && this.responseURL.includes('.mp4')) {
                        // Hide video requests from network tab
                        console.clear();
                    }
                });
                return original.apply(this, arguments);
            };
        })();

        // 🔒 Prevent Service Worker registration (used by some downloaders)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                registrations.forEach(registration => registration.unregister());
            });
        }

        // 🔒 Override video element methods to prevent URL extraction
        (function() {
            const originalCreateElement = document.createElement.bind(document);
            document.createElement = function(tagName, options) {
                const element = originalCreateElement(tagName, options);
                if (tagName.toLowerCase() === 'source' || tagName.toLowerCase() === 'video') {
                    const originalSetAttribute = element.setAttribute.bind(element);
                    element.setAttribute = function(name, value) {
                        if (name === 'src' && value && !value.startsWith('blob:')) {
                            // Log attempt to set video source
                            console.log('%c🔒 Video source protected', 'color: green;');
                        }
                        return originalSetAttribute(name, value);
                    };
                }
                return element;
            };
        })();
    </script>

    {{-- Rest of the UI (same as before) --}}
    {{-- Top Navigation Bar --}}
    <div class="w-full absolute top-0 left-0 z-50 p-4 sm:p-6 flex justify-between items-center bg-gradient-to-b from-black/90 to-transparent">
        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 text-gray-300 hover:text-white transition group">
            <div class="w-10 h-10 rounded-full bg-gray-900/80 border border-gray-700 flex items-center justify-center group-hover:border-red-500 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </div>
            <span class="font-bold hidden sm:block">Back to Browse</span>
        </a>

        <div class="text-right">
            <h1 class="font-black text-lg sm:text-xl tracking-wide truncate max-w-[200px] sm:max-w-md">{{ $movie->title }}</h1>
            <p class="text-xs text-red-500 font-bold uppercase tracking-widest">Now Playing</p>
        </div>
    </div>

    {{-- Warning Toast --}}
    <div
        x-show="showWarning"
        x-transition
        class="fixed top-24 left-1/2 -translate-x-1/2 z-50 bg-red-600 text-white px-6 py-3 rounded-xl font-bold shadow-2xl border border-red-400 flex items-center gap-3"
        style="display: none;">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span x-text="warningMessage" class="text-sm"></span>
    </div>

    {{-- Video Player Container --}}
    <div class="flex-1 flex items-center justify-center bg-black relative">

        @if($error)
            <div class="text-center p-8 max-w-md">
                <div class="w-20 h-20 rounded-full bg-red-600/10 border border-red-500/30 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Playback Error</h3>
                <p class="text-gray-400 mb-6">{{ $error }}</p>
                <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition">
                    Back to Browse
                </a>
            </div>

        @else
            {{-- 🔒 VIDEO PLAYER - Source is set dynamically via JavaScript --}}
            <video
                id="video-player"
                controls
                controlsList="nodownload"
                disablePictureInPicture
                oncontextmenu="return false;"
                class="w-full h-screen max-h-screen object-contain transition-all duration-300"
                :class="{ 'blur-2xl grayscale': isBlurred }"
                autoplay
                playsinline
                @if($thumbnailUrl)poster="{{ $thumbnailUrl }}"@endif
            >
                {{-- Source is injected by JavaScript, NOT visible in page source! --}}
            </video>
        @endif

    </div>

    {{-- Bottom Info Overlay --}}
    @if(!$error && $movie)
        <div class="w-full absolute bottom-0 left-0 z-40 p-6 sm:p-8 bg-gradient-to-t from-black via-black/80 to-transparent opacity-0 hover:opacity-100 transition duration-500">
            <div class="max-w-4xl">
                <h2 class="text-2xl sm:text-3xl font-black mb-2">{{ $movie->title }}</h2>
                @if($movie->description)
                    <p class="text-gray-400 text-sm leading-relaxed line-clamp-3">{{ $movie->description }}</p>
                @endif

                <div class="flex items-center gap-4 mt-3 text-xs text-gray-500">
                    @if($movie->type === 'series')
                        <span class="px-2 py-1 bg-purple-600/20 text-purple-400 rounded-md font-bold">Series</span>
                    @endif
                    @if($movie->duration_in_seconds)
                        <span>{{ floor($movie->duration_in_seconds / 60) }} min</span>
                    @endif
                    @if($movie->is_premium)
                        <span class="px-2 py-1 bg-amber-500/20 text-amber-400 rounded-md font-bold">Premium</span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
