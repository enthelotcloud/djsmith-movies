<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.guest.app')]
#[Title('Search & Browse')]
class extends Component {
    use WithPagination;

    // These automatically sync with the browser URL (e.g., ?q=batman&category=1)
    #[Url]
    public $q = '';

    #[Url]
    public $category = '';

    // Auth & Subscriptions State
    public $isLoggedIn = false;
    public $hasActiveSub = false;

    public function mount()
    {
        $this->isLoggedIn = Auth::check();

        if ($this->isLoggedIn) {
            $this->hasActiveSub = DB::table('subscriptions')
                ->where('user_id', Auth::id())
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();
        }
    }

    public function updated($property)
    {
        // Reset pagination when user types a new search or clicks a filter
        if ($property === 'q' || $property === 'category') {
            $this->resetPage();
        }
    }

    public function getCategoriesProperty()
    {
        return DB::table('moviecategories')->orderBy('name')->get();
    }

    public function getContentProperty()
    {
        // 1. Base Query for Movies
        $moviesQuery = DB::table('movies')
            ->select(
                'movies.id',
                'movies.title',
                'movies.description',
                DB::raw("COALESCE(movies.thumbnail, movies.thumbnail_path) as posterUrl"),
                'movies.created_at',
                'movies.slug',
                DB::raw("'movie' as type")
            )
            ->where('movies.status', 'ready');

        // Apply Search to Movies
        if (!empty(trim($this->q))) {
            $moviesQuery->where(function($q) {
                $q->where('movies.title', 'like', '%' . $this->q . '%')
                  ->orWhere('movies.description', 'like', '%' . $this->q . '%');
            });
        }

        // Apply Category Filter
        // (Since categories are explicitly for movies, we only show movies if a category is selected)
        if (!empty($this->category)) {
            $moviesQuery->join('category_movie', 'movies.id', '=', 'category_movie.movie_id')
                        ->where('category_movie.moviecategory_id', $this->category);

            $finalQuery = $moviesQuery->orderBy('movies.created_at', 'desc');
        } else {
            // 2. Base Query for Series (Only included if no specific movie category is selected)
            $seriesQuery = DB::table('series')
                ->select(
                    'id',
                    'title',
                    'description',
                    'poster as posterUrl',
                    'created_at',
                    'slug',
                    DB::raw("'series' as type")
                )
                ->where('status', 'ready');

            // Apply Search to Series
            if (!empty(trim($this->q))) {
                $seriesQuery->where(function($q) {
                    $q->where('title', 'like', '%' . $this->q . '%')
                      ->orWhere('description', 'like', '%' . $this->q . '%');
                });
            }

            // Union Both Queries
            $finalQuery = $moviesQuery->union($seriesQuery)->orderBy('created_at', 'desc');
        }

        $content = $finalQuery->paginate(20);

        // Map safe Local Public Thumbnail URLs
        $content->getCollection()->transform(function ($item) {
            $item->posterUrl = $this->getPosterUrl($item->posterUrl);
            return $item;
        });

        return $content;
    }

    private function getPosterUrl($path)
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;
        try {
            return Storage::disk('public')->url($path);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==========================================
    // 🛡️ 100% HARD PAYWALL GATEKEEPERS
    // ==========================================
    public function canWatch()
    {
        // Zero free tier. You must be logged in AND have an active subscription.
        return $this->isLoggedIn && $this->hasActiveSub;
    }

    public function getActionUrl($item)
    {
        if (!$this->isLoggedIn) {
            return route('login');
        }

        if (!$this->hasActiveSub) {
            return route('client.subscriptions');
        }

        // Only generate the actual watch links if they are fully subscribed
        if ($item->type === 'series') {
            return route('client.series.show', ['slug' => $item->slug]);
        }

        return route('client.player', ['slug' => $item->slug]);
    }
};
?>

<div class="min-h-screen bg-black pt-8 pb-20 relative z-0">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Page Header --}}
        <div class="mb-10 mt-12 lg:mt-0">
            <h1 class="text-4xl font-black text-white tracking-tight">
                @if($q)
                    Results for "<span class="text-red-500">{{ $q }}</span>"
                @else
                    Browse Catalog
                @endif
            </h1>
            <p class="text-slate-400 mt-2">Discover premium movies and series.</p>
        </div>

        <div class="flex flex-col md:flex-row gap-8">

            {{-- SIDEBAR FILTERS --}}
            <div class="w-full md:w-64 flex-shrink-0">
                <div class="bg-[#111] border border-slate-800 rounded-2xl p-6 sticky top-28">

                    {{-- AJAX Search Input --}}
                    <div class="mb-6 relative">
                        <input type="text"
                               wire:model.live.debounce.300ms="q"
                               placeholder="Search library..."
                               class="w-full bg-black/50 border border-slate-700 rounded-xl pl-10 pr-10 py-3 text-sm text-white placeholder-slate-500 focus:ring-2 focus:ring-red-500/50 focus:border-red-500 transition-all outline-none shadow-inner">
                        <svg class="absolute left-3 top-3.5 w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        @if($q)
                            <button wire:click="$set('q', '')" class="absolute right-3 top-3.5 text-slate-500 hover:text-white transition-colors">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        @endif
                    </div>

                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4">Categories</h3>

                    <div class="space-y-2">
                        <button wire:click="$set('category', '')"
                                class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-bold transition {{ $category === '' ? 'bg-red-600 text-white shadow-lg shadow-red-600/20' : 'text-slate-400 hover:text-white hover:bg-zinc-800' }}">
                            All Content
                        </button>

                        @foreach($this->categories as $cat)
                            <button wire:click="$set('category', '{{ $cat->id }}')"
                                    class="w-full text-left px-3 py-2.5 rounded-lg text-sm font-bold transition {{ (string)$category === (string)$cat->id ? 'bg-red-600 text-white shadow-lg shadow-red-600/20' : 'text-slate-400 hover:text-white hover:bg-zinc-800' }}">
                                {{ $cat->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- RESULTS GRID --}}
            <div class="flex-1">
                @if($this->content->isEmpty())
                    <div class="py-20 text-center bg-[#111] rounded-2xl border border-slate-800">
                        <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                        <h3 class="text-lg font-bold text-white">No content found</h3>
                        <p class="text-slate-500 mt-1">Try adjusting your search or category filters.</p>
                        <button wire:click="$set('q', '')" class="mt-4 px-6 py-2 bg-zinc-800 hover:bg-red-600 text-white font-bold rounded-xl transition">Clear Search</button>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                        @foreach($this->content as $item)
                            @php
                                $canPlay = $this->canWatch();
                                $isLocked = !$canPlay;
                                $actionUrl = $this->getActionUrl($item);
                            @endphp

                            <a href="{{ $actionUrl }}" wire:navigate class="group relative bg-[#111] rounded-2xl overflow-hidden border border-slate-800 hover:border-red-600/50 transition duration-500 shadow-lg block">

                                {{-- Poster --}}
                                <div class="aspect-[2/3] w-full relative overflow-hidden bg-zinc-900">
                                    @if($item->posterUrl)
                                        <img src="{{ $item->posterUrl }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-slate-700 text-xs font-bold uppercase">No Poster</div>
                                    @endif

                                    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/20 to-transparent opacity-80"></div>

                                    <div class="absolute top-2 left-2 flex flex-col gap-1.5 z-20">
                                        @if($item->type === 'series')
                                            <span class="px-2 py-0.5 bg-indigo-600 text-white text-[8px] font-black uppercase tracking-widest rounded shadow-md w-max">Series</span>
                                        @else
                                            <span class="px-2 py-0.5 bg-red-600 text-white text-[8px] font-black uppercase tracking-widest rounded shadow-md w-max">Movie</span>
                                        @endif

                                        @if($isLocked)
                                            <span class="px-2 py-0.5 bg-amber-500 text-black text-[8px] font-black uppercase tracking-widest rounded shadow-md w-max flex items-center gap-1">
                                                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C9.243 2 7 4.243 7 7v3H6c-1.103 0-2 .897-2 2v8c0 1.103.897 2 2 2h12c1.103 0 2-.897 2-2v-8c0-1.103-.897-2-2-2h-1V7c0-2.757-2.243-5-5-5zm-3 5c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm8 5v8H5v-8h14zM12 14c-1.104 0-2 .896-2 2s.896 2 2 2 2-.896 2-2-.896-2-2-2z"/></svg>
                                                Locked
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Safe Hover Overlays --}}
                                    <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition duration-300 z-10">
                                        @if($canPlay)
                                            <div class="w-12 h-12 bg-red-600 text-white rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(220,38,38,0.6)] transform scale-90 group-hover:scale-100 transition duration-300">
                                                <svg class="w-5 h-5 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            </div>
                                        @elseif(!$this->isLoggedIn)
                                            <div class="flex flex-col items-center gap-1.5 bg-black/80 backdrop-blur-sm rounded-xl px-4 py-3 border border-slate-700 group-hover:border-red-500 transition-all shadow-xl">
                                                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                                                </svg>
                                                <span class="text-[9px] font-bold text-white uppercase tracking-wider text-center">Sign In<br>to Watch</span>
                                            </div>
                                        @else
                                            <div class="flex flex-col items-center gap-1.5 bg-black/80 backdrop-blur-sm rounded-xl px-4 py-3 border border-amber-500/50 group-hover:border-amber-500 transition-all shadow-xl">
                                                <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                                </svg>
                                                <span class="text-[9px] font-bold text-amber-500 uppercase tracking-wider text-center">Subscribe<br>to Watch</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Details --}}
                                <div class="p-3 sm:p-4 relative z-20">
                                    <h3 class="text-xs sm:text-sm font-bold text-white truncate group-hover:text-red-500 transition">{{ $item->title }}</h3>
                                </div>
                            </a>
                        @endforeach
                    </div>

                    {{-- Native Livewire Pagination --}}
                    <div class="mt-12">
                        {{ $this->content->links(data: ['scrollTo' => false]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
