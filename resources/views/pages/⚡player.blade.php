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
    public $thumbnailUrl;
    public $isHLS = false;
    public $error = null;

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->movie = DB::table('movies')->where('slug', $this->slug)->where('status', 'ready')->first();

        if (!$this->movie) abort(404);

        // 1. Check Permissions
        $user = Auth::user();
        if ($this->movie->is_premium && ($user->id !== 1)) {
            $hasSub = DB::table('subscriptions')->where('user_id', $user->id)->where('status', 'active')->exists();
            if (!$hasSub) return redirect()->route('client.subscriptions');
        }

        // 2. Single Session Enforcement (Netflix Style)
        $this->enforceSingleSession();

        // 3. Generate Stream URL
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
                // Public HLS URL (Protected by B2 CORS rules we set earlier)
                $bucket = config('filesystems.disks.b2.bucket');
                $endpoint = str_replace('https://', '', config('filesystems.disks.b2.endpoint'));
                $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
                $this->streamUrl = "https://{$bucket}.{$endpoint}/{$encoded}";
            } else {
                // Temporary Signed URL for MP4
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
        isBlurred: false,
        timer: null,

        init() {
            // Block Right Click
            document.addEventListener('contextmenu', e => e.preventDefault());

            // Block DevTools Shortcuts
            document.addEventListener('keydown', e => {
                if(e.keyCode == 123 || (e.ctrlKey && e.shiftKey && (e.keyCode == 73 || e.keyCode == 74 || e.keyCode == 67)) || (e.ctrlKey && e.keyCode == 85)) {
                    e.preventDefault();
                }
            });

            // Anti-Screen Capture: Pause if user switches tab
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.$refs.player.pause();
                    this.isBlurred = true;
                }
            });

            this.setupPlayer();
        },

        setupPlayer() {
            const video = this.$refs.player;
            const src = @js($streamUrl);
            if (!src) return;

            if (@js($isHLS)) {
                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    const hls = new Hls({ capLevelToPlayerSize: true });
                    hls.loadSource(src);
                    hls.attachMedia(video);
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = src;
                }
            } else {
                video.src = src;
            }
        },

        hideUI() {
            this.ui = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.ui = false; }, 3000);
        }
     }"
     @mousemove.window="hideUI()"
     @window.blur="isBlurred = true; $refs.player.pause()"
     @window.focus="isBlurred = false"
     wire:poll.30s="heartbeat">

    {{-- The Player --}}
    <div class="absolute inset-0 flex items-center justify-center bg-black" wire:ignore>
        <video
            x-ref="player"
            id="video-player"
            class="w-full h-full object-contain transition-all duration-500"
            :class="isBlurred ? 'blur-3xl scale-110 grayscale' : ''"
            controls
            autoplay
            playsinline
            controlsList="nodownload noplaybackrate"
            disablePictureInPicture
            poster="{{ $thumbnailUrl }}">
        </video>
    </div>

    {{-- UI Overlay --}}
    <div class="absolute inset-0 z-10 pointer-events-none transition-opacity duration-500"
         :class="ui ? 'opacity-100' : 'opacity-0'">

        {{-- Back Button --}}
        <div class="p-6">
            <a href="{{ route('home') }}" wire:navigate class="pointer-events-auto inline-flex items-center gap-2 bg-black/50 px-4 py-2 rounded-full border border-white/10 text-sm font-bold backdrop-blur-md">
                &larr; Back to Browse
            </a>
        </div>

        {{-- Watermark (Harder to record if your name is on it) --}}
        <div class="absolute bottom-10 right-10 opacity-20 select-none text-xs tracking-widest font-bold">
            STREAMING ON DJSMITH.CO.KE • {{ Auth::user()->name }}
        </div>
    </div>

    {{-- Loader --}}
    <div wire:loading class="fixed inset-0 bg-black/80 flex items-center justify-center z-50">
        <div class="w-10 h-10 border-4 border-white/20 border-t-red-600 rounded-full animate-spin"></div>
    </div>

    @if($isHLS)
        <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    @endif
</div>
