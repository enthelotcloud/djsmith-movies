<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

new #[Layout('layouts.guest.app')]
#[Title('Now Playing')]
class extends Component
{
    public $slug;
    public $movie;
    public $videoUrl;

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
                abort(403, 'This is premium content. Please upgrade your subscription to watch.');
            }
        }

        // Concurrency Check (Single Instance)
        $this->enforceSingleSession();

        // Generate secure, expiring Backblaze B2 link (valid for 4 hours)
        if ($this->movie->type === 'movie' && $this->movie->video_path) {
            $this->videoUrl = Storage::disk('b2')->temporaryUrl(
                $this->movie->video_path,
                now()->addHours(4)
            );
        } else {
            abort(404, 'Video file not found or this is a series episode.');
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
    @blur.window="document.getElementById('video-player').classList.add('blur-2xl', 'grayscale')"
    @focus.window="document.getElementById('video-player').classList.remove('blur-2xl', 'grayscale')">

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
        <video
            id="video-player"
            controls
            controlsList="nodownload"
            oncontextmenu="return false;"
            class="w-full h-screen max-h-screen object-contain transition-all duration-300"
            autoplay
            @if($movie->thumbnail_path)
                poster="{{ Storage::disk('b2')->temporaryUrl($movie->thumbnail_path, now()->addHours(2)) }}"
            @endif
        >
            <source src="{{ $videoUrl }}" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>

    {{-- Description Overlay (Bottom) --}}
    <div class="w-full absolute bottom-0 left-0 z-40 p-8 bg-gradient-to-t from-black via-black/80 to-transparent opacity-0 hover:opacity-100 transition duration-500 flex items-end">
        <div class="max-w-4xl pointer-events-auto">
            <h2 class="text-3xl font-black mb-2">{{ $movie->title }}</h2>
            <p class="text-gray-400 text-sm leading-relaxed">{{ $movie->description }}</p>
        </div>
    </div>
</div>
