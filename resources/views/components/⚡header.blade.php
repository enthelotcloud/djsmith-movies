<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $search = '';
    public $showUserMenu = false;
    public $showMobileMenu = false;

    public function mount()
    {
        //
    }

    public function updatedSearch()
    {
        //
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

{{-- ⚠️ SINGLE ROOT ELEMENT - everything must be inside this div --}}
<div>
    {{-- Navbar --}}
    <div
        class="fixed top-0 left-0 right-0 z-50 transition-all duration-500"
        x-data="{ scrolled: false, openSearch: false, mobileMenu: false }"
        x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 50 })"
        :class="{ 'bg-black/95 backdrop-blur-md shadow-2xl shadow-black/50': scrolled, 'bg-gradient-to-b from-black via-black/95 to-transparent': !scrolled }"
    >
        <div class="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20">

                {{-- Left: Logo --}}
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

                    {{-- Desktop Navigation --}}
                    <nav class="hidden lg:flex items-center gap-1">
                        <a href="/" class="text-sm text-gray-300 hover:text-white px-3 py-2 rounded-lg transition-colors duration-200">
                            Home
                        </a>
                        @auth
                            <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2 rounded-lg transition-colors duration-200">
                                Plans
                            </a>
                        @endauth
                    </nav>
                </div>

                {{-- Center: Search Bar --}}
                <div class="hidden md:flex flex-1 max-w-md mx-4 lg:mx-8">
                    <div class="relative w-full group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-400 group-focus-within:text-white transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search movies, shows..."
                            class="w-full pl-10 pr-4 py-2 bg-white/10 border border-white/20 rounded-full text-sm text-white placeholder-gray-400
                                   focus:outline-none focus:border-red-500 focus:bg-black/60 focus:ring-2 focus:ring-red-500/20
                                   transition-all duration-300"
                        >
                        @if($search)
                            <button
                                wire:click="$set('search', '')"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-white"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Right: User Menu & Mobile Toggle --}}
                <div class="flex items-center gap-3">
                    {{-- Mobile Search Toggle --}}
                    <button
                        @click="openSearch = !openSearch"
                        class="md:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors duration-200"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </button>

                    @auth
                        {{-- User Avatar & Menu --}}
                        <div class="relative" x-data="{ open: false }">
                            <button
                                @click="open = !open"
                                class="flex items-center gap-2 p-1 rounded-full hover:bg-white/10 transition-all duration-200 group"
                            >
                                <div class="w-8 h-8 lg:w-9 lg:h-9 rounded-full bg-gradient-to-br from-red-600 to-red-800 flex items-center justify-center ring-2 ring-transparent group-hover:ring-red-500/50 transition-all duration-200">
                                    <span class="text-sm font-bold text-white">
                                        {{ Auth::user()->name ? strtoupper(substr(Auth::user()->name, 0, 1)) : 'U' }}
                                    </span>
                                </div>
                                <svg class="hidden lg:block w-4 h-4 text-gray-400 group-hover:text-white transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            {{-- Dropdown Menu --}}
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                x-transition:leave-end="opacity-0 scale-95 translate-y-1"
                                @click.away="open = false"
                                class="absolute right-0 mt-3 w-56 origin-top-right"
                                style="display: none;"
                            >
                                <div class="bg-gray-900/95 backdrop-blur-md border border-gray-700/50 rounded-xl shadow-2xl shadow-black/50 overflow-hidden">
                                    <div class="px-4 py-3 border-b border-gray-700/50">
                                        <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
                                        <p class="text-xs text-gray-400 truncate">{{ Auth::user()->email }}</p>
                                    </div>
                                    <div class="py-1">
                                        <button
                                            wire:click="goToDashboard"
                                            @click="open = false"
                                            class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-white hover:bg-white/10 transition-colors duration-200"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                            </svg>
                                            Dashboard
                                        </button>
                                        <a
                                            href="{{ route('client.subscriptions') }}"
                                            wire:navigate
                                            @click="open = false"
                                            class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-white hover:bg-white/10 transition-colors duration-200"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                                            </svg>
                                            Subscriptions
                                        </a>
                                        <button
                                            wire:click="logout"
                                            @click="open = false"
                                            class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-300 hover:text-red-400 hover:bg-red-500/10 transition-colors duration-200"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                            </svg>
                                            Sign Out
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="hidden sm:flex items-center gap-3">
                            <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                Sign In
                            </a>
                            <a href="{{ route('register') }}" class="text-sm bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-bold transition-colors duration-200">
                                Sign Up
                            </a>
                        </div>
                    @endauth

                    {{-- Mobile Menu Toggle --}}
                    <button
                        @click="mobileMenu = !mobileMenu"
                        class="lg:hidden p-2 text-gray-400 hover:text-white rounded-lg transition-colors duration-200"
                    >
                        <svg x-show="!mobileMenu" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileMenu" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobile Search --}}
            <div x-show="openSearch" x-transition class="md:hidden pb-3" style="display: none;">
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search movies, shows..."
                        class="w-full pl-10 pr-4 py-2.5 bg-white/10 border border-white/20 rounded-xl text-sm text-white placeholder-gray-400
                               focus:outline-none focus:border-red-500 focus:bg-black/60"
                    >
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
            </div>

            {{-- Mobile Navigation --}}
            <div x-show="mobileMenu" x-transition class="lg:hidden pb-4" style="display: none;">
                <nav class="flex flex-col gap-1">
                    <a href="/" class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">
                        Home
                    </a>
                    @auth
                        <a href="{{ route('client.subscriptions') }}" wire:navigate class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">
                            Plans
                        </a>
                        <button wire:click="goToDashboard" class="text-left text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">
                            Dashboard
                        </button>
                        <button wire:click="logout" class="text-left text-sm text-red-400 hover:text-red-300 px-3 py-2.5 rounded-lg hover:bg-red-500/10 transition-colors">
                            Sign Out
                        </button>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white px-3 py-2.5 rounded-lg hover:bg-white/5 transition-colors">
                            Sign In
                        </a>
                        <a href="{{ route('register') }}" class="text-sm text-red-500 hover:text-red-400 px-3 py-2.5 rounded-lg hover:bg-red-500/10 transition-colors font-bold">
                            Sign Up
                        </a>
                    @endauth
                </nav>
            </div>
        </div>
    </div>

    {{-- Spacer for fixed header - now INSIDE the single root element --}}
    <div class="h-16 lg:h-20"></div>
</div>
