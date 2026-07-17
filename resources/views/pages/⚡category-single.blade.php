<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.guest.app')] class extends Component {
    public $categoryId;
    public $categoryName;

    // Now accepts $slug from the URL
    public function mount($slug)
    {
        // 1. Fetch the category by slug instead of ID
        $category = DB::table('moviecategories')->where('slug', $slug)->first();

        if (!$category) {
            abort(404, 'Category not found');
        }

        $this->categoryId = $category->id;
        $this->categoryName = $category->name;
    }

    #[Computed]
    public function movies()
    {
        // 2. Fetch published movies for this category
        return DB::table('movies')
            ->join('category_movie', 'movies.id', '=', 'category_movie.movie_id')
            ->where('category_movie.moviecategory_id', $this->categoryId)
            ->where('movies.status', 'ready')
            ->select('movies.*')
            ->orderBy('movies.created_at', 'desc')
            ->get();
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-16 space-y-10">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Jost:wght@700;900&display=swap');
    </style>

    {{-- ══════════════════ HEADER ══════════════════ --}}
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 border-b border-slate-800 pb-8 relative">
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-red-600/10 rounded-full blur-[100px] pointer-events-none"></div>

        <div class="relative z-10">
            <a href="/" wire:navigate class="inline-flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-white transition mb-5 uppercase tracking-widest bg-zinc-900 border border-slate-800 px-3 py-1.5 rounded-lg w-fit">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Back to Browse
            </a>
            <h1 class="text-4xl md:text-6xl font-black text-white uppercase tracking-tighter leading-none" style="font-family: 'Jost', sans-serif;">
                {{ $this->categoryName }}
            </h1>
            <p class="text-slate-400 mt-3 text-sm md:text-base font-medium">Explore our curated collection of {{ $this->categoryName }} movies.</p>
        </div>

        <div class="text-left md:text-right bg-black border border-slate-800 p-4 rounded-2xl relative z-10 w-full md:w-auto flex flex-col justify-center min-w-[140px]">
            <span class="text-4xl font-black text-red-600 leading-none" style="font-family: 'Jost', sans-serif;">{{ count($this->movies) }}</span>
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mt-1">Titles Available</span>
        </div>
    </div>

    {{-- ══════════════════ MOVIE GRID ══════════════════ --}}
    @if(count($this->movies) > 0)
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 md:gap-6">
            @foreach($this->movies as $movie)
                {{-- 🚨 FIXED: Now points to client.player 🚨 --}}
                <a href="{{ route('client.player', ['slug' => $movie->slug]) }}" wire:navigate class="group flex flex-col gap-3 outline-none">

                    <div class="relative aspect-[2/3] rounded-2xl overflow-hidden bg-[#111111] border border-slate-800 shadow-xl group-focus:ring-2 group-focus:ring-red-600 transition">

                        {{-- Poster Image --}}
                        @if($movie->thumbnail)
                            <img src="{{ str_starts_with($movie->thumbnail, 'http') ? $movie->thumbnail : Storage::disk('public')->url($movie->thumbnail) }}"
                                 alt="{{ $movie->title }}"
                                 class="w-full h-full object-cover transform group-hover:scale-105 transition duration-700 ease-in-out">
                        @else
                            <div class="w-full h-full flex flex-col items-center justify-center bg-zinc-900 border border-slate-800">
                                <svg class="w-8 h-8 text-slate-700 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                                <span class="text-[9px] font-bold text-slate-600 uppercase tracking-widest">No Poster</span>
                            </div>
                        @endif

                        {{-- Hover Dark Overlay --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-black via-black/20 to-transparent opacity-60 group-hover:opacity-90 transition duration-300"></div>

                        {{-- Premium Badge --}}
                        @if($movie->is_premium)
                            <div class="absolute top-3 right-3 bg-amber-500 text-black text-[9px] font-black px-2 py-1 rounded shadow-lg uppercase tracking-widest border border-amber-400">
                                Premium
                            </div>
                        @endif

                        {{-- Duration Badge --}}
                        <div class="absolute bottom-3 right-3 text-[10px] font-bold text-white bg-black/70 backdrop-blur-md px-2.5 py-1 rounded-md border border-white/10 shadow-lg flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ floor($movie->duration_in_seconds / 60) }}m
                        </div>

                        {{-- Hover Play Button --}}
                        <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 transform scale-75 group-hover:scale-100">
                            <div class="w-14 h-14 bg-red-600/90 backdrop-blur-sm rounded-full flex items-center justify-center shadow-[0_0_30px_rgba(220,38,38,0.5)] text-white pl-1 border border-red-500">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"/></svg>
                            </div>
                        </div>
                    </div>

                    {{-- Text Info --}}
                    <div class="px-1">
                        <h3 class="text-white font-bold text-sm md:text-base leading-tight group-hover:text-red-500 transition line-clamp-1" title="{{ $movie->title }}">{{ $movie->title }}</h3>
                        @if($movie->excerpt)
                            <p class="text-slate-500 text-xs mt-1.5 line-clamp-2 leading-relaxed">{{ $movie->excerpt }}</p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @else
        {{-- Empty State --}}
        <div class="flex flex-col items-center justify-center py-24 border-2 border-dashed border-slate-800 rounded-3xl bg-black/30 w-full">
            <div class="w-20 h-20 bg-zinc-900 rounded-full flex items-center justify-center mb-5 border border-slate-800 shadow-xl">
                <svg class="w-10 h-10 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
            </div>
            <h3 class="text-xl md:text-2xl font-black text-white mb-2" style="font-family: 'Jost', sans-serif;">No movies found</h3>
            <p class="text-slate-500 text-sm font-medium text-center max-w-sm">We're still adding content to the <span class="text-white font-bold">{{ $this->categoryName }}</span> category. Check back soon!</p>
        </div>
    @endif
</div>
