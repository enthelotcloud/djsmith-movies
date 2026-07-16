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

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->movie = DB::table('movies')->where('slug', $this->slug)->where('status', 'ready')->first();

        if (!$this->movie) abort(404);

        $user = Auth::user();
        if ($this->movie->is_premium && ($user->id !== 1)) {
            $hasSub = DB::table('subscriptions')->where('user_id', $user->id)->where('status', 'active')->exists();
            if (!$hasSub) return redirect()->route('client.subscriptions');
        }

        $this->enforceSingleSession();
        $this->generateUrls();
    }

    public function enforceSingleSession()
    {
        $user = Auth::user();
        $sessionId = session()->getId();

        if ($user->active_session_id && $user->active_session_id !== $sessionId) {
            $lastActive = Carbon::parse($user->last_active_at);
            if ($lastActive->diffInMinutes(now()) < 1) {
                abort(403, "You are already watching on another device. Close it to continue.");
            }
        }

        DB::table('users')->where('id', $user->id)->update([
            'active_session_id' => $sessionId,
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

            if ($this->movie->thumbnail_path) {
                $this->thumbnailUrl = Storage::disk('b2')->temporaryUrl($this->movie->thumbnail_path, now()->addHours(4));
            }
        } catch (\Exception $e) {
            $this->error = "Failed to secure stream.";
        }
    }

    public function heartbeat()
    {
        DB::table('users')->where('id', Auth::id())->update(['last_active_at' => now()]);
    }
};
?>

<div class="min-h-screen bg-black text-white relative overflow-hidden"
     x-data="{
        ui: true,
        isPlaying: false,
        isBlurred: false,
        timer: null,

        init() {
            // Block Right Click
            document.addEventListener('contextmenu', e => e.preventDefault());

            // Block DevTools
            document.addEventListener('keydown', e => {
                if(e.keyCode == 123 || (e.ctrlKey && e.shiftKey && (e.keyCode == 73 || e.keyCode == 74 || e.keyCode == 67)) || (e.ctrlKey && e.keyCode == 85)) {
                    e.preventDefault();
                }
            });

            // Anti-Screen Capture
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && this.isPlaying) {
                    this.$refs.player.pause();
                    this.isBlurred = true;
                }
            });

            // Dynamically load HLS.js to prevent race conditions
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
                    // iPhone Safari Native Fallback
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
                // 1. Enter Fullscreen (Required before rotation)
                if (container.requestFullscreen) {
                    await container.requestFullscreen();
                } else if (container.webkitRequestFullscreen) { /* Desktop Safari */
                    await container.webkitRequestFullscreen();
                } else if (video.webkitEnterFullscreen) { /* iPhone Safari */
                    video.webkitEnterFullscreen();
                }

                // 2. Lock to Landscape (Android Chrome / Modern Browsers)
                if (screen.orientation && screen.orientation.lock) {
                    await screen.orientation.lock('landscape');
                }
            } catch (err) {
                console.warn('Orientation lock not supported on this OS/Browser.', err);
            }

            video.play();
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

    {{-- The Player Container --}}
    <div x-ref="container" class="absolute inset-0 flex items-center justify-center bg-black" wire:ignore>
        <video
            x-ref="player"
            id="video-player"
            class="w-full h-full object-contain transition-all duration-500"
            :class="isBlurred ? 'blur-3xl scale-110 grayscale' : ''"
            controls
            playsinline
            controlsList="nodownload noplaybackrate"
            disablePictureInPicture>
        </video>
    </div>

    {{-- Cinematic "Tap to Play" Intro Screen --}}
    <div x-show="!isPlaying" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-cover bg-center transition-opacity duration-700"
         style="background-image: url('{{ $thumbnailUrl ?: asset('logo.png') }}');">

        {{-- Heavy Dark Gradient Overlay for Readability --}}
        <div class="absolute inset-0 bg-gradient-to-t from-[#0a0a0a] via-[#0a0a0a]/80 to-transparent backdrop-blur-[2px]"></div>

        <div class="relative z-30 flex flex-col items-center text-center px-6 mt-32 max-w-3xl">
            {{-- Big Play Button --}}
            <button @click="startPlayback()" class="w-24 h-24 bg-red-600 hover:bg-red-500 text-white rounded-full flex items-center justify-center transition-all duration-300 shadow-[0_0_40px_rgba(220,38,38,0.5)] transform hover:scale-110">
                <svg class="w-10 h-10 ml-2" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <p class="mt-6 text-slate-300 font-bold tracking-widest text-[10px] sm:text-xs uppercase drop-shadow-md">Tap to Stream</p>

            {{-- Movie Title & Metadata --}}
            <h1 class="mt-8 text-4xl md:text-6xl font-black text-white drop-shadow-2xl leading-tight tracking-tight">
                {{ $movie->title }}
            </h1>

            <div class="flex items-center justify-center gap-4 mt-4 text-xs font-bold text-slate-300">
                @if($movie->is_premium)
                    <span class="text-amber-400 bg-amber-400/10 px-2 py-1 rounded border border-amber-400/20 uppercase tracking-widest">Premium</span>
                @else
                    <span class="text-emerald-400 bg-emerald-400/10 px-2 py-1 rounded border border-emerald-400/20 uppercase tracking-widest">Free</span>
                @endif

                @if($movie->duration_in_seconds)
                    <span>{{ floor($movie->duration_in_seconds / 60) }} MIN</span>
                @endif
                <span class="uppercase">{{ $movie->type }}</span>
            </div>

            @if($movie->excerpt)
                <p class="mt-6 text-slate-400 text-sm md:text-base leading-relaxed drop-shadow max-w-2xl">
                    {{ $movie->excerpt }}
                </p>
            @endif
        </div>
    </div>

    {{-- UI Overlay (In-Player Controls) --}}
    <div class="absolute inset-0 z-10 pointer-events-none transition-opacity duration-500"
         x-show="isPlaying"
         :class="ui ? 'opacity-100' : 'opacity-0'" x-cloak>

        <div class="p-6">
            <a href="{{ route('home') }}" wire:navigate class="pointer-events-auto inline-flex items-center gap-2 bg-black/50 px-4 py-2 rounded-full border border-white/10 text-sm font-bold backdrop-blur-md hover:bg-black/80 transition">
                &larr; Back to Browse
            </a>
        </div>

        <div class="absolute bottom-10 right-10 opacity-20 select-none text-xs tracking-widest font-bold">
            STREAMING ON DJSMITH.CO.KE • {{ Auth::user()->name }}
        </div>
    </div>

    {{-- Loader --}}
    <div wire:loading class="fixed inset-0 bg-black/90 flex items-center justify-center z-50">
        <div class="w-12 h-12 border-4 border-white/10 border-t-red-600 rounded-full animate-spin"></div>
    </div>
</div>
