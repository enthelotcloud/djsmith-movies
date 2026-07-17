<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new #[Layout('layouts.guest.app')]
#[Title('Search & Browse')]
class extends Component {
    use WithPagination;

    // These automatically sync with the browser URL (e.g., ?q=batman&category=1)
    #[Url]
    public $q = '';

    #[Url]
    public $category = '';

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

    public function getMoviesProperty()
    {
        $query = DB::table('movies')->where('status', 'ready');

        // Apply Search Filter
        if (!empty(trim($this->q))) {
            $query->where(function($q) {
                $q->where('title', 'like', '%' . $this->q . '%')
                  ->orWhere('description', 'like', '%' . $this->q . '%');
            });
        }

        // Apply Category Filter using Pivot Table
        if (!empty($this->category)) {
            $query->join('category_movie', 'movies.id', '=', 'category_movie.movie_id')
                  ->where('category_movie.moviecategory_id', $this->category);
        }

        $movies = $query->orderBy('movies.created_at', 'desc')->paginate(20);

        // Map safe Local Public Thumbnail URLs
        $movies->getCollection()->transform(function ($movie) {
            $movie->posterUrl = $this->getPosterUrl($movie->thumbnail ?? $movie->thumbnail_path ?? null);
            return $movie;
        });

        return $movies;
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
                @if($this->movies->isEmpty())
                    <div class="py-20 text-center bg-[#111] rounded-2xl border border-slate-800">
                        <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                        <h3 class="text-lg font-bold text-white">No content found</h3>
                        <p class="text-slate-500 mt-1">Try adjusting your search or category filters.</p>
                        <button wire:click="$set('q', '')" class="mt-4 px-6 py-2 bg-zinc-800 hover:bg-red-600 text-white font-bold rounded-xl transition">Clear Search</button>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                        @foreach($this->movies as $movie)
                            <a href="{{ route('client.player', ['slug' => $movie->slug]) }}" wire:navigate class="group relative bg-[#111] rounded-2xl overflow-hidden border border-slate-800 hover:border-red-600/50 transition duration-500 shadow-lg block">

                                {{-- Poster --}}
                                <div class="aspect-[2/3] w-full relative overflow-hidden bg-zinc-900">
                                    @if($movie->posterUrl)
                                        <img src="{{ $movie->posterUrl }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-slate-700 text-xs font-bold uppercase">No Poster</div>
                                    @endif

                                    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/20 to-transparent opacity-80"></div>

                                    <div class="absolute top-2 left-2 flex flex-col gap-1.5">
                                        @if($movie->type === 'series')
                                            <span class="px-2 py-0.5 bg-indigo-600 text-white text-[8px] font-black uppercase tracking-widest rounded shadow-md w-max">Series</span>
                                        @endif
                                        @if($movie->is_premium)
                                            <span class="px-2 py-0.5 bg-amber-500 text-black text-[8px] font-black uppercase tracking-widest rounded shadow-md w-max">Premium</span>
                                        @endif
                                    </div>

                                    <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition duration-300">
                                        <div class="w-12 h-12 bg-red-600 text-white rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(220,38,38,0.6)] transform scale-90 group-hover:scale-100 transition duration-300">
                                            <svg class="w-5 h-5 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </div>
                                    </div>
                                </div>

                                {{-- Details --}}
                                <div class="p-3 sm:p-4">
                                    <h3 class="text-xs sm:text-sm font-bold text-white truncate group-hover:text-red-500 transition">{{ $movie->title }}</h3>
                                </div>
                            </a>
                        @endforeach
                    </div>

                    {{-- Native Livewire Pagination --}}
                    <div class="mt-12">
                        {{ $this->movies->links(data: ['scrollTo' => false]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
