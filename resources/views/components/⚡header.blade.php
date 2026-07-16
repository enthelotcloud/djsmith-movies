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

    public function mount()
    {
        //
    }

    // Triggered automatically as the user types
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
                        'poster' => $this->getPosterUrl($movie->thumbnail_path)
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
            return Storage::disk('b2')->temporaryUrl($path, now()->addHours(2));
        } catch (\Exception $e) {
            return null;
        }
    }

    // Handles submitting to the dedicated search page
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
};
?>

{{-- ⚠️ SINGLE ROOT ELEMENT --}}
<div>
    {{-- Navbar --}}
    <div
        class="fixed top-0 left-0 right-0 z-50 transition-all duration-500"
        x-data="{
            scrolled: false,
            openSearch: false,
            mobileMenu: false,
            searchFocused: false,
            history: JSON.parse(localStorage.getItem('dj_search_history') || '[]'),

            saveSearch(term) {
                if (!term || term.trim() === '') return;
                let arr = this.history.filter(i => i.toLowerCase() !== term.toLowerCase());
                arr.unshift(term);
                if (arr.length > 5) arr.pop(); // Keep last 5 searches
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
        :class="{ 'bg-black/95 backdrop-blur-md border-b border-slate-900 shadow-2xl': scrolled, 'bg-gradient-to-b from-black to-black/0': !scrolled }"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20">

                {{-- Left: Logo & Nav --}}
                <div class="flex items-center gap-8">
                    <a href="/" class="flex items-center gap-3 group" wire:navigate>
                        <div class="relative">
                            <img
                                src="{{ asset('images/logo.png') }}"
                                alt="Dj Smith Movies"
                                class="h-8 lg:h-10 w-auto transition-transform duration-300 group-hover:scale-105"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                            <div class="hidden h-8 lg:h-10 w-8 lg:w-10 bg-red-600 rounded-lg items-center justify-center">
                                <svg class="w-5 h-5 lg:w-6 lg:h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M18 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12zm-1 2H7a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1zm-2 7l-4-3v6l4-3z"/>
                                </svg>
                            </div>
                        </div>
                        <span class="hidden sm:block text-xl lg:text-2xl font-black text-white tracking-tight">
                            Dj<span class="text-red-600">Smith</span>
                        </span>
                    </a>

                    <nav class="hidden lg:flex items-center gap-1">
                        <a href="/" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2 rounded-lg transition-colors duration-200">Home</a>
                        <a href="{{ route('client.search') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2 rounded-lg transition-colors duration-200">Browse</a>
                        @auth
                            <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2 rounded-lg transition-colors duration-200">Plans</a>
                        @endauth
                    </nav>
                </div>

                {{-- Center: Desktop AJAX Search --}}
                <div class="hidden md:flex flex-1 max-w-md mx-4 lg:mx-8 relative">
                    <form wire:submit.prevent="submitSearch" @submit="saveSearch($wire.search)" class="w-full relative group z-50">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-400 group-focus-within:text-white transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            @focus="searchFocused = true"
                            @click.outside="searchFocused = false"
                            placeholder="Search movies, shows..."
                            class="w-full pl-10 pr-10 py-2 bg-white/10 border border-white/20 rounded-full text-sm text-white placeholder-gray-400 focus:outline-none focus:border-red-500 focus:bg-black/80 focus:ring-2 focus:ring-red-500/20 transition-all duration-300"
                        >
                        @if($search)
                            <button type="button" wire:click="$set('search', '')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-white">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        @endif
                    </form>

                    {{-- Search Dropdown (History & Results) --}}
                    <div x-show="searchFocused"
                         x-transition
                         class="absolute top-full left-0 w-full mt-2 bg-gray-900/95 backdrop-blur-xl border border-gray-700/50 rounded-2xl shadow-2xl overflow-hidden z-50"
                         style="display: none;">

                        {{-- History --}}
                        <div x-show="$wire.search.length < 2 && history.length > 0" class="p-3">
                            <div class="flex items-center justify-between mb-2 px-2">
                                <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Recent</span>
                                <button type="button" @click="clearHistory()" class="text-[10px] text-red-500 hover:text-red-400">Clear</button>
                            </div>
                            <ul class="space-y-1">
                                <template x-for="term in history" :key="term">
                                    <li>
                                        <button type="button" @click="executeSearch(term)" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-gray-300 hover:text-white hover:bg-white/10 rounded-xl transition">
                                            <svg class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span x-text="term"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        {{-- Live Results --}}
                        <div x-show="$wire.search.length >= 2">
                            @if(count($searchResults) > 0)
                                <div class="p-2 space-y-1">
                                    @foreach($searchResults as $result)
                                        <a href="{{ route('client.player', ['slug' => $result['slug']]) }}" wire:navigate class="flex items-center gap-4 p-2 hover:bg-white/10 rounded-xl transition group">
                                            @if($result['poster'])
                                                <img src="{{ $result['poster'] }}" class="w-10 h-14 object-cover rounded shadow group-hover:scale-105 transition">
                                            @else
                                                <div class="w-10 h-14 bg-gray-800 rounded flex items-center justify-center text-[8px] text-gray-500">No Img</div>
                                            @endif
                                            <div class="flex-1 overflow-hidden">
                                                <h4 class="text-sm font-bold text-white truncate group-hover:text-red-400 transition">{{ $result['title'] }}</h4>
                                                <p class="text-[11px] text-gray-400 truncate mt-0.5">{{ $result['excerpt'] }}</p>
                                            </div>
                                        </a>
                                    @endforeach
                                    <button type="button" @click="executeSearch($wire.search)" class="w-full mt-2 py-2.5 text-xs font-bold text-white bg-white/5 hover:bg-red-600 rounded-xl transition">
                                        View all results
                                    </button>
                                </div>
                            @else
                                <div class="p-6 text-center text-gray-500 text-sm">
                                    No results found for "<span class="text-white" x-text="$wire.search"></span>"
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Right: Notifications, User Menu & Mobile Toggle --}}
                <div class="flex items-center gap-3 lg:gap-4">

                    {{-- Mobile Search Toggle --}}
                    <button @click="openSearch = !openSearch" class="md:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors duration-200">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </button>

                    @auth
                        {{-- 🔔 Notification Bell --}}
                        <button class="relative p-2 text-gray-400 hover:text-white transition-colors duration-200 rounded-full hover:bg-white/10">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <span class="absolute top-1 right-1.5 w-2 h-2 bg-red-600 rounded-full ring-2 ring-black"></span>
                        </button>

                        {{-- User Avatar & Menu --}}
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false" class="flex items-center gap-2 p-1 rounded-full hover:bg-white/10 transition-all duration-200 group">
                                <div class="w-8 h-8 lg:w-9 lg:h-9 rounded-full bg-gradient-to-br from-red-600 to-red-800 flex items-center justify-center ring-2 ring-transparent group-hover:ring-red-500/50 transition-all duration-200">
                                    <span class="text-sm font-bold text-white">{{ Auth::user()->name ? strtoupper(substr(Auth::user()->name, 0, 1)) : 'U' }}</span>
                                </div>
                                <svg class="hidden lg:block w-4 h-4 text-gray-400 group-hover:text-white transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            {{-- User Dropdown --}}
                            <div x-show="open" x-transition class="absolute right-0 mt-3 w-56 origin-top-right z-50" style="display: none;">
                                <div class="bg-gray-900/95 backdrop-blur-md border border-gray-700/50 rounded-xl shadow-2xl shadow-black/50 overflow-hidden">
                                    <div class="px-4 py-3 border-b border-gray-700/50">
                                        <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
                                        <p class="text-xs text-gray-400 truncate">{{ Auth::user()->email }}</p>
                                    </div>
                                    <div class="py-1">
                                        <button wire:click="goToDashboard" @click="open = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-white hover:bg-white/10 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                            Dashboard
                                        </button>
                                        <a href="{{ route('client.subscriptions') }}" wire:navigate @click="open = false" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-white hover:bg-white/10 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                                            Subscriptions
                                        </a>
                                        <button wire:click="logout" @click="open = false" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-red-400 hover:bg-red-500/10 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            Sign Out
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="hidden sm:flex items-center gap-3">
                            <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white px-4 py-2 rounded-lg transition-colors">Sign In</a>
                            <a href="{{ route('register') }}" class="text-sm bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-bold transition-colors">Sign Up</a>
                        </div>
                    @endauth

                    {{-- Mobile Menu Toggle --}}
                    <button @click="mobileMenu = !mobileMenu" class="lg:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors">
                        <svg x-show="!mobileMenu" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileMenu" style="display:none;" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            {{-- MOBILE SEARCH DROPDOWN (Slides down directly under the nav) --}}
            <div x-show="openSearch" x-collapse class="md:hidden pb-4 relative z-40" style="display: none;">
                <form wire:submit.prevent="submitSearch" @submit="saveSearch($wire.search)" class="relative">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search movies, shows..." class="w-full pl-10 pr-4 py-2.5 bg-white/10 border border-white/20 rounded-xl text-sm text-white placeholder-gray-400 focus:outline-none focus:border-red-500 focus:bg-black/90">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </form>

                {{-- Mobile Live Results --}}
                <div x-show="$wire.search.length >= 2" class="mt-2 bg-gray-900/90 border border-gray-700/50 rounded-xl overflow-hidden p-2">
                    @forelse($searchResults as $result)
                        <a href="{{ route('client.player', ['slug' => $result['slug']]) }}" wire:navigate class="flex items-center gap-3 p-2 hover:bg-white/10 rounded-lg group">
                            @if($result['poster'])
                                <img src="{{ $result['poster'] }}" class="w-8 h-12 object-cover rounded shadow">
                            @endif
                            <div>
                                <h4 class="text-sm font-bold text-white truncate group-hover:text-red-400">{{ $result['title'] }}</h4>
                            </div>
                        </a>
                    @empty
                        <div class="p-4 text-center text-gray-400 text-xs">No results found.</div>
                    @endforelse
                    @if(count($searchResults) > 0)
                        <button type="button" @click="executeSearch($wire.search)" class="w-full mt-1 py-2 text-xs font-bold text-red-500 hover:text-red-400">View all results</button>
                    @endif
                </div>
            </div>

            {{-- Mobile Navigation Links --}}
            <div x-show="mobileMenu" x-collapse class="lg:hidden pb-4" style="display: none;">
                <nav class="flex flex-col gap-1">
                    <a href="/" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">Home</a>
                    <a href="{{ route('client.search') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">Browse Catalog</a>
                    @auth
                        <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">Plans</a>
                        <button wire:click="goToDashboard" class="text-left text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">Dashboard</button>
                        <button wire:click="logout" class="text-left text-sm text-red-400 hover:text-red-300 px-3 py-2.5 rounded-lg hover:bg-red-500/10 transition-colors">Sign Out</button>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">Sign In</a>
                        <a href="{{ route('register') }}" class="text-sm text-red-500 hover:text-red-400 px-3 py-2.5 rounded-lg hover:bg-red-500/10 transition-colors font-bold">Sign Up</a>
                    @endauth
                </nav>
            </div>
        </div>
    </div>

    {{-- Spacer for fixed header --}}
    <div class="h-16 lg:h-20"></div>
</div>
