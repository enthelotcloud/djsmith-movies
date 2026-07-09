<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {
    
    // Modal States
    public $confirmingPlan = null;
    public $showResultModal = false;
    public $modalType = ''; // 'success' or 'error'
    public $modalMessage = '';
    public $isProcessing = false;

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('price')->get();
    }

    // 1. Reactive Wallet Balance
    #[Computed]
    public function currentBalance()
    {
        return Auth::user()->fresh()->wallet_balance;
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlan = Plan::find($planId);
    }

    public function executePurchase()
    {
        $this->isProcessing = true;
        $user = Auth::user();
        $plan = $this->confirmingPlan;

        // Check funds
        if ($user->fresh()->wallet_balance < $plan->price) {
            $this->modalType = 'error';
            $this->modalMessage = 'Insufficient funds. You need KES ' . number_format($plan->price - $user->wallet_balance, 2) . ' more. Please top up your wallet.';
            $this->showResultModal = true;
            $this->confirmingPlan = null;
            $this->isProcessing = false;
            return;
        }

        // Deduct money
        $user->decrement('wallet_balance', $plan->price);

        // Deactivate old subscriptions
        Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        // Create new active subscription
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMinutes($plan->duration_minutes),
            'auto_renew' => true, 
        ]);

        // Show Success Modal
        $this->modalType = 'success';
        $this->modalMessage = 'Success! You are now subscribed to ' . $plan->name . '. Grab the popcorn!';
        $this->showResultModal = true;
        
        $this->confirmingPlan = null;
        $this->isProcessing = false;
    }

    public function closeModals()
    {
        $this->confirmingPlan = null;
        $this->showResultModal = false;
    }
};
?>

<div class="max-w-5xl mx-auto space-y-8 relative">

    {{-- HEADER (Reactive Balance) --}}
    <div class="text-center mb-10">
        <h1 class="text-3xl font-black text-white">Choose Your Plan</h1>
        <p class="text-slate-400 mt-2 flex items-center justify-center gap-2">
            Available Wallet Balance: 
            <span class="text-red-500 font-mono font-bold bg-red-950/40 px-3 py-1 rounded-lg border border-red-500/30 transition-all duration-500">
                KES {{ number_format($this->currentBalance, 2) }}
            </span>
        </p>
    </div>

    {{-- PLAN CARDS (Netflix Red Theme) --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @forelse($this->plans as $plan)
            <div class="bg-black border border-slate-800 rounded-2xl p-8 flex flex-col relative overflow-hidden transition hover:border-red-600/60 group shadow-lg">
                
                @if($plan->can_download)
                    <div class="absolute top-0 right-0 bg-red-600 text-xs font-bold px-3 py-1 rounded-bl-lg text-white shadow-md">
                        Downloads Included
                    </div>
                @endif

                <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                <div class="text-3xl font-black text-red-600 mb-6 drop-shadow-md">
                    KES {{ number_format($plan->price, 0) }}
                </div>

                <ul class="space-y-3 mb-8 flex-1 text-sm text-slate-300">
                    <li class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Full HD Streaming
                    </li>
                    <li class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}
                    </li>
                    <li class="flex items-center gap-2 {{ $plan->can_download ? 'text-slate-300' : 'text-slate-700 line-through' }}">
                        <svg class="w-5 h-5 {{ $plan->can_download ? 'text-red-600' : 'text-slate-800' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Offline Downloads
                    </li>
                </ul>

                <button 
                    wire:click="confirmPurchase({{ $plan->id }})" 
                    class="w-full py-3 rounded-lg bg-zinc-900 text-red-500 border border-red-600/30 group-hover:bg-red-600 group-hover:text-white group-hover:border-red-600 font-bold transition-all shadow-sm"
                >
                    Select Plan
                </button>
            </div>
        @empty
            <div class="col-span-3 text-center text-slate-500 py-12 bg-black border border-slate-800 rounded-2xl">
                No plans available right now. Check back later!
            </div>
        @endforelse
    </div>

    {{-- ══════════════════ 1. CONFIRMATION MODAL ══════════════════ --}}
    @if($confirmingPlan)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-zinc-950 border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-6 text-center">
                    <div class="w-16 h-16 bg-red-950/50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-900/50">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    
                    <h3 class="text-xl font-bold text-white mb-2">Confirm Purchase</h3>
                    <p class="text-sm text-slate-400 mb-6">
                        You are about to purchase <strong class="text-white">{{ $confirmingPlan->name }}</strong>. 
                        This will deduct <strong class="text-red-500">KES {{ number_format($confirmingPlan->price, 2) }}</strong> from your wallet.
                    </p>

                    <div class="flex flex-col gap-3">
                        <button 
                            wire:click="executePurchase" 
                            wire:loading.attr="disabled"
                            class="w-full py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm flex items-center justify-center gap-2 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="executePurchase">Confirm & Pay</span>
                            <span wire:loading wire:target="executePurchase" class="flex items-center gap-2">
                                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Processing...
                            </span>
                        </button>
                        <button wire:click="closeModals" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition disabled:opacity-50">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ 2. SUCCESS / ERROR RESULT MODAL ══════════════════ --}}
    @if($showResultModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-zinc-950 border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-6 text-center">
                    
                    @if($modalType === 'success')
                        <div class="w-16 h-16 bg-emerald-950/50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-emerald-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Payment Successful!</h3>
                    @else
                        <div class="w-16 h-16 bg-red-950/50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Transaction Failed</h3>
                    @endif

                    <p class="text-sm text-slate-400 mb-6 leading-relaxed">
                        {{ $modalMessage }}
                    </p>

                    <div class="flex flex-col gap-3">
                        @if($modalType === 'error')
                            <a href="{{ route('client.wallet-topup') }}" wire:navigate class="w-full py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm block text-center">
                                Top Up Wallet Now
                            </a>
                        @endif
                        <button wire:click="closeModals" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>