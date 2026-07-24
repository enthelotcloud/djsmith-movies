<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

new #[Layout('layouts.guest.app')] class extends Component {
    public $slug;
    public $series;
    public $activeSeasonId = null;
    
    // Paywall State
    public $isLoggedIn = false;
    public $hasActiveSub = false;

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->series = DB::table('series')->where('slug', $this->slug)->first();

        if (!$this->series) {
            abort(404, 'Series not found.');
        }

        // Auto-select the first season
        $firstSeason = $this->seasons->first();
        if ($firstSeason) {
            $this->activeSeasonId = $firstSeason->id;
        }

        // 🛡️ Paywall Check Initialization
        $this->isLoggedIn = Auth::check();
        if ($this->isLoggedIn) {
            $this->hasActiveSub = DB::table('subscriptions')
                ->where('user_id', Auth::id())
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();
        }
    }

    #[Title('Watch Series')]
    public function getTitle()
    {
        return $this->series ? $this->series->title . ' - Dj Smith Movies' : 'Series';
    }

    #[Computed]
    public function seasons()
    {
        return DB::table('seasons')
            ->where('series_id', $this->series->id)
            ->orderBy('created_at', 'asc') // Assuming earlier created = Season 1, etc.
            ->get();
    }

    #[Computed]
    public function episodes()
    {
        if (!$this->activeSeasonId) return [];

        $userId = Auth::id() ?? 0;

        return DB::table('episodes')
            ->join('episode_season', 'episodes.id', '=', 'episode_season.episode_id')
            // Left join watch history to track progress for the logged-in user
            ->leftJoin('episode_watch_histories', function($join) use ($userId) {
                $join->on('episodes.id', '=', 'episode_watch_histories.episode_id')
                     ->where('episode_watch_histories.user_id', '=', $userId);
            })
            ->where('episode_season.season_id', $this->activeSeasonId)
            ->where('episodes.status', 'ready')
            ->select(
                'episodes.*', 
                'episode_watch_histories.progress_seconds', 
                'episode_watch_histories.is_completed'
            )
            ->orderBy('episodes.id', 'asc')
            ->get();
    }

    public function setActiveSeason($seasonId)
    {
        $this->activeSeasonId = $seasonId;
    }

    public function formatDuration($seconds)
    {
        if (!$seconds) return '0m';
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }

    // 🛡️ 100% Paywall Gatekeeper for Play Buttons
    public function getActionUrl($episodeSlug)
    {
        if (!$this->isLoggedIn) {
            return route('login');
        }

        if (!$this->hasActiveSub) {
            return route('client.subscriptions');
        }

        return "/series/watch/" . $episodeSlug;
    }
};
?>

<div class="min-h-screen bg-black pb-20">
    {{-- 🎬 HERO OVERVIEW SECTION --}}
    <div class="relative w-full min-h-[65vh] flex items-end pb-12 pt-32">
        {{-- Background Image & Gradient --}}
        <div class="absolute inset-0 z-0">
            @if($series->poster)
                <img src="{{ str_starts_with($series->poster, 'http') ? $series->poster : Storage::disk('public')->url($series->poster) }}"
                     class="w-full h-full object-cover object-top opacity-40 blur-sm scale-105"
                     alt="{{ $series->title }} Background">
            @else
                <div class="w-full h-full bg-zinc-900"></div>
            @endif

            <div class="absolute inset-0 bg-gradient-to-t from-black via-black/80 to-transparent"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-black via-black/50 to-transparent"></div>
        </div>

        {{-- Content --}}
        <div class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 w-full flex flex-col md:flex-row gap-8 items-end">
            {{-- Series Poster --}}
            <div class="hidden md:block flex-shrink-0 w-64 rounded-2xl overflow-hidden shadow-2xl border border-slate-700/50 relative group">
                @if($series->poster)
                    <img src="{{ str_starts_with($series->poster, 'http') ? $series->poster : Storage::disk('public')->url($series->poster) }}"
                         class="w-full aspect-[2/3] object-cover group-hover:scale-105 transition-transform duration-700"
                         alt="{{ $series->title }} Poster">
                @else
                    <div class="w-full aspect-[2/3] bg-zinc-800 flex items-center justify-center">
                        <span class="text-slate-500 font-bold uppercase">No Poster</span>
                    </div>
                @endif
            </div>

            {{-- Metadata --}}
            <div class="flex-grow space-y-4 max-w-3xl">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-indigo-600/20 border border-indigo-500/30 backdrop-blur-sm">
                    <span class="text-[10px] font-black text-indigo-400 uppercase tracking-widest">Series Overview</span>
                </div>

                <h1 class="text-4xl sm:text-6xl font-black text-white tracking-tight drop-shadow-lg">
                    {{ $series->title }}
                </h1>

                <div class="flex items-center gap-4 text-sm font-bold text-slate-300 drop-shadow">
                    <span class="capitalize">{{ $series->status === 'ready' ? 'Completed' : $series->status }}</span>
                    <span class="text-slate-600">•</span>
                    <span>{{ $this->seasons->count() }} {{ Str::plural('Season', $this->seasons->count()) }}</span>
                </div>

                <p class="text-base sm:text-lg text-slate-300 leading-relaxed drop-shadow max-w-2xl">
                    {{ $series->description }}
                </p>

                @if($series->trailer_url)
                    <div class="pt-4">
                        <a href="{{ $series->trailer_url }}" target="_blank" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-zinc-800/80 hover:bg-zinc-700 border border-slate-700 text-white font-bold transition backdrop-blur-sm">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Watch Trailer
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- 📂 SEASONS & EPISODES BROWSER --}}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-12 relative z-20">

        @if($this->seasons->isEmpty())
            <div class="p-12 text-center bg-zinc-900 border border-slate-800 rounded-2xl">
                <svg class="w-12 h-12 text-slate-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                <h3 class="text-xl font-bold text-white mb-2">No Seasons Available</h3>
                <p class="text-slate-500 text-sm">Episodes for this series have not been released yet.</p>
            </div>
        @else
            {{-- SEASON SELECTOR TABS --}}
            <div class="flex items-center gap-3 overflow-x-auto pb-4 scrollbar-hide border-b border-slate-800/80 mb-6">
                @foreach($this->seasons as $season)
                    <button wire:click="setActiveSeason({{ $season->id }})"
                            class="whitespace-nowrap px-6 py-3 rounded-full text-sm font-black transition-all duration-300 {{ $activeSeasonId === $season->id ? 'bg-red-600 text-white shadow-[0_0_15px_rgba(220,38,38,0.4)]' : 'bg-zinc-900 text-slate-400 hover:text-white hover:bg-zinc-800 border border-slate-800' }}">
                        {{ $season->name }}
                    </button>
                @endforeach
            </div>

            {{-- EPISODE LIST (Vertical Netflix-style Stack) --}}
            <div class="space-y-4">
                @forelse($this->episodes as $index => $episode)
                    @php
                        $watchUrl = $this->getActionUrl($episode->slug);
                        $epThumb = $episode->thumbnail ?? $series->poster ?? null;
                        
                        // Watch Progress Calculations
                        $progressPercent = 0;
                        $isWatched = false;
                        if ($episode->duration_in_seconds > 0 && $episode->progress_seconds > 0) {
                            $progressPercent = ($episode->progress_seconds / $episode->duration_in_seconds) * 100;
                            $progressPercent = min(100, max(0, $progressPercent)); 
                        }
                        
                        if ($episode->is_completed || $progressPercent > 90) {
                            $isWatched = true;
                        }

                        $isLocked = !$this->isLoggedIn || !$this->hasActiveSub;
                    @endphp

                    {{-- Apply dimming/grayscale to the card if it has been watched --}}
                    <div class="group relative flex flex-col sm:flex-row items-center gap-4 sm:gap-6 p-3 sm:p-4 bg-zinc-900/50 hover:bg-zinc-800/80 border border-slate-800 hover:border-slate-700 rounded-2xl transition-all duration-300 {{ $isWatched ? 'opacity-60 grayscale-[40%]' : '' }}">

                        {{-- Episode Number --}}
                        <div class="hidden sm:flex items-center justify-center w-12 text-2xl font-black text-slate-700 group-hover:text-slate-400 transition-colors">
                            {{ $index + 1 }}
                        </div>

                        {{-- Episode Thumbnail --}}
                        <div class="relative w-full sm:w-48 aspect-video rounded-xl overflow-hidden bg-zinc-950 flex-shrink-0">
                            @if($epThumb)
                                <img src="{{ str_starts_with($epThumb, 'http') ? $epThumb : Storage::disk('public')->url($epThumb) }}"
                                     class="w-full h-full object-cover opacity-80 group-hover:opacity-100 group-hover:scale-105 transition-all duration-500"
                                     alt="{{ $episode->title }}" loading="lazy">
                            @endif

                            {{-- Play Icon Overlay (Only show if not locked) --}}
                            @if(!$isLocked)
                                <a href="{{ $watchUrl }}" wire:navigate class="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity z-10">
                                    <div class="w-12 h-12 rounded-full bg-red-600 flex items-center justify-center shadow-lg transform scale-90 group-hover:scale-100 transition-all">
                                        <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4l12 6-12 6z"/></svg>
                                    </div>
                                </a>
                            @endif

                            {{-- Lock Overlay for unsubscribed users --}}
                            @if($isLocked)
                                <div class="absolute inset-0 bg-black/60 flex items-center justify-center backdrop-blur-[1px] z-10">
                                    <svg class="w-8 h-8 text-amber-500/80" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C9.243 2 7 4.243 7 7v3H6c-1.103 0-2 .897-2 2v8c0 1.103.897 2 2 2h12c1.103 0 2-.897 2-2v-8c0-1.103-.897-2-2-2h-1V7c0-2.757-2.243-5-5-5zm-3 5c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm8 5v8H5v-8h14zM12 14c-1.104 0-2 .896-2 2s.896 2 2 2 2-.896 2-2-.896-2-2-2z"/></svg>
                                </div>
                            @endif

                            {{-- Progress Bar (Redline at the bottom) --}}
                            @if($progressPercent > 0)
                                <div class="absolute bottom-0 left-0 right-0 h-1 bg-zinc-800 z-20">
                                    <div class="h-full bg-red-600 shadow-[0_0_10px_rgba(220,38,38,0.8)]" style="width: {{ $progressPercent }}%;"></div>
                                </div>
                            @endif

                            {{-- Duration Badge (Pushed up slightly if progress bar exists) --}}
                            @if($episode->duration_in_seconds)
                                <div class="absolute right-2 px-1.5 py-0.5 bg-black/80 rounded text-[9px] font-bold text-white border border-white/10 z-20 {{ $progressPercent > 0 ? 'bottom-2.5' : 'bottom-2' }}">
                                    {{ $this->formatDuration($episode->duration_in_seconds) }}
                                </div>
                            @endif
                        </div>

                        {{-- Episode Metadata --}}
                        <div class="flex-grow w-full">
                            <div class="flex items-center justify-between gap-4 mb-1">
                                <h3 class="text-lg font-bold text-white group-hover:text-red-400 transition-colors">
                                    {{ $episode->title }}
                                </h3>

                                <span class="px-2 py-0.5 bg-amber-500 text-black text-[9px] font-black uppercase tracking-widest rounded shadow border border-amber-400 flex-shrink-0">Premium</span>
                            </div>

                            @if($episode->excerpt)
                                <p class="text-sm text-slate-400 line-clamp-2 leading-relaxed">
                                    {{ $episode->excerpt }}
                                </p>
                            @elseif($episode->description)
                                <p class="text-sm text-slate-400 line-clamp-2 leading-relaxed">
                                    {{ $episode->description }}
                                </p>
                            @endif
                        </div>

                        {{-- Action Area (Mobile shows as block, desktop shows on right) --}}
                        <div class="w-full sm:w-auto mt-2 sm:mt-0 flex-shrink-0">
                            @if($isLocked)
                                <a href="{{ $watchUrl }}" wire:navigate class="block w-full text-center px-6 py-3 rounded-xl bg-amber-500/10 hover:bg-amber-500 text-amber-500 hover:text-black border border-amber-500/30 hover:border-amber-500 font-bold text-sm transition-all shadow-sm">
                                    Subscribe to Watch
                                </a>
                            @else
                                <a href="{{ $watchUrl }}" wire:navigate class="block w-full text-center px-6 py-3 rounded-xl bg-red-600/10 hover:bg-red-600 text-red-500 hover:text-white border border-red-500/20 hover:border-red-600 font-bold text-sm transition-all shadow-sm">
                                    {{ $progressPercent > 0 && !$isWatched ? 'Resume' : 'Play' }}
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="py-12 text-center text-slate-500 italic border border-dashed border-slate-800 rounded-2xl">
                        No episodes have been uploaded for this season yet.
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</div>