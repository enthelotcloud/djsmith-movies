<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component
{
    public $search = '';
    public $searchResults = [];
    public $showUserMenu = false;
    public $showMobileMenu = false;
    public $currentRoute = '';

    // Auth & Subscriptions State for the search locks
    public $isLoggedIn = false;
    public $hasActiveSub = false;

    public function mount()
    {
        $this->currentRoute = request()->route()->getName();

        $this->isLoggedIn = Auth::check();
        if ($this->isLoggedIn) {
            $this->hasActiveSub = DB::table('subscriptions')
                ->where('user_id', Auth::id())
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();
        }
    }

    public function updatedSearch()
    {
        if (strlen($this->search) > 1) {
            $this->searchResults = DB::table('movies')
                ->where('status', 'ready')
                ->where(function($query) {
                    $query->where('title', 'like', '%' . $this->search . '%')
                          ->orWhere('description', 'like', '%' . $this->search . '%');
                })
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($movie) {
                    return [
                        'title' => $movie->title,
                        'slug' => $movie->slug,
                        'excerpt' => Str::limit($movie->description, 50),
                        'poster' => $this->getPosterUrl($movie->thumbnail ?? $movie->thumbnail_path ?? null),
                        'is_premium' => $movie->is_premium // Fetched to check if we need to lock it
                    ];
                })
                ->toArray();
        } else {
            $this->searchResults = [];
        }
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

    public function submitSearch()
    {
        if (trim($this->search) !== '') {
            $this->redirectRoute('client.search', ['q' => $this->search], navigate: true);
        }
    }

    public function goToDashboard()
    {
        $user = Auth::user();

        if ($user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        } elseif ($user->hasRole('staff')) {
            return redirect()->route('staff.dashboard');
        } else {
            return redirect()->route('client.dashboard');
        }
    }

    public function logout()
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect()->route('login');
    }

    private function isActive($route)
    {
        return $this->currentRoute === $route;
    }
};
?>

<div>
    {{-- ========================================== --}}
    {{-- TOP NAVIGATION BAR (Desktop & Mobile) --}}
    {{-- ========================================== --}}
    <div
        class="fixed top-0 left-0 right-0 z-50 transition-all duration-500"
        x-data="{
            scrolled: false,
            mobileMenu: false,
            searchFocused: false,
            history: JSON.parse(localStorage.getItem('dj_search_history') || '[]'),

            saveSearch(term) {
                if (!term || term.trim() === '') return;
                let arr = this.history.filter(i => i.toLowerCase() !== term.toLowerCase());
                arr.unshift(term);
                if (arr.length > 5) arr.pop();
                this.history = arr;
                localStorage.setItem('dj_search_history', JSON.stringify(this.history));
            },

            clearHistory() {
                this.history = [];
                localStorage.removeItem('dj_search_history');
            },

            executeSearch(term) {
                this.saveSearch(term);
                $wire.search = term;
                $wire.submitSearch();
                this.searchFocused = false;
            }
        }"
        x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 50 })"
        :class="{ 'bg-black/80 backdrop-blur-2xl border-b border-white/5 shadow-2xl shadow-black/50': scrolled, 'bg-gradient-to-b from-black/90 to-transparent': !scrolled }"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20 gap-2 sm:gap-4">

                {{-- Left: Logo & Nav --}}
                <div class="flex items-center gap-8 shrink-0">
                    <a href="/" class="flex items-center gap-3 group shrink-0" wire:navigate>
                        <div class="relative">
                            <img
                                src="{{ asset('logo.png') }}"
                                alt="Dj Smith Movies"
                                class="h-8 lg:h-10 w-auto transition-transform duration-300 group-hover:scale-105"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                            <div class="hidden h-8 lg:h-10 w-8 lg:w-10 bg-gradient-to-br from-red-600 to-red-800 rounded-xl items-center justify-center shadow-lg shadow-red-600/20">
                                <svg class="w-5 h-5 lg:w-6 lg:h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M18 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12zm-1 2H7a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1zm-2 7l-4-3v6l4-3z"/>
                                </svg>
                            </div>
                        </div>
                        <span class="hidden sm:block text-xl lg:text-2xl font-black text-white tracking-tight">
                            Dj<span class="text-red-600">Smith</span>
                        </span>
                    </a>

                    {{-- Desktop Navigation Links --}}
                    <nav class="hidden lg:flex items-center gap-1">
                        <a href="/" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2 rounded-lg hover:bg-white/5 transition-all duration-200">Home</a>
                        <a href="{{ route('client.search') }}" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2 rounded-lg hover:bg-white/5 transition-all duration-200">Browse</a>
                        @auth
                            <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2 rounded-lg hover:bg-white/5 transition-all duration-200">Plans</a>
                        @endauth
                    </nav>
                </div>

                {{-- Center: Unified Search (Visible on all screens, flex-1 allows scaling) --}}
                <div class="flex flex-1 max-w-md mx-1 sm:mx-4 lg:mx-8 relative z-50">
                    <form wire:submit.prevent="submitSearch" @submit="saveSearch($wire.search)" class="w-full relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-500 group-focus-within:text-red-400 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            @focus="searchFocused = true"
                            @click.outside="searchFocused = false"
                            placeholder="Search..."
                            class="w-full pl-9 sm:pl-10 pr-9 sm:pr-10 py-2 sm:py-2.5 bg-white/5 border border-white/10 rounded-full text-sm text-white placeholder-gray-500 focus:outline-none focus:border-red-500/50 focus:bg-black/80 focus:ring-2 focus:ring-red-500/20 transition-all duration-300"
                        >
                        @if($search)
                            <button type="button" wire:click="$set('search', '')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-500 hover:text-white">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        @endif
                    </form>

                    {{-- Search Dropdown --}}
                    <div x-show="searchFocused"
                         x-transition
                         class="absolute top-full left-0 w-full mt-2 bg-gray-900/95 backdrop-blur-2xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 overflow-hidden z-50"
                         style="display: none;">

                        <div x-show="$wire.search.length < 2 && history.length > 0" class="p-3">
                            <div class="flex items-center justify-between mb-2 px-2">
                                <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Recent</span>
                                <button type="button" @click="clearHistory()" class="text-[10px] text-red-500 hover:text-red-400">Clear</button>
                            </div>
                            <ul class="space-y-1">
                                <template x-for="term in history" :key="term">
                                    <li>
                                        <button type="button" @click="executeSearch(term)" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-gray-400 hover:text-white hover:bg-white/5 rounded-xl transition">
                                            <svg class="w-4 h-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span x-text="term"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div x-show="$wire.search.length >= 2">
                            @if(count($searchResults) > 0)
                                <div class="p-2 space-y-1">
                                    @foreach($searchResults as $result)
                                        @php
                                            // Determine if this result needs to be locked based on subscription
                                            $isLocked = $result['is_premium'] && (!$isLoggedIn || !$hasActiveSub);
                                            $actionUrl = $isLocked ? ($isLoggedIn ? route('client.subscriptions') : route('login')) : route('client.player', ['slug' => $result['slug']]);
                                        @endphp

                                        <a href="{{ $actionUrl }}" wire:navigate class="flex items-center gap-4 p-2 hover:bg-white/5 rounded-xl transition group">

                                            <div class="relative w-10 h-14 flex-shrink-0">
                                                @if($result['poster'])
                                                    <img src="{{ $result['poster'] }}" class="w-full h-full object-cover rounded-lg shadow group-hover:scale-105 transition">
                                                @else
                                                    <div class="w-full h-full bg-gray-800 rounded-lg flex items-center justify-center text-[8px] text-gray-600">No Img</div>
                                                @endif

                                                {{-- 🔒 The Lock Overlay --}}
                                                @if($isLocked)
                                                    <div class="absolute inset-0 bg-black/60 rounded-lg flex items-center justify-center backdrop-blur-[1px]">
                                                        <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C9.243 2 7 4.243 7 7v3H6c-1.103 0-2 .897-2 2v8c0 1.103.897 2 2 2h12c1.103 0 2-.897 2-2v-8c0-1.103-.897-2-2-2h-1V7c0-2.757-2.243-5-5-5zm-3 5c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm8 5v8H5v-8h14zM12 14c-1.104 0-2 .896-2 2s.896 2 2 2 2-.896 2-2-.896-2-2-2z"/></svg>
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="flex-1 overflow-hidden">
                                                <div class="flex items-center gap-2">
                                                    <h4 class="text-sm font-bold text-white truncate group-hover:text-red-400 transition">{{ $result['title'] }}</h4>
                                                    @if($isLocked)
                                                        <span class="text-[8px] font-bold text-amber-500 uppercase tracking-widest border border-amber-500/30 px-1 rounded">Premium</span>
                                                    @endif
                                                </div>
                                                <p class="text-[11px] text-gray-500 truncate mt-0.5">{{ $result['excerpt'] }}</p>
                                            </div>
                                        </a>
                                    @endforeach
                                    <button type="button" @click="executeSearch($wire.search)" class="w-full mt-2 py-2.5 text-xs font-bold text-white bg-white/5 hover:bg-red-600 rounded-xl transition-all">
                                        View all results
                                    </button>
                                </div>
                            @else
                                <div class="p-6 text-center text-gray-500 text-sm">
                                    No results for "<span class="text-white" x-text="$wire.search"></span>"
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Right: Actions --}}
                <div class="flex items-center gap-1 sm:gap-4 shrink-0">
                    @auth
                        {{-- Notifications --}}
                        <button class="relative p-2 text-gray-400 hover:text-white rounded-full hover:bg-white/10 transition-all duration-200">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full ring-2 ring-black animate-pulse"></span>
                        </button>

                        {{-- User Menu (Desktop) --}}
                        <div class="relative hidden lg:block" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false" class="flex items-center gap-2 p-1 rounded-full hover:bg-white/10 transition-all duration-200 group">
                                <div class="w-8 h-8 lg:w-9 lg:h-9 rounded-full bg-gradient-to-br from-red-600 to-red-800 flex items-center justify-center ring-2 ring-transparent group-hover:ring-red-500/30 transition-all duration-200 shadow-lg shadow-red-600/10">
                                    <span class="text-sm font-bold text-white">{{ Auth::user()->name ? strtoupper(substr(Auth::user()->name, 0, 1)) : 'U' }}</span>
                                </div>
                                <svg class="w-4 h-4 text-gray-500 group-hover:text-white transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div x-show="open" x-transition class="absolute right-0 mt-3 w-56 origin-top-right z-50" style="display: none;">
                                <div class="bg-gray-900/95 backdrop-blur-2xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 overflow-hidden">
                                    <div class="px-4 py-3 border-b border-white/5">
                                        <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
                                        <p class="text-xs text-gray-500 truncate">{{ Auth::user()->email }}</p>
                                    </div>
                                    <div class="py-1">
                                        <button wire:click="goToDashboard" @click="open = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-400 hover:text-white hover:bg-white/5 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                            Dashboard
                                        </button>
                                        <a href="{{ route('client.subscriptions') }}" wire:navigate @click="open = false" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-400 hover:text-white hover:bg-white/5 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                                            Subscriptions
                                        </a>
                                        <button wire:click="logout" @click="open = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-400 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            Sign Out
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Mobile Menu Toggle (replaces user menu on mobile) --}}
                        <button @click="mobileMenu = !mobileMenu" class="lg:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors">
                            <svg x-show="!mobileMenu" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <svg x-show="mobileMenu" style="display:none;" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    @else
                        <div class="hidden sm:flex items-center gap-3">
                            <a href="{{ route('login') }}" class="text-sm text-gray-400 hover:text-white px-4 py-2 rounded-lg transition-colors">Sign In</a>
                            <a href="{{ route('register') }}" class="text-sm bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-bold transition-all shadow-lg shadow-red-600/20">Sign Up</a>
                        </div>

                        {{-- Mobile Menu Toggle for guests --}}
                        <button @click="mobileMenu = !mobileMenu" class="lg:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors">
                            <svg x-show="!mobileMenu" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <svg x-show="mobileMenu" style="display:none;" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    @endauth
                </div>
            </div>

            {{-- Mobile Navigation Menu (Dropdown) --}}
            <div x-show="mobileMenu" x-collapse class="lg:hidden pb-4" style="display: none;">
                <nav class="flex flex-col gap-1 bg-gray-900/80 backdrop-blur-xl rounded-2xl border border-white/5 p-2 mx-4 mt-2">
                    <a href="/" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Home</a>
                    <a href="{{ route('client.search') }}" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Browse Catalog</a>
                    <a href="{{ route('live') }}" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Live TV</a>
                    @auth
                        <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Plans</a>
                        <button wire:click="goToDashboard" class="text-left text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Dashboard</button>
                        <div class="border-t border-white/5 my-1"></div>
                        <button wire:click="logout" class="text-left text-sm text-red-400 hover:text-red-300 px-3 py-2.5 rounded-xl hover:bg-red-500/10 transition-colors">Sign Out</button>
                    @else
                        <div class="border-t border-white/5 my-1"></div>
                        <a href="{{ route('login') }}" class="text-sm text-gray-400 hover:text-white px-3 py-2.5 rounded-xl hover:bg-white/5 transition-colors">Sign In</a>
                        <a href="{{ route('register') }}" class="text-sm text-red-400 hover:text-red-300 px-3 py-2.5 rounded-xl hover:bg-red-500/10 transition-colors font-bold">Sign Up</a>
                    @endauth
                </nav>
            </div>
        </div>
    </div>

    {{-- ========================================== --}}
    {{-- MOBILE BOTTOM NAVIGATION - LIQUID GLASS --}}
    {{-- Only visible on mobile (hidden on lg and up) --}}
    {{-- ========================================== --}}
    <div class="lg:hidden fixed bottom-0 left-0 right-0 z-50 pb-4 px-4"
         x-data="{
            activeTab: '{{ $currentRoute ? (str_contains($currentRoute, 'home') ? 'home' : (str_contains($currentRoute, 'search') ? 'search' : (str_contains($currentRoute, 'subscriptions') ? 'plans' : (str_contains($currentRoute, 'live') ? 'live' : 'home')))) : 'home' }}'
         }">

        {{-- Glass morphism container --}}
        <div class="relative mx-auto max-w-lg">

            {{-- Glow effect behind the nav --}}
            <div class="absolute inset-0 bg-gradient-to-t from-red-600/20 via-red-600/5 to-transparent blur-2xl -top-10 rounded-full pointer-events-none"></div>

            {{-- Main nav container --}}
            <nav class="relative flex items-center justify-around h-16 px-2
                        bg-gray-900/70 backdrop-blur-3xl
                        border border-white/10
                        rounded-2xl
                        shadow-2xl shadow-black/50
                        before:absolute before:inset-0 before:rounded-2xl before:bg-gradient-to-b before:from-white/10 before:to-transparent before:pointer-events-none
                        after:absolute after:inset-0 after:rounded-2xl after:bg-gradient-to-t after:from-black/20 after:to-transparent after:pointer-events-none">

                {{-- Liquid highlight effect on top --}}
                <div class="absolute top-0 left-4 right-4 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent pointer-events-none"></div>

                {{-- Home --}}
                <a href="/" wire:navigate
                   @click="activeTab = 'home'"
                   class="relative flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 transition-all duration-300 group w-16">

                    <div x-show="activeTab === 'home'" x-transition class="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-gradient-to-r from-red-500 to-red-600 rounded-full shadow-lg shadow-red-500/50"></div>

                    <svg class="w-6 h-6 transition-all duration-300" :class="activeTab === 'home' ? 'text-red-500 drop-shadow-lg drop-shadow-red-500/50' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span class="text-[10px] font-medium transition-all duration-300" :class="activeTab === 'home' ? 'text-red-500' : 'text-gray-500 group-hover:text-gray-300'">
                        Home
                    </span>
                </a>

                {{-- Search / Browse --}}
                <a href="{{ route('client.search') }}" wire:navigate
                   @click="activeTab = 'search'"
                   class="relative flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 transition-all duration-300 group w-16">

                    <div x-show="activeTab === 'search'" x-transition class="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-gradient-to-r from-red-500 to-red-600 rounded-full shadow-lg shadow-red-500/50"></div>

                    <svg class="w-6 h-6 transition-all duration-300" :class="activeTab === 'search' ? 'text-red-500 drop-shadow-lg drop-shadow-red-500/50' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span class="text-[10px] font-medium transition-all duration-300" :class="activeTab === 'search' ? 'text-red-500' : 'text-gray-500 group-hover:text-gray-300'">
                        Browse
                    </span>
                </a>

                {{-- Center: Chat (Toasts Notification) --}}
                <div class="relative -mt-8 mx-2">
                    <div class="absolute inset-0 bg-red-600/20 blur-xl rounded-full pointer-events-none"></div>
                    <div class="relative w-12 h-12 bg-gradient-to-br from-red-600 to-red-800 rounded-full flex items-center justify-center
                                shadow-2xl shadow-red-600/30 ring-4 ring-gray-900/80
                                before:absolute before:inset-0 before:rounded-full before:bg-gradient-to-b before:from-white/20 before:to-transparent before:pointer-events-none
                                transition-transform duration-300 hover:scale-110 active:scale-95">
                        <a href="#" @click.prevent="$dispatch('notify-toast', { type: 'info', message: 'Chat and community coming soon' })" class="flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </a>
                    </div>
                </div>

                {{-- Live (Now accessible to everyone) --}}
                <a href="{{ route('live') }}" wire:navigate
                   @click="activeTab = 'live'"
                   class="relative flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 transition-all duration-300 group w-16">

                    <div x-show="activeTab === 'live'" x-transition class="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-gradient-to-r from-red-500 to-red-600 rounded-full shadow-lg shadow-red-500/50"></div>

                    <svg class="w-6 h-6 transition-all duration-300" :class="activeTab === 'live' ? 'text-red-500 drop-shadow-lg drop-shadow-red-500/50' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <span class="text-[10px] font-medium transition-all duration-300" :class="activeTab === 'live' ? 'text-red-500' : 'text-gray-500 group-hover:text-gray-300'">
                        Live
                    </span>
                </a>

                @auth
                    {{-- Plans --}}
                    <a href="{{ route('client.subscriptions') }}" wire:navigate
                       @click="activeTab = 'plans'"
                       class="relative flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 transition-all duration-300 group w-16">

                        <div x-show="activeTab === 'plans'" x-transition class="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-gradient-to-r from-red-500 to-red-600 rounded-full shadow-lg shadow-red-500/50"></div>

                        <svg class="w-6 h-6 transition-all duration-300" :class="activeTab === 'plans' ? 'text-red-500 drop-shadow-lg drop-shadow-red-500/50' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                        </svg>
                        <span class="text-[10px] font-medium transition-all duration-300" :class="activeTab === 'plans' ? 'text-red-500' : 'text-gray-500 group-hover:text-gray-300'">
                            Plans
                        </span>
                    </a>
                @else
                    {{-- Sign In --}}
                    <a href="{{ route('login') }}" wire:navigate
                       @click="activeTab = 'login'"
                       class="relative flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 transition-all duration-300 group w-16">

                        <div x-show="activeTab === 'login'" x-transition class="absolute -top-1 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-gradient-to-r from-red-500 to-red-600 rounded-full shadow-lg shadow-red-500/50"></div>

                        <svg class="w-6 h-6 transition-all duration-300" :class="activeTab === 'login' ? 'text-red-500 drop-shadow-lg drop-shadow-red-500/50' : 'text-gray-500 group-hover:text-gray-300'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span class="text-[10px] font-medium transition-all duration-300" :class="activeTab === 'login' ? 'text-red-500' : 'text-gray-500 group-hover:text-gray-300'">
                            Sign In
                        </span>
                    </a>
                @endauth
            </nav>
        </div>
    </div>

    {{-- Spacer for fixed top header --}}
    <div class="h-16 lg:h-20"></div>
</div>
