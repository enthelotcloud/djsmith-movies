<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('layouts.guest.app')]
#[Title('Frequently Asked Questions')]
class extends Component
{
    public $search = '';
    public $category = 'All';

    public function getCategoriesProperty()
    {
        return [
            'All',
            'Subscriptions & Access',
            'Streaming & Playback',
            'Content & Requests',
        ];
    }

    public function getFaqsProperty()
    {
        $allFaqs = [
            [
                'question' => 'How does streaming work on Dj Smith Movies?',
                'answer' => 'Dj Smith Movies is a premium video-on-demand platform. An active subscription is required to stream any movie or series in our catalog. Once subscribed, you get unlimited access across all your supported devices.',
                'category' => 'Subscriptions & Access'
            ],
            [
                'question' => 'How often is new content added?',
                'answer' => 'Our library is updated daily! We upload at least 10 new movies every day, along with the latest episodes for ongoing series and requested titles.',
                'category' => 'Content & Requests'
            ],
            [
                'question' => 'Can I request a movie or series that is not listed?',
                'answer' => 'Yes! If you cannot find a specific movie or series in our catalog, send your request to support@djsmith.co.ke. Our team reviews requests daily and prioritizing adding them to the platform.',
                'category' => 'Content & Requests'
            ],
            [
                'question' => 'Can I share my account across multiple devices?',
                'answer' => 'To ensure high quality streaming and system stability for all users, account access is limited to a single active device at a time. Simultaneous streaming on multiple screens will pause active playback.',
                'category' => 'Subscriptions & Access'
            ],
            [
                'question' => 'What should I do if a video is buffering or failing to play?',
                'answer' => 'First, check your internet connection. If buffering continues, try refreshing your browser or clearing your cache. Our anti-piracy network protections ensure stable playback on all modern mobile and desktop browsers.',
                'category' => 'Streaming & Playback'
            ],
            [
                'question' => 'How do I manage or renew my subscription?',
                'answer' => 'You can check your active plan, expiration date, or renew your subscription anytime from your personal Dashboard or by visiting the Plans page.',
                'category' => 'Subscriptions & Access'
            ],
            [
                'question' => 'Is Dj Smith Movies supported on mobile devices?',
                'answer' => 'Yes, our web application is fully optimized for smartphones and tablets. You can also install it directly as a Progressive Web App (PWA) for a native app-like experience.',
                'category' => 'Streaming & Playback'
            ],
            [
                'question' => 'Who do I contact for customer support or billing issues?',
                'answer' => 'For any questions, account assistance, or technical support, send an email directly to support@djsmith.co.ke and our team will get back to you promptly.',
                'category' => 'Content & Requests'
            ],
        ];

        return array_filter($allFaqs, function ($faq) {
            // Filter by Category
            if ($this->category !== 'All' && $faq['category'] !== $this->category) {
                return false;
            }

            // Filter by Search Term
            if (!empty(trim($this->search))) {
                $term = strtolower(trim($this->search));
                $qMatch = str_contains(strtolower($faq['question']), $term);
                $aMatch = str_contains(strtolower($faq['answer']), $term);
                return $qMatch || $aMatch;
            }

            return true;
        });
    }

    public function highlight($text)
    {
        if (empty(trim($this->search))) {
            return $text;
        }

        $term = preg_quote(trim($this->search), '/');
        return preg_replace("/($term)/i", "<mark class='bg-red-600/40 text-red-100 px-1 rounded shadow-sm'>$1</mark>", $text);
    }
};
?>

<div class="min-h-screen bg-black pt-20 pb-24 relative z-0">
    
    {{-- Background Glow --}}
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-red-900/20 blur-[120px] rounded-full pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        {{-- Header Section --}}
        <div class=" mb-10">
            <h1 class="text-4xl md:text-5xl font-black text-white tracking-tight mb-4">
                Frequently Asked <span class="text-red-600">Questions</span>
            </h1>
            <p class="text-slate-400 text-sm md:text-base ">
                Everything you need to know about streaming, subscriptions, daily uploads, and requesting your favorite titles.
            </p>
        </div>

        {{-- Search Input Bar --}}
        <div class="sticky top-20 sm:top-24 z-30 mb-8">
            <div class="relative max-w-7xl mx-auto group">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-slate-500 group-focus-within:text-red-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <input 
                    type="text" 
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search questions (e.g., requests, 10 movies, buffering)..." 
                    class="w-full bg-[#111]/90 backdrop-blur-xl border border-slate-800 rounded-2xl pl-12 pr-12 py-4 text-sm text-white placeholder-slate-500 focus:ring-2 focus:ring-red-600/50 focus:border-red-600 transition-all outline-none shadow-2xl shadow-black/50"
                >
                @if($search)
                    <button wire:click="$set('search', '')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-white transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                @endif
            </div>
        </div>

        {{-- Category Filters --}}
        <div class="flex justify-start sm:justify-center gap-2 overflow-x-auto pb-4 mb-8 no-scrollbar">
            @foreach($this->categories as $cat)
                <button 
                    wire:click="$set('category', '{{ $cat }}')"
                    class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold whitespace-nowrap transition-all duration-300 {{ $category === $cat ? 'bg-red-600 text-white shadow-lg shadow-red-600/20' : 'bg-[#111] text-slate-400 border border-slate-800 hover:text-white hover:border-slate-700' }}"
                >
                    {{ $cat }}
                </button>
            @endforeach
        </div>

        {{-- Accordion List --}}
        <div class="space-y-4">
            
            <div wire:loading class="w-full p-12 flex justify-center">
                <div class="w-8 h-8 border-4 border-slate-700 border-t-red-600 rounded-full animate-spin"></div>
            </div>

            <div wire:loading.remove class="space-y-4">
                @forelse($this->faqs as $index => $faq)
                    <div 
                        x-data="{ open: false }" 
                        class="bg-[#111] border border-slate-800 rounded-2xl overflow-hidden hover:border-slate-700 transition duration-300"
                    >
                        <button 
                            @click="open = !open" 
                            class="w-full p-5 text-left flex items-center justify-between gap-4 focus:outline-none"
                        >
                            <span class="text-sm md:text-base font-bold text-white leading-snug">
                                {!! $this->highlight($faq['question']) !!}
                            </span>
                            
                            <div class="shrink-0 w-8 h-8 rounded-full bg-white/5 flex items-center justify-center text-slate-400 transition-transform duration-300" :class="{ 'rotate-180 bg-red-600/20 text-red-500': open }">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                        </button>

                        <div 
                            x-show="open" 
                            x-collapse
                            style="display: none;"
                            class="px-5 pb-5 pt-0 text-slate-400 text-sm md:text-base leading-relaxed border-t border-slate-800/40 mt-1"
                        >
                            <p class="pt-3">
                                {!! $this->highlight($faq['answer']) !!}
                            </p>
                            <div class="mt-3">
                                <span class="text-[10px] uppercase font-bold text-slate-500 border border-slate-800 px-2 py-0.5 rounded">
                                    {{ $faq['category'] }}
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="bg-[#111] border border-slate-800 rounded-3xl p-12 text-center">
                        <svg class="w-12 h-12 text-slate-700 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="text-lg font-bold text-white mb-1">No matching questions found</h3>
                        <p class="text-slate-500 text-sm">Try adjusting your search query or selecting a different category.</p>
                        <button wire:click="$set('search', ''); $set('category', 'All');" class="mt-4 px-5 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl transition text-xs">
                            Reset Filters
                        </button>
                    </div>
                @endforelse
            </div>

        </div>

        {{-- Movie Request Box --}}
        <div class="mt-12 bg-gradient-to-r from-red-950/40 via-[#111] to-zinc-900 border border-red-900/30 rounded-3xl p-6 sm:p-8 text-center relative overflow-hidden shadow-2xl">
            <div class="relative z-10">
                <span class="px-3 py-1 bg-red-600/20 text-red-400 text-[10px] font-black uppercase tracking-widest rounded-full border border-red-500/30">
                    Daily Content Updates
                </span>
                <h3 class="text-xl sm:text-2xl font-black text-white mt-3 mb-2">
                    Can't find what you're looking for?
                </h3>
                <p class="text-slate-400 text-sm max-w-xl mx-auto mb-6">
                    We add <strong class="text-white">10+ new movies every single day</strong>. If a movie or series isn't available yet, send us a request and we'll upload it for you!
                </p>
                <a 
                    href="mailto:support@djsmith.co.ke?subject=Movie%20Request" 
                    class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-500 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-red-600/30"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    Send Request to support@djsmith.co.ke
                </a>
            </div>
        </div>

    </div>
</div>