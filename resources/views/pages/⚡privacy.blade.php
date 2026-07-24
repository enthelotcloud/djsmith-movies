<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('layouts.guest.app')]
#[Title('Privacy Policy')]
class extends Component
{
    public $search = '';

    public function getSectionsProperty()
    {
        $sections = [
            [
                'title' => '1. Introduction & Scope',
                'content' => 'Welcome to Dj Smith Movies (djsmith.co.ke). This Privacy Policy explains how we collect, use, and protect your personal data when you access our premium video-on-demand (VOD) streaming service. By using our platform, you agree to the data practices described in this policy.'
            ],
            [
                'title' => '2. Information We Collect',
                'content' => 'We collect information you provide directly, such as your name, email address, and account credentials. We also automatically collect streaming metadata, including your watch history, playback progress, search queries, IP address, browser type, and device identifiers.'
            ],
            [
                'title' => '3. Subscription & Payment Data',
                'content' => 'To access our premium catalog, an active subscription is required. We do not store full credit card numbers on our servers. All financial transactions are securely processed and encrypted by our trusted third-party payment gateways.'
            ],
            [
                'title' => '4. Anti-Piracy Enforcement',
                'content' => 'To protect licensed content and enforce our zero-tolerance piracy policy, we actively monitor session data. We collect active session IDs, concurrent login attempts, and device footprints to enforce our strict single-device streaming rule and to detect unauthorized scraping, screen recording, or stream ripping.'
            ],
            [
                'title' => '5. How We Use Your Data',
                'content' => 'Your data is used to provide and optimize the streaming experience, securely process subscriptions, remember your video playback progress, and personalize your content recommendations. We also use this data to secure our platform against fraudulent access and account sharing.'
            ],
            [
                'title' => '6. Cookies & Local Storage',
                'content' => 'We use cookies and local browser storage to keep you logged in, remember your site preferences, and track video playback progress so you can resume watching exactly where you left off across your authorized devices.'
            ],
            [
                'title' => '7. Data Sharing & Third Parties',
                'content' => 'We will never sell your personal data. We only share information with strictly vetted third-party service providers (such as cloud hosting, CDN providers, and payment processors) necessary to operate the Dj Smith Movies platform.'
            ],
            [
                'title' => '8. Data Retention & Security',
                'content' => 'We implement robust technical security measures, including SSL encryption and secure server infrastructure, to protect your data. We retain your account and watch history data as long as your account is active, or as required by law.'
            ],
            [
                'title' => '9. Your Rights & Choices',
                'content' => 'You have the right to access, correct, or request the deletion of your personal data. If you wish to permanently delete your Dj Smith account and wipe your watch history, you can do so from your account settings or by contacting our support team.'
            ],
            [
                'title' => '10. Changes to This Policy',
                'content' => 'We may update this Privacy Policy periodically to reflect changes in our platform or legal requirements. We will notify you of any significant changes by updating the date at the top of this policy and, where appropriate, sending an email notification.'
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
                Privacy <span class="text-red-600">Policy</span>
            </h1>
            <p class="text-slate-400 text-sm md:text-base ">
                Transparency and security are at the core of our platform. Read how we handle your data to bring you a premium, secure streaming experience.
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
                    placeholder="Search the privacy policy (e.g., cookies, tracking, payment)..."
                    class="w-full bg-[#111]/90 backdrop-blur-xl border border-slate-800 rounded-2xl pl-12 pr-12 py-4 text-sm text-white placeholder-slate-500 focus:ring-2 focus:ring-red-600/50 focus:border-red-600 transition-all outline-none shadow-2xl shadow-black/50"
                >
                @if($search)
                    <button wire:click="$set('search', '')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-500 hover:text-white transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                @endif
            </div>
        </div>

        {{-- Policy Content --}}
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
                Have questions about your data? Contact our Data Protection Officer at <br class="sm:hidden">
                <a href="mailto:privacy@djsmith.co.ke" class="text-red-500 hover:text-red-400 font-bold transition">privacy@djsmith.co.ke</a>
            </p>
        </div>

    </div>
</div>
