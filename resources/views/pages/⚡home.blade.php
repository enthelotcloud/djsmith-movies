<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Title('Dj Smith Movies - Stream Unlimited')]
#[Layout('layouts.guest.app')]
class extends Component
{
    public $hasActiveSub = false;
    public $isLoggedIn = false;
    public $featuredMovies = [];

    public function mount()
    {
        $this->isLoggedIn = Auth::check();

        if ($this->isLoggedIn) {
            $this->hasActiveSub = DB::table('subscriptions')
                ->where('user_id', Auth::id())
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();

            $this->enforceSingleSession();
        }

        // Get the top 5 newest movies for the slider, excluding completed ones
        $sliderQuery = DB::table('movies')->where('status', 'ready');

        if ($this->isLoggedIn) {
            $watchedIds = DB::table('watch_histories')
                ->where('user_id', Auth::id())
                ->where('is_completed', true)
                ->pluck('movie_id');

            if ($watchedIds->isNotEmpty()) {
                $sliderQuery->whereNotIn('id', $watchedIds);
            }
        }

        $this->featuredMovies = $sliderQuery
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();
    }

    public function enforceSingleSession()
    {
        if (!Auth::check()) return;

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

    #[Computed]
    public function movies()
    {
        $query = DB::table('movies')->where('status', 'ready');

        if ($this->isLoggedIn) {
            $watchedIds = DB::table('watch_histories')
                ->where('user_id', Auth::id())
                ->where('is_completed', true)
                ->pluck('movie_id');

            if ($watchedIds->isNotEmpty()) {
                $query->whereNotIn('id', $watchedIds);
            }
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    #[Computed]
    public function continueWatching()
    {
        if (!$this->isLoggedIn) return collect();

        return DB::table('watch_histories')
            ->join('movies', 'watch_histories.movie_id', '=', 'movies.id')
            ->where('watch_histories.user_id', Auth::id())
            ->where('watch_histories.is_completed', false)
            ->where('movies.status', 'ready')
            ->select('movies.*', 'watch_histories.progress_seconds')
            ->orderBy('watch_histories.updated_at', 'desc')
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return DB::table('moviecategories')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function latestMovies()
    {
        return $this->movies->take(8);
    }

    // NEW: Fetch latest active series
    #[Computed]
    public function latestSeries()
    {
        return DB::table('series')
            ->whereIn('status', ['ready', 'ongoing', 'completed'])
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get();
    }

    public function getMoviesByCategory($categoryId)
    {
        return DB::table('movies')
            ->join('category_movie', 'movies.id', '=', 'category_movie.movie_id')
            ->where('category_movie.moviecategory_id', $categoryId)
            ->where('movies.status', 'ready')
            ->select('movies.*')
            ->orderBy('movies.created_at', 'desc')
            ->get();
    }

    public function formatDuration($seconds)
    {
        if (!$seconds) return null;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }

    public function pingHeartbeat()
    {
        if (Auth::check()) {
            DB::table('users')->where('id', Auth::id())->update(['last_active_at' => now()]);
        }
    }

    public function canWatch($movie)
    {
        if (!$this->isLoggedIn) return false;
        if ($movie->is_premium && !$this->hasActiveSub) return false;
        return true;
    }

    public function getMovieAction($movie)
    {
        if (!$this->isLoggedIn) {
            return route('login');
        }

        if ($movie->is_premium && !$this->hasActiveSub) {
            return route('client.subscriptions');
        }

        return route('client.player', ['slug' => $movie->slug]);
    }

    // NEW: Get Series Action (Locks if not logged in)
    public function getSeriesAction($series)
    {
        if (!$this->isLoggedIn) {
            return route('login');
        }

        // Assuming you will create a series overview page that lists the seasons/episodes
        // Fallback to home if the route doesn't exist yet, but you should create a 'client.series.show' route
        return route('client.series.show', ['slug' => $series->slug]);
    }
};
?>

<div
    class="min-h-screen bg-black relative"
    x-data="{ scrolled: false }"
    x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 100 })"
    wire:poll.60s="pingHeartbeat">

    {{-- 🎬 HERO SLIDER SECTION --}}
    @if(count($this->featuredMovies) > 0)
        <div x-data="{
                activeSlide: 0,
                maxSlides: {{ count($this->featuredMovies) }},
                initSlider() {
                    if (this.maxSlides > 1) {
                        setInterval(() => {
                            this.activeSlide = this.activeSlide === this.maxSlides - 1 ? 0 : this.activeSlide + 1;
                        }, 6000);
                    }
                }
             }"
             x-init="initSlider()"
             class="relative w-full min-h-[85vh] flex flex-col justify-center bg-black overflow-hidden pt-20 md:pt-0">

            {{-- Background Images Layer --}}
            <div class="absolute inset-0 z-0">
                @foreach($this->featuredMovies as $index => $movie)
                    @php $heroThumb = $movie->thumbnail ?? $movie->thumbnail_path ?? null; @endphp

                    <div x-show="activeSlide === {{ $index }}"
                         x-transition:enter="transition ease-out duration-1000"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="transition ease-in duration-1000"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="absolute inset-0">

                        @if($heroThumb)
                            <img src="{{ str_starts_with($heroThumb, 'http') ? $heroThumb : Storage::disk('public')->url($heroThumb) }}"
                                 class="w-full h-full object-cover object-top opacity-60"
                                 alt="{{ $movie->title }}">
                        @endif
                    </div>
                @endforeach

                {{-- Global Gradients applied over the active image to ensure smooth blending --}}
                <div class="absolute inset-0 bg-gradient-to-t from-black via-black/80 md:via-black/40 to-transparent"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-black via-black/80 md:via-black/60 to-transparent"></div>
            </div>

            {{-- Content Layer --}}
            <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 w-full flex-grow flex flex-col justify-center">
                @foreach($this->featuredMovies as $index => $movie)
                    @php
                        $canPlay = $this->canWatch($movie);
                        $actionUrl = $this->getMovieAction($movie);
                    @endphp

                    <div x-show="activeSlide === {{ $index }}"
                         x-transition:enter="transition ease-out duration-700 delay-300"
                         x-transition:enter-start="opacity-0 translate-y-8"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         style="display: none;"
                         class="max-w-4xl space-y-6 md:space-y-8">

                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-600/10 border border-red-500/20 backdrop-blur-sm">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                            </span>
                            <span class="text-xs font-bold text-red-500 uppercase tracking-widest">Featured This Week</span>
                        </div>

                        <div class="space-y-4">
                            <h1 class="text-5xl sm:text-6xl lg:text-8xl font-black text-white tracking-tight leading-none drop-shadow-lg">
                                {{ $movie->title }}
                            </h1>

                            @if($movie->description)
                                <p class="text-lg md:text-xl text-gray-300 max-w-2xl leading-relaxed line-clamp-3 md:line-clamp-2 drop-shadow-md">
                                    {{ $movie->description }}
                                </p>
                            @endif

                            <div class="flex items-center gap-4 text-sm font-bold text-gray-300">
                                @if($movie->duration_in_seconds)
                                    <span class="inline-flex items-center gap-1.5">
                                        <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        {{ $this->formatDuration($movie->duration_in_seconds) }}
                                    </span>
                                @endif

                                @if($movie->is_premium)
                                    <span class="px-2.5 py-1 bg-amber-500 text-black text-[10px] uppercase tracking-widest rounded-md">Premium</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-4 pt-4">
                            @if($canPlay)
                                <a href="{{ $actionUrl }}"
                                   wire:navigate
                                   class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(220,38,38,0.4)] hover:scale-105">
                                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                    Play Now
                                </a>
                            @else
                                <a href="{{ $actionUrl }}"
                                   wire:navigate
                                   class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(220,38,38,0.4)] hover:scale-105">
                                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                    {{ $this->isLoggedIn ? 'Subscribe to Watch' : 'Sign In to Watch' }}
                                </a>
                            @endif

                            <a href="#browse"
                               class="group inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl border border-gray-600 hover:border-white hover:bg-white/10 text-white font-bold text-lg transition-all duration-300 backdrop-blur-sm">
                                Browse Library
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Slider Indicators --}}
            @if(count($this->featuredMovies) > 1)
                <div class="absolute bottom-8 left-0 right-0 z-30 flex justify-center items-center gap-3">
                    @foreach($this->featuredMovies as $index => $movie)
                        <button @click="activeSlide = {{ $index }}"
                                class="h-1.5 rounded-full transition-all duration-300"
                                :class="activeSlide === {{ $index }} ? 'bg-red-600 w-12' : 'bg-white/30 hover:bg-white/50 w-3'"></button>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Fallback Hero if no movies exist yet --}}
        <div class="relative w-full min-h-[90vh] flex items-center bg-gradient-to-br from-gray-900 via-black to-red-950/30 overflow-hidden">
            <div class="absolute inset-0">
                <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cdefs%3E%3Cpattern id=\"grid\" width=\"60\" height=\"60\" patternUnits=\"userSpaceOnUse\"%3E%3Cpath d=\"M 60 0 L 0 0 0 60\" fill=\"none\" stroke=\"white\" stroke-width=\"0.5\"/%3E%3C/pattern%3E%3C/defs%3E%3Crect width=\"100%25\" height=\"100%25\" fill=\"url(%23grid)\"/%3E%3C/svg%3E')] opacity-10"></div>
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-red-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse"></div>
            </div>

            <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 w-full">
                <div class="max-w-4xl space-y-8">
                    <div class="space-y-2">
                        <h1 class="text-5xl sm:text-6xl lg:text-8xl font-black text-white tracking-tight leading-none">
                            Unlimited<br>
                            <span class="text-transparent bg-clip-text bg-gradient-to-r from-red-500 to-red-700">
                                Entertainment
                            </span>
                        </h1>
                        <p class="text-lg sm:text-xl text-gray-400 max-w-2xl leading-relaxed">
                            Browse our extensive library of movies and series.
                            Subscribe to unlock premium content and start streaming instantly.
                        </p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4 pt-4">
                        @guest
                            <a href="{{ route('login') }}" class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300">
                                Sign In to Watch
                            </a>
                        @else
                            <a href="#browse" class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300">
                                Browse Library
                            </a>
                        @endguest
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Stats Banner --}}
    <div class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 hidden md:block">
        <div class="flex items-center gap-8 bg-zinc-900/80 backdrop-blur-md border border-slate-800 rounded-2xl p-6 w-fit shadow-xl">
            <div>
                <div class="text-3xl font-black text-white leading-none">{{ $this->movies->count() }}</div>
                <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Titles</div>
            </div>
            <div class="w-px h-10 bg-slate-800"></div>
            <div>
                <div class="text-3xl font-black text-white leading-none">{{ $this->categories->count() }}</div>
                <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Categories</div>
            </div>
            <div class="w-px h-10 bg-slate-800"></div>
            <div>
                <div class="text-3xl font-black text-emerald-500 leading-none">HD</div>
                <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-1">Quality</div>
            </div>
        </div>
    </div>

    {{-- 📂 MAIN CONTENT --}}
    <div id="browse" class="relative z-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-20">

        {{-- ⏳ CONTINUE WATCHING (Only visible to logged-in users with records) --}}
        @if($this->isLoggedIn && $this->continueWatching->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <span class="w-1 h-8 bg-red-600 rounded-full"></span>
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Continue Watching</h2>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($this->continueWatching as $movie)
                        @php
                            $actionUrl = $this->getMovieAction($movie);
                            $movieThumb = $movie->thumbnail ?? $movie->thumbnail_path ?? null;
                            $percent = $movie->duration_in_seconds ? min(100, max(0, ($movie->progress_seconds / $movie->duration_in_seconds) * 100)) : 0;
                        @endphp

                        <div class="group relative bg-zinc-900 rounded-2xl overflow-hidden border border-slate-800 hover:border-red-500/50 transition-all duration-500 hover:scale-[1.02]">

                            {{-- Poster Layer --}}
                            <div class="aspect-[2/3] w-full relative overflow-hidden bg-zinc-950">
                                @if($movieThumb)
                                    <img src="{{ str_starts_with($movieThumb, 'http') ? $movieThumb : Storage::disk('public')->url($movieThumb) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700 opacity-80 group-hover:opacity-100"
                                         alt="{{ $movie->title }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-zinc-800 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif

                                {{-- Play Overlays --}}
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-all duration-300 flex items-center justify-center z-10">
                                    <a href="{{ $actionUrl }}" wire:navigate class="w-12 h-12 bg-red-600/90 rounded-full flex items-center justify-center border border-red-500 shadow-xl transition-transform hover:scale-110">
                                        <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"/></svg>
                                    </a>
                                </div>

                                {{-- Progress Indicator Bar --}}
                                <div class="absolute bottom-0 left-0 right-0 h-1.5 bg-zinc-800 z-20">
                                    <div class="h-full bg-red-600 shadow-[0_0_10px_rgba(220,38,38,0.7)] transition-all" style="width: {{ $percent }}%;"></div>
                                </div>
                            </div>

                            {{-- Bottom Info Segment --}}
                            <div class="p-3">
                                <h3 class="text-sm font-bold text-white truncate group-hover:text-red-500 transition">
                                    {{ $movie->title }}
                                </h3>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    @if($movie->duration_in_seconds)
                                        Resumes at {{ $this->formatDuration($movie->progress_seconds) }}
                                    @else
                                        In Progress
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 🎯 LATEST MOVIES --}}
        @if($this->latestMovies->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <span class="w-1 h-8 bg-red-600 rounded-full"></span>
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Latest Movies</h2>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($this->latestMovies as $movie)
                        @php
                            $canPlay = $this->canWatch($movie);
                            $actionUrl = $this->getMovieAction($movie);
                            $movieThumb = $movie->thumbnail ?? $movie->thumbnail_path ?? null;
                        @endphp

                        <div class="group relative bg-zinc-900 rounded-2xl overflow-hidden border border-slate-800 hover:border-red-500/50 transition-all duration-500 hover:scale-[1.02]">

                            {{-- Poster --}}
                            <div class="aspect-[2/3] w-full relative overflow-hidden">
                                @if($movieThumb)
                                    <img src="{{ str_starts_with($movieThumb, 'http') ? $movieThumb : Storage::disk('public')->url($movieThumb) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                         alt="{{ $movie->title }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-zinc-800 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif

                                {{-- Gradient Overlay --}}
                                <div class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent"></div>

                                @if($movie->is_premium)
                                    <div class="absolute top-3 right-3 z-20">
                                        <span class="px-2 py-1 bg-amber-500 text-black text-[9px] font-black uppercase tracking-widest rounded-md shadow-lg border border-amber-400">Premium</span>
                                    </div>
                                @endif

                                {{-- Duration --}}
                                @if($movie->duration_in_seconds)
                                    <div class="absolute bottom-3 right-3 px-2 py-1 bg-black/80 backdrop-blur-md text-white text-[10px] font-bold rounded border border-white/10 shadow-lg z-20 flex items-center gap-1.5">
                                        <svg class="w-3 h-3 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ $this->formatDuration($movie->duration_in_seconds) }}
                                    </div>
                                @endif

                                {{-- Hover Overlay --}}
                                <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 z-10">
                                    @if($canPlay)
                                        <a href="{{ $actionUrl }}"
                                           wire:navigate
                                           class="w-14 h-14 bg-red-600/90 backdrop-blur-sm text-white rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(220,38,38,0.5)] border border-red-500 hover:scale-110 transition-transform">
                                            <svg class="w-6 h-6 ml-1" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"/></svg>
                                        </a>
                                    @elseif(!$this->isLoggedIn)
                                        <a href="{{ $actionUrl }}"
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-slate-700 hover:border-red-500 transition-all shadow-xl">
                                            <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                                            </svg>
                                            <span class="text-xs font-bold text-white uppercase tracking-wider">Sign In to Watch</span>
                                        </a>
                                    @else
                                        <a href="{{ $actionUrl }}"
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-amber-500/50 hover:border-amber-500 transition-all shadow-xl">
                                            <svg class="w-8 h-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                            </svg>
                                            <span class="text-xs font-bold text-amber-500 uppercase tracking-wider">Subscribe to Watch</span>
                                        </a>
                                    @endif
                                </div>
                            </div>

                            {{-- Movie Info --}}
                            <div class="p-3">
                                <h3 class="text-sm font-bold text-white truncate group-hover:text-red-500 transition">
                                    {{ $movie->title }}
                                </h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs text-slate-500">Movie</span>
                                    @if(isset($movie->release_year) && $movie->release_year)
                                        <span class="text-xs text-slate-700">•</span>
                                        <span class="text-xs text-slate-500">{{ $movie->release_year }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            {{-- Empty State --}}
            <section>
                <div class="text-center py-20 border-2 border-dashed border-slate-800 rounded-3xl bg-black/30">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-zinc-900 border border-slate-800 mb-6 shadow-xl">
                        <svg class="w-10 h-10 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-black text-white mb-2">No Movies Found</h3>
                    <p class="text-slate-500 max-w-md mx-auto font-medium">
                        We're still setting up our catalog. Check back soon for amazing content!
                    </p>
                </div>
            </section>
        @endif

        {{-- 📂 CATEGORIES SECTION --}}
        @if($this->categories->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <span class="w-1 h-8 bg-red-600 rounded-full"></span>
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Browse by Category</h2>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                    @foreach($this->categories as $category)
                        @php $movieCount = $this->getMoviesByCategory($category->id)->count(); @endphp

                        <a href="{{ route('category.single', ['slug' => $category->slug]) }}" wire:navigate class="group block relative bg-zinc-900 rounded-2xl overflow-hidden border border-slate-800 hover:border-red-500/50 transition-all duration-500 hover:scale-105 cursor-pointer shadow-lg">
                            <div class="absolute inset-0 bg-gradient-to-t from-black via-black/40 to-transparent z-10 transition-opacity group-hover:opacity-80"></div>

                            <div class="aspect-video w-full relative overflow-hidden">
                                @if($category->poster)
                                    <img src="{{ str_starts_with($category->poster, 'http') ? $category->poster : Storage::disk('public')->url($category->poster) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                         alt="{{ $category->name }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-gradient-to-br from-red-900/20 via-black to-slate-900/20 flex items-center justify-center">
                                        <svg class="w-8 h-8 text-white/10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/>
                                        </svg>
                                    </div>
                                @endif
                            </div>

                            <div class="relative z-20 p-4">
                                <h3 class="text-base font-bold text-white group-hover:text-red-500 transition truncate">
                                    {{ $category->name }}
                                </h3>
                                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">
                                    {{ $movieCount }} {{ Str::plural('title', $movieCount) }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 📺 TRENDING SERIES SECTION --}}
        @if($this->latestSeries->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <span class="w-1 h-8 bg-red-600 rounded-full"></span>
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Trending Series</h2>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($this->latestSeries as $show)
                        @php
                            $actionUrl = $this->getSeriesAction($show);
                            $showPoster = $show->poster ?? null;
                        @endphp

                        <div class="group relative bg-zinc-900 rounded-2xl overflow-hidden border border-slate-800 hover:border-red-500/50 transition-all duration-500 hover:scale-[1.02]">

                            {{-- Poster --}}
                            <div class="aspect-[2/3] w-full relative overflow-hidden">
                                @if($showPoster)
                                    <img src="{{ str_starts_with($showPoster, 'http') ? $showPoster : Storage::disk('public')->url($showPoster) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                         alt="{{ $show->title }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-zinc-800 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                        </svg>
                                    </div>
                                @endif

                                {{-- Gradient Overlay --}}
                                <div class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent"></div>

                                {{-- TV Series Badge --}}
                                <div class="absolute top-3 left-3 z-20">
                                    <span class="px-2 py-1 bg-indigo-600 text-white text-[9px] font-black uppercase tracking-widest rounded-md shadow-lg border border-indigo-500">TV Series</span>
                                </div>

                                {{-- Hover Overlay --}}
                                <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 z-10">
                                    @if($this->isLoggedIn)
                                        <a href="{{ $actionUrl }}"
                                           wire:navigate
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-slate-700 hover:border-red-500 transition-all shadow-xl hover:scale-105">
                                            <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                                            </svg>
                                            <span class="text-xs font-bold text-white uppercase tracking-wider">View Episodes</span>
                                        </a>
                                    @else
                                        <a href="{{ $actionUrl }}"
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-slate-700 hover:border-red-500 transition-all shadow-xl">
                                            <svg class="w-8 h-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                                            </svg>
                                            <span class="text-xs font-bold text-white uppercase tracking-wider">Sign In to Watch</span>
                                        </a>
                                    @endif
                                </div>
                            </div>

                            {{-- Series Info --}}
                            <div class="p-3">
                                <h3 class="text-sm font-bold text-white truncate group-hover:text-red-500 transition">
                                    {{ $show->title }}
                                </h3>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs text-slate-500 capitalize">{{ $show->status === 'ready' ? 'Complete' : $show->status }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 💎 FEATURES SECTION --}}
        <section class="py-10">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-black text-white mb-4">Why Choose Dj Smith Movies?</h2>
                <p class="text-slate-500 max-w-2xl mx-auto font-medium">Premium streaming experience designed for movie enthusiasts.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="group p-8 rounded-2xl bg-[#111111] border border-slate-800 hover:border-red-500/30 transition-all duration-500 shadow-xl">
                    <div class="w-12 h-12 rounded-xl bg-zinc-900 border border-slate-800 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">HD Streaming</h3>
                    <p class="text-sm text-slate-400 leading-relaxed font-medium">Crystal clear quality for all content. Watch movies the way they were meant to be seen.</p>
                </div>

                <div class="group p-8 rounded-2xl bg-[#111111] border border-slate-800 hover:border-red-500/30 transition-all duration-500 shadow-xl">
                    <div class="w-12 h-12 rounded-xl bg-zinc-900 border border-slate-800 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Secure Access</h3>
                    <p class="text-sm text-slate-400 leading-relaxed font-medium">Enterprise-grade security protects your account and ensures safe streaming.</p>
                </div>

                <div class="group p-8 rounded-2xl bg-[#111111] border border-slate-800 hover:border-red-500/30 transition-all duration-500 shadow-xl">
                    <div class="w-12 h-12 rounded-xl bg-zinc-900 border border-slate-800 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">24/7 Access</h3>
                    <p class="text-sm text-slate-400 leading-relaxed font-medium">Watch anytime, anywhere. Our platform is always available when you need it.</p>
                </div>
            </div>
        </section>

    </div>
</div>
