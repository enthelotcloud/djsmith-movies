<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->movie = DB::table('movies')->where('slug', $this->slug)->where('status', 'ready')->first();

        if (!$this->movie) {
            abort(404, 'Movie not found or not available.');
        }

        $user = Auth::user();

        // Admin override: User ID 1 bypasses everything
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

        // Concurrency Check (Single Instance)
        $this->enforceSingleSession();

        // Generate video URL with error handling
        $this->generateVideoUrl();

        // Generate thumbnail URL with error handling
        $this->generateThumbnailUrl();
    }

    private function generateVideoUrl()
    {
        if ($this->movie->type !== 'movie' || !$this->movie->video_path) {
            abort(404, 'Video file not found or this is a series episode.');
        }

        try {
            // Check if B2 disk is configured
            if (!config('filesystems.disks.b2.key') || !config('filesystems.disks.b2.bucket')) {
                // Fallback: try to use a direct URL if stored
                if (filter_var($this->movie->video_path, FILTER_VALIDATE_URL)) {
                    $this->videoUrl = $this->movie->video_path;
                } else {
                    Log::error('B2 Storage not configured', [
                        'movie_id' => $this->movie->id,
                        'video_path' => $this->movie->video_path
                    ]);
                    $this->error = 'Video storage is not properly configured. Please contact support.';
                }
                return;
            }

            $this->videoUrl = Storage::disk('b2')->temporaryUrl(
                $this->movie->video_path,
                now()->addHours(4)
            );
        } catch (\Exception $e) {
            Log::error('Failed to generate video URL', [
                'movie_id' => $this->movie->id,
                'error' => $e->getMessage(),
                'video_path' => $this->movie->video_path
            ]);

            // Fallback: try direct URL
            if (filter_var($this->movie->video_path, FILTER_VALIDATE_URL)) {
                $this->videoUrl = $this->movie->video_path;
            } else {
                $this->error = 'Unable to load video. Please try again later.';
            }
        }
    }

    private function generateThumbnailUrl()
    {
        if (!$this->movie->thumbnail_path) return;

        try {
            if (!config('filesystems.disks.b2.key') || !config('filesystems.disks.b2.bucket')) {
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
    x-data
    wire:poll.60s="pingHeartbeat"
    @blur.window="document.getElementById('video-player')?.classList.add('blur-2xl', 'grayscale')"
    @focus.window="document.getElementById('video-player')?.classList.remove('blur-2xl', 'grayscale')">

    {{-- Anti-Piracy Scripts --}}
    <script>
        document.addEventListener('contextmenu', event => event.preventDefault());
        document.addEventListener('keydown', function(e) {
            if(e.keyCode == 123 ||
              (e.ctrlKey && e.shiftKey && (e.keyCode == 73 || e.keyCode == 74 || e.keyCode == 67)) ||
              (e.ctrlKey && e.keyCode == 85) ||
              e.key === 'PrintScreen') {
                e.preventDefault();
            }
        });
    </script>

    {{-- Top Navigation Bar --}}
    <div class="w-full absolute top-0 left-0 z-50 p-6 flex justify-between items-center bg-gradient-to-b from-black/80 to-transparent pointer-events-auto">
        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 text-gray-300 hover:text-white transition group">
            <div class="w-10 h-10 rounded-full bg-gray-900 border border-gray-700 flex items-center justify-center group-hover:border-red-500 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </div>
            <span class="font-bold">Back to Browse</span>
        </a>

        <div class="text-right">
            <h1 class="font-black text-xl tracking-wide">{{ $movie->title }}</h1>
            <p class="text-xs text-red-500 font-bold uppercase tracking-widest">Now Playing</p>
        </div>
    </div>

    {{-- Video Player Container --}}
    <div class="flex-1 flex items-center justify-center bg-black relative">
        @if($error)
            {{-- Error State --}}
            <div class="text-center p-8">
                <div class="w-20 h-20 rounded-full bg-red-600/10 border border-red-500/30 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Playback Error</h3>
                <p class="text-gray-400 mb-6">{{ $error }}</p>
                <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Browse
                </a>
            </div>
        @elseif($videoUrl)
            <video
                id="video-player"
                controls
                controlsList="nodownload"
                oncontextmenu="return false;"
                class="w-full h-screen max-h-screen object-contain transition-all duration-300"
                autoplay
                @if($thumbnailUrl)
                    poster="{{ $thumbnailUrl }}"
                @endif
            >
                <source src="{{ $videoUrl }}" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        @else
            {{-- Loading State --}}
            <div class="text-center p-8">
                <div class="w-20 h-20 rounded-full border-4 border-red-600 border-t-transparent animate-spin mx-auto mb-6"></div>
                <p class="text-gray-400">Loading video...</p>
            </div>
        @endif
    </div>

    {{-- Description Overlay (Bottom) --}}
    @if($videoUrl)
        <div class="w-full absolute bottom-0 left-0 z-40 p-8 bg-gradient-to-t from-black via-black/80 to-transparent opacity-0 hover:opacity-100 transition duration-500 flex items-end">
            <div class="max-w-4xl pointer-events-auto">
                <h2 class="text-3xl font-black mb-2">{{ $movie->title }}</h2>
                @if($movie->description)
                    <p class="text-gray-400 text-sm leading-relaxed">{{ $movie->description }}</p>
                @endif
            </div>
        </div>
    @endif
</div>
