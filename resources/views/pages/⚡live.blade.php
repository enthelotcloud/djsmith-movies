<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Title('Live TV - Coming Soon')]
#[Layout('layouts.guest.app')]
class extends Component
{
    // Ready for future logic
};
?>

<div class="min-h-[85vh] bg-black relative flex flex-col items-center justify-center px-4 overflow-hidden pt-20">

    {{-- Background Glow & Grid Effects --}}
    <div class="absolute inset-0 z-0 flex items-center justify-center pointer-events-none">
        <div class="absolute w-[500px] h-[500px] bg-red-600/20 rounded-full blur-[120px]"></div>
        <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cdefs%3E%3Cpattern id=\"grid\" width=\"60\" height=\"60\" patternUnits=\"userSpaceOnUse\"%3E%3Cpath d=\"M 60 0 L 0 0 0 60\" fill=\"none\" stroke=\"white\" stroke-width=\"0.5\"/%3E%3C/pattern%3E%3C/defs%3E%3Crect width=\"100%25\" height=\"100%25\" fill=\"url(%23grid)\"/%3E%3C/svg%3E')] opacity-[0.03]"></div>
    </div>

    {{-- Content Container --}}
    <div class="relative z-10 text-center max-w-2xl mx-auto space-y-8 mt-10 lg:mt-0">

        {{-- Floating Icon --}}
        <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-zinc-900 border border-slate-800 shadow-2xl shadow-red-600/20 mb-2 relative">
            <div class="absolute inset-0 rounded-full border border-red-500/30 animate-ping opacity-20"></div>
            <svg class="w-12 h-12 text-red-500 drop-shadow-[0_0_15px_rgba(239,68,68,0.5)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        </div>

        <div class="space-y-6">
            {{-- Badge --}}
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-600/10 border border-red-500/20 backdrop-blur-sm shadow-xl">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                </span>
                <span class="text-[10px] font-black text-red-500 uppercase tracking-[0.2em]">In Development</span>
            </div>

            {{-- Typography --}}
            <h1 class="text-4xl md:text-6xl lg:text-7xl font-black text-white tracking-tight leading-none">
                Live TV & <br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-red-500 to-red-700">Community</span>
            </h1>

            <p class="text-base md:text-lg text-slate-400 max-w-lg mx-auto leading-relaxed font-medium">
                We are building an interactive live streaming experience. Soon, you'll be able to watch scheduled events live and chat with other movie fans in real-time.
            </p>
        </div>

        {{-- Actions --}}
        <div class="pt-6 flex flex-col sm:flex-row gap-4 justify-center items-center">
            <a href="/" wire:navigate class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl border border-slate-700 bg-[#111111] hover:border-white hover:bg-white/5 text-white font-bold text-lg transition-all duration-300 shadow-xl">
                <svg class="w-5 h-5 text-slate-400 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Home
            </a>
            <a href="{{ route('client.search') }}" wire:navigate class="group inline-flex items-center justify-center gap-3 px-8 py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold text-lg transition-all duration-300 shadow-[0_0_30px_rgba(220,38,38,0.3)] hover:shadow-[0_0_40px_rgba(220,38,38,0.5)]">
                Browse Library
                <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </a>
        </div>

    </div>
</div>
