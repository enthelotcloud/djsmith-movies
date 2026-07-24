<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\{DB, Auth, Storage, Log};
use Carbon\Carbon;

new #[Layout('layouts.guest.app')]
#[Title('Now Playing')]
class extends Component
{
    public $slug;
    public $movie;
    public $streamUrl;
    public $thumbnailUrl = null;
    public $isHLS = false;
    public $error = null;

    // Watch Tracking
    public $startProgress = 0;

    // Session Tracking
    public $hasSessionConflict = false;

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->movie = DB::table('movies')->where('slug', $this->slug)->where('status', 'ready')->first();

        if (!$this->movie) abort(404);

        // 🛡️ 1. STRICT GUEST BLOCK: If not logged in, boot them to login immediately
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // 🛡️ 2. 100% HARD PAYWALL: Every user (except ID 1) MUST have an active, unexpired subscription
        if ($user->id !== 1) {
            $hasActiveSub = DB::table('subscriptions')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();

            if (!$hasActiveSub) {
                return redirect()->route('client.subscriptions');
            }
        }

        // Initialize Session Check
        $this->checkSession();

        $this->generateUrls();

        // Fetch resume time (Cookie logic removed since guests are banned)
        $history = DB::table('watch_histories')
            ->where('user_id', Auth::id())
            ->where('movie_id', $this->movie->id)
            ->first();

        $this->startProgress = $history ? $history->progress_seconds : 0;
    }

    public function checkSession()
    {
        $user = Auth::user();
        $sessionId = session()->getId();

        if ($user->active_session_id && $user->active_session_id !== $sessionId) {
            $lastActive = $user->last_active_at ? Carbon::parse($user->last_active_at) : null;

            // Use absolute diffInSeconds to completely fix timezone drift (e.g., within last 90s)
            if ($lastActive && abs(now()->diffInSeconds($lastActive)) < 90) {
                $this->hasSessionConflict = true;
                return false;
            }
        }

        $this->claimSession();
        return true;
    }

    public function forceTakeoverSession()
    {
        // Overwrite the active session ID in DB immediately
        $this->claimSession();
        $this->hasSessionConflict = false;

        // Refresh stream URLs
        $this->generateUrls();
    }

    private function claimSession()
    {
        DB::table('users')->where('id', Auth::id())->update([
            'active_session_id' => session()->getId(),
            'last_active_at' => now()
        ]);
    }

    public function generateUrls()
    {
        $path = $this->movie->video_path;
        $this->isHLS = str_ends_with($path, '.m3u8');

        try {
            if ($this->isHLS) {
                // Route through your secure Laravel proxy to defeat IDM
                $this->streamUrl = route('stream.manifest', ['slug' => $this->slug]);
            } else {
                $this->streamUrl = Storage::disk('b2')->temporaryUrl($path, now()->addHours(4));
            }

            // Fixed Image Path Logic
            $thumb = $this->movie->thumbnail ?? $this->movie->thumbnail_path ?? null;
            if ($thumb) {
                $this->thumbnailUrl = str_starts_with($thumb, 'http') ? $thumb : Storage::disk('public')->url($thumb);
            }

        } catch (\Exception $e) {
            $this->error = "Failed to secure stream.";
        }
    }

    // Consolidated heartbeat method to handle both tracking and kicking
    public function heartbeat()
    {
        $user = Auth::user();
        $sessionId = session()->getId();

        // 🚨 If another device took over the session, boot this device
        if ($user->active_session_id && $user->active_session_id !== $sessionId) {
            $this->dispatch('session-kicked');
            return;
        }

        DB::table('users')->where('id', Auth::id())->update(['last_active_at' => now()]);
    }

    // Watch Tracking Sync
    public function syncProgress($seconds)
    {
        if (Auth::check()) {
            DB::table('watch_histories')->updateOrInsert(
                ['user_id' => Auth::id(), 'movie_id' => $this->movie->id],
                [
                    'progress_seconds' => $seconds,
                    'is_completed' => ($this->movie->duration_in_seconds && $seconds >= ($this->movie->duration_in_seconds * 0.9)), // Mark complete if 90% watched
                    'updated_at' => now(),
                    'created_at' => DB::raw('COALESCE(created_at, NOW())')
                ]
            );
        }
    }
};
?>

<div class="fixed inset-0 z-50 bg-black text-white overflow-hidden"
     x-data="{
        ui: true,
        isPlaying: false,
        isBlurred: false,
        timer: null,
        warningCount: 0,
        maxWarnings: 3,

        // Tracking Variables
        initialProgress: @js($startProgress),
        movieId: @js($movie->id),
        lastSavedTime: 0,

        init() {
            // 🚨 Fix: Livewire event listener is now safely inside init()
            $wire.on('session-kicked', () => {
                this.forceStop();
                alert('Streaming stopped: Your account started playing on another device.');
                window.location.href = '/';
            });

            const video = this.$refs.player;

            // ==========================================
            // WATCH TRACKING & RESUME
            // ==========================================
            video.addEventListener('loadedmetadata', () => {
                if (this.initialProgress > 0) {
                    video.currentTime = this.initialProgress;
                }
            });

            video.addEventListener('timeupdate', () => {
                let currentTime = Math.floor(video.currentTime);

                // Save every 5 seconds
                if (currentTime > 0 && currentTime % 5 === 0 && currentTime !== this.lastSavedTime) {
                    this.lastSavedTime = currentTime;
                    $wire.syncProgress(currentTime);
                }
            });

            // ==========================================
            // ANTI-PIRACY: Right Click Block
            // ==========================================
            document.addEventListener('contextmenu', e => {
                e.preventDefault();
                this.showWarning('Right-click is disabled to protect content.');
            });

            // ==========================================
            // ANTI-PIRACY: DevTools & Aggressive Screenshot Block
            // ==========================================
            const blockKeysAndScreenshots = (e) => {
                // Dev Tools Block
                if (e.keyCode === 123 || (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || (e.ctrlKey && (e.keyCode === 85 || e.keyCode === 83 || e.keyCode === 80))) {
                    e.preventDefault();
                    this.showWarning('Developer tools & actions disabled.');
                    return false;
                }

                // Aggressive Screenshot Block (PrintScreen or Cmd+Shift+3/4/5)
                if (e.key === 'PrintScreen' || e.keyCode === 44 || (e.metaKey && e.shiftKey)) {
                    e.preventDefault();
                    this.showWarning('Screenshots are disabled.');

                    // Immediately black out the video to ruin the capture frame
                    this.isBlurred = true;
                    setTimeout(() => { if (this.isPlaying) this.isBlurred = false; }, 2000);

                    // Overwrite clipboard so they paste a warning instead of the movie frame
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText('Content is protected. Screenshots are not allowed.');
                    }
                    return false;
                }
            };

            window.addEventListener('keydown', blockKeysAndScreenshots);
            window.addEventListener('keyup', blockKeysAndScreenshots);

            // ==========================================
            // ANTI-SCREEN CAPTURE: Visibility Change
            // ==========================================
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && this.isPlaying) {
                    video.pause();
                    this.isBlurred = true;
                }
            });

            // ==========================================
            // ANTI-SCREEN RECORDING: MediaRecorder Detection
            // ==========================================
            if (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) {
                navigator.mediaDevices.getDisplayMedia = () => {
                    this.showWarning('Screen recording is not allowed.');
                    // Black out screen instantly if they try to invoke recording software
                    this.isBlurred = true;
                    return Promise.reject(new Error('Screen recording blocked'));
                };
            }

            // ==========================================
            // PLAYER SETUP
            // ==========================================
            if (@js($isHLS)) {
                if (typeof Hls === 'undefined') {
                    let script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest';
                    script.onload = () => this.setupPlayer();
                    document.head.appendChild(script);
                } else {
                    this.setupPlayer();
                }
            } else {
                this.setupPlayer();
            }

            // Lock body scroll
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        },

        showWarning(message) {
            this.warningCount++;
            const warningEl = this.$refs.warning;
            const warningText = this.$refs.warningText;

            if (warningEl && warningText) {
                warningText.textContent = message + ` (Warning ${this.warningCount}/${this.maxWarnings})`;
                warningEl.classList.remove('opacity-0', 'translate-y-4');
                warningEl.classList.add('opacity-100', 'translate-y-0');

                setTimeout(() => {
                    warningEl.classList.add('opacity-0', 'translate-y-4');
                    warningEl.classList.remove('opacity-100', 'translate-y-0');
                }, 3000);
            }

            if (this.warningCount >= this.maxWarnings) {
                this.forceStop();
            }
        },

        forceStop() {
            if (this.isPlaying) {
                this.$refs.player.pause();
                this.$refs.player.currentTime = 0;
            }
            this.isPlaying = false;
            this.isBlurred = true;
            this.warningCount = 0;

            alert('Streaming stopped due to multiple violation attempts. Please respect content protection policies.');
        },

        setupPlayer() {
            const video = this.$refs.player;
            const src = @js($streamUrl);
            if (!src) return;

            if (@js($isHLS)) {
                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    const hls = new Hls({
                        capLevelToPlayerSize: true,
                        manifestLoadingTimeOut: 20000,
                        fragLoadingTimeOut: 20000,
                        xhrSetup: function(xhr, url) {
                            if (url.includes('/api/video-key/')) {
                                xhr.withCredentials = true;
                            }
                        }
                    });
                    hls.loadSource(src);
                    hls.attachMedia(video);
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = src;
                }
            } else {
                video.src = src;
            }
        },

        async startPlayback() {
            this.isPlaying = true;
            this.isBlurred = false;
            const video = this.$refs.player;
            const container = this.$refs.container;

            try {
                // 1. Enter Fullscreen
                if (container.requestFullscreen) {
                    await container.requestFullscreen();
                } else if (container.webkitRequestFullscreen) {
                    await container.webkitRequestFullscreen();
                } else if (video.webkitEnterFullscreen) {
                    video.webkitEnterFullscreen();
                }

                // 2. Lock to Landscape (Android/Chrome/Modern Browsers)
                if (screen.orientation && screen.orientation.lock) {
                    await screen.orientation.lock('landscape');
                }
            } catch (err) {
                console.warn('Orientation lock not supported on this OS/Browser.', err);
            }

            video.play().catch(e => {
                console.error('Play failed:', e);
                // Fallback: try again without fullscreen lock logic
                video.play();
            });
            this.hideUI();
        },

        hideUI() {
            if (!this.isPlaying) return;
            this.ui = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.ui = false; }, 3000);
        }
     }"
     @mousemove.window="hideUI()"
     @window.blur="isBlurred = true; if(isPlaying) $refs.player.pause()"
     @window.focus="isBlurred = false"
     wire:poll.30s="heartbeat">

    {{-- Large Diagonal DRM Watermark Overlay --}}
    <div class="pointer-events-none absolute inset-0 z-[5] flex items-center justify-center overflow-hidden" style="user-select: none; -webkit-user-select: none;">
        <div class="text-[clamp(2rem,5vw,6rem)] font-black text-white/5 -rotate-[25deg] whitespace-nowrap tracking-[0.5em] select-none uppercase">
            {{ Auth::user()->email }}
        </div>
    </div>

    {{-- Anti-Piracy Warning Toast --}}
    <div x-ref="warning"
         class="fixed top-6 left-1/2 -translate-x-1/2 z-50 px-6 py-3 bg-red-600/95 backdrop-blur-md text-white font-bold text-sm rounded-xl shadow-2xl border border-red-400/30 transition-all duration-500 opacity-0 translate-y-4 pointer-events-none">
        <span x-ref="warningText"></span>
    </div>

    {{-- Robust Player Container --}}
    <div x-ref="container"
         class="absolute inset-0 flex items-center justify-center bg-black"
         wire:ignore>

        <video
            x-ref="player"
            id="video-player"
            class="w-full h-full object-contain transition-all duration-500 select-none"
            :class="isBlurred ? 'blur-3xl scale-110 grayscale opacity-0' : ''"
            controls
            playsinline
            controlsList="nodownload noplaybackrate"
            disablePictureInPicture
            oncontextmenu="return false;"
            disableRemotePlayback>
        </video>
    </div>

    {{-- Cinematic "Tap to Play" Intro Screen --}}
    <div x-show="!isPlaying"
         class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-cover bg-center transition-opacity duration-700 select-none"
         style="background-image: url('{{ $thumbnailUrl ?: asset('logo.png') }}');">

        {{-- Heavy Dark Gradient Overlay for Readability --}}
        <div class="absolute inset-0 bg-gradient-to-t from-[#0a0a0a] via-[#0a0a0a]/80 to-transparent backdrop-blur-[2px]"></div>

        <div class="relative z-30 flex flex-col items-center text-center px-6 mt-20 sm:mt-32 max-w-3xl">
            {{-- Big Play Button --}}
            <button @click="startPlayback()"
                    class="w-20 h-20 sm:w-24 sm:h-24 bg-red-600 hover:bg-red-500 text-white rounded-full flex items-center justify-center transition-all duration-300 shadow-[0_0_40px_rgba(220,38,38,0.5)] transform hover:scale-110">
                <svg class="w-10 h-10 ml-2" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <p class="mt-6 text-slate-300 font-bold tracking-widest text-[10px] sm:text-xs uppercase drop-shadow-md">Tap to Stream</p>

            {{-- Movie Title & Metadata --}}
            <h1 class="mt-6 sm:mt-8 text-4xl md:text-6xl font-black text-white drop-shadow-2xl leading-tight tracking-tight">
                {{ $movie->title }}
            </h1>

            <div class="flex items-center justify-center gap-4 mt-4 text-xs font-bold text-slate-300">
                {{-- 🔒 100% Paywall Enforcement: Static Premium Badge --}}
                <span class="text-amber-400 bg-amber-400/10 px-2 py-1 rounded border border-amber-400/20 uppercase tracking-widest">Premium</span>

                @if($movie->duration_in_seconds)
                    <span>{{ floor($movie->duration_in_seconds / 60) }} MIN</span>
                @endif
                <span class="uppercase">{{ $movie->type ?? 'Movie' }}</span>
            </div>

            @if($movie->excerpt)
                <p class="mt-4 sm:mt-6 text-slate-400 text-sm md:text-base leading-relaxed drop-shadow max-w-2xl line-clamp-3">
                    {{ $movie->excerpt }}
                </p>
            @endif
        </div>
    </div>

    {{-- UI Overlay (In-Player Controls) --}}
    <div class="absolute inset-0 z-10 pointer-events-none transition-opacity duration-500 select-none"
         x-show="isPlaying"
         :class="ui ? 'opacity-100' : 'opacity-0'" x-cloak>

        <div class="p-4 sm:p-6">
            <a href="{{ route('home') }}" wire:navigate
               class="pointer-events-auto inline-flex items-center gap-2 bg-black/50 px-4 py-2 rounded-full border border-white/10 text-sm font-bold backdrop-blur-md hover:bg-black/80 transition shadow-lg">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Browse
            </a>
        </div>

        {{-- Session Info Watermark --}}
        <div class="absolute bottom-6 right-6 sm:bottom-10 sm:right-10 opacity-20 select-none text-[10px] sm:text-xs tracking-widest font-bold pointer-events-none">
            STREAMING ON DJSMITH.CO.KE • {{ Auth::user()->name }}
        </div>
    </div>

    {{-- Loader --}}
    <div wire:loading class="fixed inset-0 bg-black/90 flex items-center justify-center z-50">
        <div class="w-12 h-12 border-4 border-white/10 border-t-red-600 rounded-full animate-spin"></div>
    </div>

    {{-- 🚨 ACTIVE SESSION CONFLICT MODAL --}}
    @if($hasSessionConflict)
        <div class="fixed inset-0 z-[100] bg-black/95 backdrop-blur-2xl flex items-center justify-center p-4">
            <div class="bg-[#111111] border border-red-900/40 rounded-3xl p-6 sm:p-8 max-w-md w-full text-center shadow-2xl shadow-red-900/20 relative overflow-hidden">

                <div class="w-16 h-16 bg-red-600/10 border border-red-500/30 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>

                <h2 class="text-2xl font-black text-white mb-2 tracking-tight">Streaming Conflict</h2>
                <p class="text-slate-400 text-sm mb-6 leading-relaxed">
                    Your account is currently active on another device or tab. Would you like to end the other session and watch here?
                </p>

                <div class="flex flex-col gap-3">
                    <button wire:click="forceTakeoverSession" class="w-full py-3.5 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl transition-all shadow-lg shadow-red-600/30 text-sm">
                        End Other Session & Stream Here
                    </button>

                    <a href="{{ route('home') }}" wire:navigate class="w-full py-3.5 bg-zinc-800 hover:bg-zinc-700 text-slate-300 font-bold rounded-xl transition-all text-sm">
                        Back to Browse
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>
