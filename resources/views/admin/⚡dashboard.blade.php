<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\{DB, Auth};
use Carbon\Carbon;

new #[Layout('layouts.app')] // Adjust this layout name if yours is different
#[Title('Admin Dashboard')]
class extends Component
{
    // The poll directive in the view automatically triggers updates,
    // so we just rely on Computed properties to fetch fresh data every cycle.

    #[Computed]
    public function greeting()
    {
        $hour = now()->format('H');

        if ($hour < 12) return 'Good Morning';
        if ($hour < 17) return 'Good Afternoon';
        return 'Good Evening';
    }

    #[Computed]
    public function userStats()
    {
        // Excluded specific test/close client IDs
        $excludedIds = [1, 2, 3, 4, 5, 12];

        // Base query applying the exclusion rule
        $baseQuery = DB::table('users')->whereNotIn('id', $excludedIds);

        return [
            'clients' => (clone $baseQuery)->where('role', 'client')->count(),
            'staff'   => (clone $baseQuery)->where('role', 'staff')->count(),
            'admins'  => (clone $baseQuery)->where('role', 'admin')->count(),
        ];
    }

    #[Computed]
    public function activeSessions()
    {
        // Counting users who have pinged the heartbeat within the last 5 minutes
        return DB::table('users')
            ->where('last_active_at', '>=', now()->subMinutes(5))
            ->count();
    }

    #[Computed]
    public function movieStats()
    {
        return [
            'total' => DB::table('movies')->count(),
            'today' => DB::table('movies')->whereDate('created_at', now()->today())->count(),
        ];
    }

    #[Computed]
    public function mostWatched()
    {
        // Groups by movie_id to find the highest view count
        return DB::table('watch_histories')
            ->join('movies', 'watch_histories.movie_id', '=', 'movies.id')
            ->select('movies.title', DB::raw('count(watch_histories.id) as views'))
            ->groupBy('movies.id', 'movies.title')
            ->orderByDesc('views')
            ->first();
    }
};
?>

{{-- Main Container (No padding, no background, max-w-7xl as requested) --}}
{{-- Polls every 15 seconds to fetch live data seamlessly --}}
<div class="max-w-7xl mx-auto w-full" wire:poll.15s>

    {{-- Header Section with Dynamic Greeting & Live Clock --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 mb-8 sm:mb-10">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 mb-4 rounded-full bg-red-600/10 border border-red-500/20">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                </span>
                <span class="text-[10px] font-bold text-red-500 uppercase tracking-widest">Live Updates Active</span>
            </div>

            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-black text-white tracking-tight">
                {{ $this->greeting }}, {{ Auth::user()->name ?? 'Admin' }}
            </h1>
            <p class="text-slate-400 mt-2 text-sm sm:text-base">Here is what is happening across your platform right now.</p>
        </div>

        {{-- Alpine.js Live Clock --}}
        <div x-data="{
                time: '',
                updateTime() {
                    this.time = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute:'2-digit', second:'2-digit' });
                }
             }"
             x-init="updateTime(); setInterval(() => updateTime(), 1000)"
             class="flex items-center gap-3 bg-[#111111] border border-slate-800 rounded-2xl px-5 py-3 shadow-xl">
            <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="text-xl font-mono font-bold text-white tracking-widest" x-text="time"></span>
        </div>
    </div>

    {{-- System Status & Active Sessions --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        {{-- Active Sessions Spotlight Card --}}
        <div class="lg:col-span-2 bg-gradient-to-br from-[#111111] to-zinc-900 border border-slate-800 rounded-3xl p-8 relative overflow-hidden shadow-2xl">
            {{-- Decorative Background --}}
            <div class="absolute -right-10 -top-10 w-64 h-64 bg-red-600/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div>
                    <h2 class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-1">Current Active Sessions</h2>
                    <div class="flex items-baseline gap-3">
                        <span class="text-6xl font-black text-white tracking-tighter">{{ $this->activeSessions }}</span>
                        <span class="text-slate-500 font-medium">Users Online</span>
                    </div>
                </div>

                <div class="w-16 h-16 bg-red-600/10 border border-red-500/20 text-red-500 rounded-2xl flex items-center justify-center rotate-3 shadow-lg shadow-red-900/20">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>
        </div>

        {{-- Top Watched Movie Spotlight --}}
        <div class="bg-[#111111] border border-slate-800 rounded-3xl p-8 flex flex-col justify-center shadow-xl relative overflow-hidden group">
            <div class="absolute inset-0 bg-gradient-to-t from-black via-transparent to-transparent opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center gap-2 mb-4">
                    <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <span class="text-xs font-bold text-amber-500 uppercase tracking-widest">Most Watched Title</span>
                </div>

                @if($this->mostWatched)
                    <h3 class="text-xl sm:text-2xl font-black text-white leading-tight mb-2 truncate" title="{{ $this->mostWatched->title }}">
                        {{ $this->mostWatched->title }}
                    </h3>
                    <p class="text-sm font-bold text-slate-400">
                        {{ number_format($this->mostWatched->views) }} Total Views
                    </p>
                @else
                    <h3 class="text-xl font-bold text-slate-500">No data yet</h3>
                @endif
            </div>
        </div>
    </div>

    {{-- Platform Metrics --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        {{-- User Roles Breakdowns --}}
        <div class="bg-[#111111] border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
            <h3 class="text-lg font-black text-white mb-6">User Base Breakdown</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                <div class="bg-zinc-900/50 border border-slate-700/50 rounded-2xl p-5 hover:border-blue-500/30 transition-colors">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Clients</p>
                    <p class="text-2xl font-black text-white">{{ number_format($this->userStats['clients']) }}</p>
                </div>

                <div class="bg-zinc-900/50 border border-slate-700/50 rounded-2xl p-5 hover:border-emerald-500/30 transition-colors">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Staff</p>
                    <p class="text-2xl font-black text-white">{{ number_format($this->userStats['staff']) }}</p>
                </div>

                <div class="bg-zinc-900/50 border border-slate-700/50 rounded-2xl p-5 hover:border-purple-500/30 transition-colors">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Admins</p>
                    <p class="text-2xl font-black text-white">{{ number_format($this->userStats['admins']) }}</p>
                </div>

            </div>
            <p class="text-xs text-slate-600 font-medium mt-4">* Excludes specific internal test accounts.</p>
        </div>

        {{-- Content Metrics --}}
        <div class="bg-[#111111] border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
            <h3 class="text-lg font-black text-white mb-6">Library Statistics</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div class="flex items-center gap-5 bg-zinc-900/50 border border-slate-700/50 rounded-2xl p-5">
                    <div class="w-12 h-12 bg-slate-800 rounded-xl flex items-center justify-center text-slate-400">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Total Movies</p>
                        <p class="text-2xl font-black text-white">{{ number_format($this->movieStats['total']) }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-5 bg-zinc-900/50 border border-slate-700/50 rounded-2xl p-5">
                    <div class="w-12 h-12 bg-emerald-500/10 border border-emerald-500/20 rounded-xl flex items-center justify-center text-emerald-500">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-emerald-500 uppercase tracking-widest mb-0.5">Uploaded Today</p>
                        <p class="text-2xl font-black text-white">+{{ number_format($this->movieStats['today']) }}</p>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>
