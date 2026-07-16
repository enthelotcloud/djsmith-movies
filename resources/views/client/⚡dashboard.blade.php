<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new #[Title('Dashboard')]
#[Layout('layouts.app')]
class extends Component
{
    public function getGreetingProperty()
    {
        $hour = Carbon::now()->timezone('Africa/Nairobi')->hour;
        if ($hour < 12) return 'Good Morning';
        if ($hour < 17) return 'Good Afternoon';
        return 'Good Evening';
    }

    public function getObfuscatedEmailProperty()
    {
        $user = Auth::user();
        $email = $user ? $user->email : 'user@example.com';
        $parts = explode("@", $email);
        if (count($parts) !== 2) return $email;
        $name = $parts[0];
        $domain = $parts[1];
        if (strlen($name) > 2) {
            $hidden = substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1);
        } else {
            $hidden = substr($name, 0, 1) . '*';
        }
        return $hidden . '@' . $domain;
    }

    public function getBalanceProperty()
    {
        $user = Auth::user()->fresh();
        return $user && $user->wallet_balance !== null ? number_format($user->wallet_balance, 2) : '0.00';
    }

    public function getUserNameProperty()
    {
        return Auth::user() ? Auth::user()->name : 'Guest';
    }

    public function getSubscriptionExpiryProperty()
    {
        $sub = DB::table('subscriptions')
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at', 'desc')
            ->first();

        return $sub ? Carbon::parse($sub->expires_at)->format('M d, Y') : null;
    }

    public function getHasActivePlanProperty()
    {
        return $this->subscriptionExpiry !== null;
    }
};
?>

<div class="max-w-7xl mx-auto space-y-6 sm:space-y-8">

    {{-- 1. PROFILE HEADER --}}
    <div class="bg-[#111111] border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-2xl">
        <div class="flex items-center gap-4 mb-5">
            <img src="https://ui-avatars.com/api/?name={{ urlencode($this->userName) }}&background=dc2626&color=fff&size=128&bold=true"
                 alt="Avatar"
                 class="w-14 h-14 sm:w-16 sm:h-16 rounded-full border-2 border-red-600 shadow-lg shadow-red-600/20 object-cover">
            <div class="min-w-0 flex-1">
                <p class="text-slate-400 text-[11px] font-bold tracking-widest uppercase">{{ $this->greeting }},</p>
                <h1 class="text-lg sm:text-xl font-black text-white truncate">{{ $this->userName }}</h1>
                <p class="text-slate-500 text-xs font-mono truncate">{{ $this->obfuscatedEmail }}</p>
            </div>
        </div>

        {{-- Balance + Expiry Cards --}}
        <div class="grid grid-cols-2 gap-3 mb-5">
            <div class="bg-black/50 border border-slate-800 rounded-2xl p-3 text-center">
                <div class="flex justify-center mb-1">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Balance</p>
                <p class="text-lg font-black text-emerald-400 mt-0.5">KES {{ $this->balance }}</p>
            </div>
            <div class="bg-black/50 border border-slate-800 rounded-2xl p-3 text-center">
                <div class="flex justify-center mb-1">
                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <p class="text-[10px] text-slate-500 uppercase font-bold tracking-widest">Expires</p>
                @if($this->hasActivePlan)
                    <p class="text-lg font-black text-white mt-0.5">{{ $this->subscriptionExpiry }}</p>
                @else
                    <p class="text-lg font-black text-slate-400 mt-0.5">—</p>
                @endif
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="space-y-3">
            <a href="{{ route('home') }}" wire:navigate
               class="flex items-center justify-center gap-2 w-full py-3.5 bg-white/5 hover:bg-white/10 active:bg-white/20 border border-slate-700 text-white text-sm font-bold rounded-xl transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                Browse Movies
            </a>
            <a href="{{ route('client.wallet-topup') }}" wire:navigate
               class="flex items-center justify-center gap-2 w-full py-3.5 bg-zinc-800 hover:bg-zinc-700 active:bg-zinc-600 text-white text-sm font-bold rounded-xl transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                Top Up Wallet
            </a>
            <a href="{{ route('client.subscriptions') }}" wire:navigate
               class="flex items-center justify-center gap-2 w-full py-3.5 bg-red-600 hover:bg-red-700 active:bg-red-800 text-white text-sm font-bold rounded-xl transition shadow-lg shadow-red-600/20">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                Manage Plan
            </a>
        </div>
    </div>

    {{-- 2. LIVE EVENT PROMO --}}
    <div class="bg-gradient-to-r from-red-900 to-black border border-red-900/50 rounded-3xl p-5 sm:p-6 shadow-2xl relative overflow-hidden">
        <div class="absolute top-4 right-4 flex items-center gap-2 px-3 py-1 bg-red-600/20 border border-red-500/50 rounded-full">
            <span class="w-2 h-2 rounded-full bg-red-500 animate-ping"></span>
            <span class="w-2 h-2 rounded-full bg-red-500 absolute"></span>
            <span class="text-[10px] font-black text-red-500 uppercase tracking-widest">Live Soon</span>
        </div>

        <div class="relative z-10">
            <h3 class="text-red-500 font-bold text-xs tracking-widest uppercase mb-1">Exclusive Broadcast</h3>
            <h2 class="text-2xl sm:text-4xl font-black text-white leading-tight uppercase tracking-tighter">August Holiday Slam</h2>
            <p class="text-slate-300 mt-2 text-xs sm:text-sm">Live commentary by <strong class="text-white">DJ Smith</strong> from the studio.</p>

            <div x-data="{
                    end: new Date('August 4, 2026 20:00:00').getTime(),
                    now: new Date().getTime(),
                    get distance() { return this.end - this.now; },
                    get days() { return Math.max(0, Math.floor(this.distance / (1000 * 60 * 60 * 24))).toString().padStart(2, '0'); },
                    get hours() { return Math.max(0, Math.floor((this.distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))).toString().padStart(2, '0'); },
                    get minutes() { return Math.max(0, Math.floor((this.distance % (1000 * 60 * 60)) / (1000 * 60))).toString().padStart(2, '0'); },
                    get seconds() { return Math.max(0, Math.floor((this.distance % (1000 * 60)) / 1000)).toString().padStart(2, '0'); }
                 }"
                 x-init="setInterval(() => now = new Date().getTime(), 1000)"
                 class="mt-5 flex items-center gap-1.5 sm:gap-2">
                <div class="flex flex-col items-center bg-black/40 border border-white/10 rounded-xl px-2.5 py-2 min-w-[52px]">
                    <span class="text-lg sm:text-2xl font-black text-white font-mono" x-text="days"></span>
                    <span class="text-[8px] sm:text-[10px] text-red-400 uppercase font-bold tracking-widest">Days</span>
                </div>
                <span class="text-red-500 font-black text-lg sm:text-xl">:</span>
                <div class="flex flex-col items-center bg-black/40 border border-white/10 rounded-xl px-2.5 py-2 min-w-[52px]">
                    <span class="text-lg sm:text-2xl font-black text-white font-mono" x-text="hours"></span>
                    <span class="text-[8px] sm:text-[10px] text-red-400 uppercase font-bold tracking-widest">Hrs</span>
                </div>
                <span class="text-red-500 font-black text-lg sm:text-xl">:</span>
                <div class="flex flex-col items-center bg-black/40 border border-white/10 rounded-xl px-2.5 py-2 min-w-[52px]">
                    <span class="text-lg sm:text-2xl font-black text-white font-mono" x-text="minutes"></span>
                    <span class="text-[8px] sm:text-[10px] text-red-400 uppercase font-bold tracking-widest">Min</span>
                </div>
                <span class="text-red-500 font-black text-lg sm:text-xl">:</span>
                <div class="flex flex-col items-center bg-black/40 border border-white/10 rounded-xl px-2.5 py-2 min-w-[52px]">
                    <span class="text-lg sm:text-2xl font-black text-white font-mono" x-text="seconds"></span>
                    <span class="text-[8px] sm:text-[10px] text-red-400 uppercase font-bold tracking-widest">Sec</span>
                </div>
            </div>

            <button disabled class="mt-5 px-6 py-2.5 bg-white/10 text-white text-xs sm:text-sm font-bold rounded-xl cursor-not-allowed border border-white/20">
                Link Available Soon
            </button>
        </div>
    </div>

    {{-- 3. CONTENT PLACEHOLDERS --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 sm:gap-6">
        <div class="bg-[#111111] border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-lg">
            <div class="flex items-center gap-2 mb-4">
                <div class="p-1.5 bg-zinc-900 rounded-lg text-emerald-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white">Continue Watching</h3>
            </div>
            <div class="flex flex-col items-center justify-center py-8 text-center border-2 border-dashed border-slate-800 rounded-2xl bg-black/20">
                <svg class="w-10 h-10 text-slate-700 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z"/></svg>
                <p class="text-slate-400 font-bold text-xs">Empty</p>
            </div>
        </div>

        <div class="bg-[#111111] border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-lg">
            <div class="flex items-center gap-2 mb-4">
                <div class="p-1.5 bg-zinc-900 rounded-lg text-red-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white">My Favourites</h3>
            </div>
            <div class="flex flex-col items-center justify-center py-8 text-center border-2 border-dashed border-slate-800 rounded-2xl bg-black/20">
                <svg class="w-10 h-10 text-slate-700 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
                <p class="text-slate-400 font-bold text-xs">Empty</p>
            </div>
        </div>
    </div>
</div>
