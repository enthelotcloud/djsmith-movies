<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('layouts.guest.app')]
#[Title('Terms of Service')]
class extends Component
{
    public $search = '';

    public function getSectionsProperty()
    {
        $sections = [
            [
                'title' => '1. Acceptance of Terms',
                'content' => 'By accessing, registering, or subscribing to Dj Smith Movies (djsmith.co.ke), you agree to be bound by these Terms of Service. If you do not agree to these terms, you may not access or use our premium video-on-demand platform.'
            ],
            [
                'title' => '2. Subscriptions & Billing',
                'content' => 'Dj Smith Movies operates on a strict 100% paywall model. There is no free tier. Access to movies and series requires an active, unexpired subscription. All payments are non-refundable unless otherwise required by applicable law. We reserve the right to change subscription pricing with prior notice.'
            ],
            [
                'title' => '3. Account Usage & Single Device Limit',
                'content' => 'Your subscription is for personal, non-commercial use only. Account sharing is strictly prohibited. Our system actively monitors sessions and enforces a strict single-device playback rule. Attempting to stream concurrently on multiple devices will result in immediate session termination and potential account suspension.'
            ],
            [
                'title' => '4. Anti-Piracy & Content Protection',
                'content' => 'All content on Dj Smith Movies is protected by copyright and Digital Rights Management (DRM). You are strictly prohibited from downloading, ripping, screen-recording, taking screenshots, or attempting to bypass our security measures. Violations detected by our system will lead to an immediate, permanent ban without a refund.'
            ],
            [
                'title' => '5. User Conduct',
                'content' => 'You agree not to use our service for any illegal purposes or to conduct any activity that could damage, disable, or impair our servers. This includes attempting to scrape data, reverse-engineer our video players, or distribute modified links to bypass our authentication systems.'
            ],
            [
                'title' => '6. Service Availability',
                'content' => 'While we strive to provide uninterrupted high-quality streaming, we do not guarantee that the service will be entirely free from buffering, downtime, or technical errors. Service quality may vary depending on your location, bandwidth, and device capabilities.'
            ],
            [
                'title' => '7. Account Termination',
                'content' => 'We reserve the right to suspend or terminate your account at any time, without notice or liability, if we determine that you have violated these Terms of Service, particularly regarding piracy, account sharing, or payment fraud.'
            ],
            [
                'title' => '8. Content Availability',
                'content' => 'The movies and series available on our platform are subject to change. We reserve the right to add, remove, or modify the catalog at our sole discretion without prior notice to subscribers.'
            ],
            [
                'title' => '9. Limitation of Liability',
                'content' => 'To the maximum extent permitted by law, Dj Smith Movies shall not be liable for any indirect, incidental, or consequential damages arising from your use of or inability to use our streaming service.'
            ],
            [
                'title' => '10. Governing Law',
                'content' => 'These Terms of Service are governed by and construed in accordance with the laws of Kenya. Any disputes arising out of or related to these terms shall be subject to the exclusive jurisdiction of the courts located in Kenya.'
            ],
        ];

        if (empty(trim($this->search))) {
            return $sections;
        }

        $searchTerm = strtolower(trim($this->search));

        // Filter sections based on search query
        return array_filter($sections, function($section) use ($searchTerm) {
            return str_contains(strtolower($section['title']), $searchTerm) ||
                   str_contains(strtolower($section['content']), $searchTerm);
        });
    }

    // Helper method to highlight search matches safely
    public function highlight($text)
    {
        if (empty(trim($this->search))) {
            return $text;
        }

        $term = preg_quote(trim($this->search), '/');
        // Wrap matches in a red highlight span
        return preg_replace("/($term)/i", "<mark class='bg-red-600/40 text-red-100 px-1 rounded shadow-sm'>$1</mark>", $text);
    }
};
?>

<div class="min-h-screen bg-black pt-20 pb-24 relative z-0">
    
    {{-- Background Glow --}}
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-red-900/20 blur-[120px] rounded-full pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        {{-- Header Section --}}
        <div class=" mb-12">
            <h1 class="text-4xl md:text-5xl font-black text-white tracking-tight mb-4">
                Terms of <span class="text-red-600">Service</span>
            </h1>
            <p class="text-slate-400 text-sm md:text-base">
                These terms govern your use of the Dj Smith Movies platform. Please read them carefully to understand your rights and obligations as a subscriber.
            </p>
            <p class="text-xs text-slate-500 mt-4 font-bold uppercase tracking-widest">
                Last Updated: {{ date('F Y') }}
            </p>
        </div>

        {{-- Sticky Search Bar --}}
        <div class="sticky top-20 sm:top-24 z-30 mb-10">
            <div class="relative max-w-2xl mx-auto group">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-slate-500 group-focus-within:text-red-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <input 
                    type="text" 
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search the terms (e.g., billing, sharing, piracy)..." 
                    class="w-full bg-[#111]/90 backdrop-blur-xl border border-slate-800 rounded-2xl pl-12 pr-12 py-4 text-sm text-white placeholder-slate-500 focus:ring-2 focus:ring-red-600/50 focus:border-red-600 transition-all outline-none shadow-2xl shadow-black/50"
                >
                @if($search)
                    <button wire:click="$set('search', '')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-white transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                @endif
            </div>
        </div>

        {{-- Terms Content --}}
        <div class="bg-[#111] border border-slate-800 rounded-3xl overflow-hidden shadow-2xl">
            
            <div wire:loading class="w-full p-12 flex justify-center">
                <div class="w-8 h-8 border-4 border-slate-700 border-t-red-600 rounded-full animate-spin"></div>
            </div>

            <div wire:loading.remove>
                @if(count($this->sections) > 0)
                    <div class="divide-y divide-slate-800/50">
                        @foreach($this->sections as $index => $section)
                            <div class="p-5 sm:p-8 hover:bg-white/[0.02] transition duration-300">
                                <h2 class="text-lg md:text-xl font-bold text-white mb-3">
                                    {!! $this->highlight($section['title']) !!}
                                </h2>
                                <p class="text-slate-400 text-sm md:text-base leading-relaxed">
                                    {!! $this->highlight($section['content']) !!}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-16 text-center">
                        <svg class="w-16 h-16 text-slate-700 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="text-xl font-bold text-white mb-2">No matching clauses found</h3>
                        <p class="text-slate-500 text-sm">We couldn't find anything matching "<span class="text-red-500 font-bold">{{ $search }}</span>".</p>
                        <button wire:click="$set('search', '')" class="mt-6 px-6 py-2.5 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl transition shadow-lg shadow-red-600/20 text-sm">
                            Clear Search
                        </button>
                    </div>
                @endif
            </div>

        </div>

        {{-- Contact Footer --}}
        <div class="mt-12 text-center border-t border-slate-800/50 pt-8">
            <p class="text-slate-500 text-sm">
                If you have any questions regarding these Terms, please contact support at <br class="sm:hidden">
                <a href="mailto:support@djsmith.co.ke" class="text-red-500 hover:text-red-400 font-bold transition">support@djsmith.co.ke</a>
            </p>
        </div>

    </div>
</div>