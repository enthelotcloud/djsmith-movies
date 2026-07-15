<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

new #[Title('Dj Smith Movies - Stream Unlimited')]
#[Layout('layouts.guest.app')]
class extends Component
{
    public $hasActiveSub = false;
    public $isLoggedIn = false;
    public $randomFeatured = null;

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

        // Get a random featured movie for the hero background
        $this->randomFeatured = DB::table('movies')
            ->where('status', 'ready')
            ->inRandomOrder()
            ->first();
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
        return DB::table('movies')
            ->where('status', 'ready')
            ->orderBy('created_at', 'desc')
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

    /**
     * Check if user can watch a movie
     */
    public function canWatch($movie)
    {
        if (!$this->isLoggedIn) return false;
        if ($movie->is_premium && !$this->hasActiveSub) return false;
        return true;
    }

    /**
     * Get the appropriate link when clicking a movie
     */
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
};
?>

<div
    class="min-h-screen bg-black relative"
    x-data="{ scrolled: false }"
    x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 100 })"
    wire:poll.60s="pingHeartbeat">

    {{-- 🎬 HERO SECTION --}}
    <div class="relative w-full min-h-[90vh] flex items-center bg-gradient-to-br from-gray-900 via-black to-red-950/30 overflow-hidden">

        {{-- Featured Movie Background (if exists) --}}
        @if($randomFeatured && $randomFeatured->thumbnail_path)
            <div class="absolute inset-0">
                <img src="{{ Storage::disk('b2')->temporaryUrl($randomFeatured->thumbnail_path, now()->addHours(2)) }}"
                     class="w-full h-full object-cover opacity-30 scale-110 blur-sm"
                     alt="">
                <div class="absolute inset-0 bg-gradient-to-t from-black via-black/70 to-black/40"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-black via-black/60 to-transparent"></div>
            </div>
        @else
            {{-- Fallback Background Pattern --}}
            <div class="absolute inset-0">
                <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cdefs%3E%3Cpattern id=\"grid\" width=\"60\" height=\"60\" patternUnits=\"userSpaceOnUse\"%3E%3Cpath d=\"M 60 0 L 0 0 0 60\" fill=\"none\" stroke=\"white\" stroke-width=\"0.5\"/%3E%3C/pattern%3E%3C/defs%3E%3Crect width=\"100%25\" height=\"100%25\" fill=\"url(%23grid)\"/%3E%3C/svg%3E')] opacity-10"></div>
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-red-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse"></div>
                <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-purple-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse" style="animation-delay: 2s;"></div>
            </div>
        @endif

        {{-- Content --}}
        <div class="relative z-20 max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8 py-20 w-full">
            <div class="max-w-4xl space-y-8">

                {{-- Status Badge --}}
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-600/10 border border-red-500/20 backdrop-blur-sm">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    </span>
                    <span class="text-xs font-bold text-red-500 uppercase tracking-widest">Now Streaming</span>
                </div>

                {{-- Featured Movie Title (if exists) --}}
                @if($randomFeatured)
                    <div class="space-y-2">
                        <p class="text-sm text-red-500 font-bold uppercase tracking-widest">Featured This Week</p>
                        <h1 class="text-5xl sm:text-6xl lg:text-8xl font-black text-white tracking-tight leading-none">
                            {{ $randomFeatured->title }}
                        </h1>
                        @if($randomFeatured->description)
                            <p class="text-lg text-gray-400 max-w-2xl leading-relaxed line-clamp-2">
                                {{ $randomFeatured->description }}
                            </p>
                        @endif
                        @if($randomFeatured->duration_in_seconds)
                            <span class="inline-flex items-center gap-2 text-gray-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                {{ $this->formatDuration($randomFeatured->duration_in_seconds) }}
                            </span>
                        @endif
                    </div>
                @else
                    {{-- Default Titles if no movies --}}
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
                @endif

                {{-- CTA Buttons --}}
                <div class="flex flex-col sm:flex-row gap-4 pt-4">
                    @guest
                        <a href="{{ route('login') }}"
                           class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(220,38,38,0.4)] hover:scale-105">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            Sign In to Watch
                        </a>
                        <a href="{{ route('register') }}"
                           class="group inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl border border-gray-700 hover:border-gray-500 text-gray-300 hover:text-white font-bold text-lg transition-all duration-300">
                            Create Account
                            <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </a>
                    @else
                        @if(!$hasActiveSub)
                            <a href="{{ route('client.subscriptions') }}"
                               wire:navigate
                               class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(220,38,38,0.4)] hover:scale-105">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z"/>
                                </svg>
                                Subscribe to Watch
                                <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                            </a>
                        @else
                            <a href="#browse"
                               class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 hover:shadow-[0_0_30px_rgba(220,38,38,0.4)] hover:scale-105">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z"/>
                                </svg>
                                Browse Library
                            </a>
                        @endif
                    @endguest
                </div>

                {{-- Stats --}}
                <div class="flex items-center gap-8 pt-8">
                    <div>
                        <div class="text-3xl font-black text-white">{{ $this->movies->count() }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-widest">Titles</div>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-white">{{ $this->categories->count() }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-widest">Categories</div>
                    </div>
                    <div>
                        <div class="text-3xl font-black text-green-500">HD</div>
                        <div class="text-xs text-gray-500 uppercase tracking-widest">Quality</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Scroll Indicator --}}
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 z-20 animate-bounce">
            <svg class="w-8 h-8 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
            </svg>
        </div>
    </div>

    {{-- 📂 MAIN CONTENT --}}
    <div id="browse" class="relative z-20 max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-20">

        {{-- 🎯 LATEST RELEASES --}}
        @if($this->latestMovies->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <span class="w-1 h-8 bg-red-600 rounded-full"></span>
                        <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Latest Releases</h2>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($this->latestMovies as $movie)
                        @php
                            $canPlay = $this->canWatch($movie);
                            $actionUrl = $this->getMovieAction($movie);
                        @endphp

                        <div class="group relative bg-gray-900 rounded-2xl overflow-hidden border border-gray-800 hover:border-red-500/50 transition-all duration-500 hover:scale-[1.02]">

                            {{-- Poster --}}
                            <div class="aspect-[2/3] w-full relative overflow-hidden">
                                @if($movie->thumbnail_path)
                                    <img src="{{ Storage::disk('b2')->temporaryUrl($movie->thumbnail_path, now()->addHours(2)) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                         alt="{{ $movie->title }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-gray-800 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                @endif

                                {{-- Gradient Overlay --}}
                                <div class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent"></div>

                                {{-- Badges --}}
                                <div class="absolute top-3 left-3 flex flex-col gap-1 z-20">
                                    @if($movie->type === 'series')
                                        <span class="px-2 py-1 bg-purple-600 text-white text-[9px] font-black uppercase tracking-widest rounded-md">Series</span>
                                    @endif
                                </div>

                                @if($movie->is_premium)
                                    <div class="absolute top-3 right-3 z-20">
                                        <span class="px-2 py-1 bg-amber-500 text-black text-[9px] font-black uppercase tracking-widest rounded-md">Premium</span>
                                    </div>
                                @endif

                                {{-- Duration --}}
                                @if($movie->duration_in_seconds)
                                    <div class="absolute bottom-3 right-3 px-2 py-1 bg-black/80 backdrop-blur text-white text-[10px] font-bold rounded border border-gray-700 z-20">
                                        {{ $this->formatDuration($movie->duration_in_seconds) }}
                                    </div>
                                @endif

                                {{-- Hover Overlay --}}
                                <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 z-10">
                                    @if($canPlay)
                                        <a href="{{ $actionUrl }}"
                                           wire:navigate
                                           class="w-16 h-16 bg-red-600 text-white rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(220,38,38,0.6)] hover:scale-110 transition-transform">
                                            <svg class="w-7 h-7 ml-1" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                        </a>
                                    @elseif(!$this->isLoggedIn)
                                        <a href="{{ $actionUrl }}"
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-gray-700 hover:border-red-500 transition-all">
                                            <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                                            </svg>
                                            <span class="text-xs font-bold text-white">Sign In to Watch</span>
                                        </a>
                                    @else
                                        <a href="{{ $actionUrl }}"
                                           class="flex flex-col items-center gap-2 bg-black/80 backdrop-blur rounded-xl px-6 py-4 border border-amber-500/50 hover:border-amber-500 transition-all">
                                            <svg class="w-8 h-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                            </svg>
                                            <span class="text-xs font-bold text-amber-500">Subscribe to Watch</span>
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
                                    <span class="text-xs text-gray-500">{{ $movie->type === 'series' ? 'Series' : 'Movie' }}</span>
                                    @if(isset($movie->release_year) && $movie->release_year)
                                        <span class="text-xs text-gray-700">•</span>
                                        <span class="text-xs text-gray-500">{{ $movie->release_year }}</span>
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
                <div class="text-center py-20">
                    <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gray-900 border border-gray-800 mb-6">
                        <svg class="w-12 h-12 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-white mb-3">Coming Soon</h3>
                    <p class="text-gray-500 max-w-md mx-auto leading-relaxed">
                        We're adding new movies to our library. Check back soon for amazing content!
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

                        <div class="group relative bg-gradient-to-br from-gray-900 to-black rounded-2xl overflow-hidden border border-gray-800 hover:border-red-500/50 transition-all duration-500 hover:scale-105 cursor-pointer">
                            <div class="absolute inset-0 bg-gradient-to-t from-black via-black/50 to-transparent z-10"></div>

                            <div class="aspect-video w-full relative overflow-hidden">
                                @if($category->poster)
                                    <img src="{{ Storage::disk('b2')->temporaryUrl($category->poster, now()->addHours(2)) }}"
                                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700"
                                         alt="{{ $category->name }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full bg-gradient-to-br from-red-900/30 via-purple-900/30 to-blue-900/30 flex items-center justify-center">
                                        <svg class="w-12 h-12 text-white/10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/>
                                        </svg>
                                    </div>
                                @endif
                            </div>

                            <div class="relative z-20 p-4">
                                <h3 class="text-base font-bold text-white group-hover:text-red-500 transition truncate">
                                    {{ $category->name }}
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ $movieCount }} {{ Str::plural('title', $movieCount) }}
                                </p>
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
                <p class="text-gray-500 max-w-2xl mx-auto">Premium streaming experience designed for movie enthusiasts.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="group p-8 rounded-2xl bg-gray-900/50 border border-gray-800 hover:border-red-500/30 transition-all duration-500">
                    <div class="w-12 h-12 rounded-xl bg-red-600/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">HD Streaming</h3>
                    <p class="text-sm text-gray-400 leading-relaxed">Crystal clear quality for all content. Watch movies the way they were meant to be seen.</p>
                </div>

                <div class="group p-8 rounded-2xl bg-gray-900/50 border border-gray-800 hover:border-red-500/30 transition-all duration-500">
                    <div class="w-12 h-12 rounded-xl bg-red-600/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Secure Access</h3>
                    <p class="text-sm text-gray-400 leading-relaxed">Enterprise-grade security protects your account and ensures safe streaming.</p>
                </div>

                <div class="group p-8 rounded-2xl bg-gray-900/50 border border-gray-800 hover:border-red-500/30 transition-all duration-500">
                    <div class="w-12 h-12 rounded-xl bg-red-600/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">24/7 Access</h3>
                    <p class="text-sm text-gray-400 leading-relaxed">Watch anytime, anywhere. Our platform is always available when you need it.</p>
                </div>
            </div>
        </section>

    </div>
</div>
