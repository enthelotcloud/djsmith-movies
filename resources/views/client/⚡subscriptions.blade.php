<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {
    
    public $confirmingPlan = null;
    public $showResultModal = false;
    public $modalType = ''; 
    public $modalMessage = '';
    public $isProcessing = false;

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('price')->get();
    }

    #[Computed]
    public function currentBalance()
    {
        return Auth::user()->fresh()->wallet_balance;
    }

    #[Computed]
    public function activeSubscription()
    {
        return Subscription::with('plan')
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    #[Computed]
    public function subscriptionHistory()
    {
        // FIX: Only fetch real purchases by ignoring the default inactive placeholder
        return Subscription::with('plan')
            ->where('user_id', Auth::id())
            ->whereNotNull('plan_id') 
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlan = Plan::find($planId);
    }

    public function executePurchase()
    {
        $this->isProcessing = true;
        
        $user = User::find(Auth::id()); 
        $plan = $this->confirmingPlan;

        if ($user->wallet_balance < $plan->price) {
            $this->modalType = 'error';
            $this->modalMessage = 'Insufficient funds. You need KES ' . number_format($plan->price - $user->wallet_balance, 2) . ' more. Please top up your wallet.';
            $this->showResultModal = true;
            $this->confirmingPlan = null;
            $this->isProcessing = false;
            return;
        }

        // Deduct money safely
        $user->wallet_balance -= $plan->price;
        $user->save();

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

        $this->modalType = 'success';
        $this->modalMessage = 'Success! You are now subscribed to ' . $plan->name . '. Enjoy your movies!';
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

<div class="max-w-5xl mx-auto space-y-10 relative">

    {{-- HEADER & REACTIVE WALLET --}}
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-zinc-950 border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Movie Plans</h1>
            <p class="text-slate-400 mt-1">Upgrade your access instantly using your wallet.</p>
        </div>
        <div class="flex items-center gap-3 bg-black px-5 py-3 rounded-xl border border-red-900/50">
            <div class="w-10 h-10 rounded-full bg-red-950/50 flex items-center justify-center text-red-500">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Wallet Balance</div>
                <div class="text-xl font-black text-red-500 font-mono">KES {{ number_format($this->currentBalance, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- ══════════════════ ACTIVE PLAN & SECURE SERVER COUNTDOWN ══════════════════ --}}
    @if($this->activeSubscription && $this->activeSubscription->plan)
        <div class="bg-gradient-to-r from-red-950/80 to-black border border-red-900/50 rounded-2xl p-8 shadow-2xl relative overflow-hidden">
            
            <div class="absolute -top-24 -right-24 w-64 h-64 bg-red-600/20 blur-3xl rounded-full pointer-events-none"></div>

            <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-6">
                <div>
                    <h2 class="text-sm font-bold text-red-500 uppercase tracking-widest mb-1">Current Active Plan</h2>
                    <div class="text-3xl font-black text-white">{{ $this->activeSubscription->plan->name }}</div>
                    
                    <div class="mt-4 flex items-center gap-4">
                        @if($this->activeSubscription->plan->can_download)
                            <span class="bg-red-600 text-white text-xs font-bold px-3 py-1.5 rounded-md flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Offline Downloads Active
                            </span>
                        @endif
                        <span class="text-sm text-slate-400">Purchased on {{ $this->activeSubscription->starts_at ? $this->activeSubscription->starts_at->format('M d, g:i A') : 'N/A' }}</span>
                    </div>
                </div>

                {{-- FIX: Server-Side Time Evaluation. It relies on PHP seconds, ignoring client clocks --}}
                @php
                    $secondsRemaining = now()->diffInSeconds($this->activeSubscription->expires_at, false);
                @endphp

                <div class="bg-black/50 border border-red-500/20 rounded-xl p-5 text-center min-w-[250px] backdrop-blur-md" 
                     x-data="{
                         secondsLeft: {{ $secondsRemaining }},
                         timeRemaining: 'Calculating...',
                         init() {
                             this.updateDisplay();
                             setInterval(() => {
                                 this.secondsLeft--;
                                 this.updateDisplay();
                             }, 1000);
                         },
                         updateDisplay() {
                             if (this.secondsLeft <= 0) {
                                 this.timeRemaining = 'EXPIRED';
                                 return;
                             }
                             let d = Math.floor(this.secondsLeft / (60 * 60 * 24));
                             let h = Math.floor((this.secondsLeft % (60 * 60 * 24)) / (60 * 60));
                             let m = Math.floor((this.secondsLeft % (60 * 60)) / 60);
                             let s = Math.floor(this.secondsLeft % 60);
                             
                             let result = '';
                             if (d > 0) result += d + 'd ';
                             if (h > 0 || d > 0) result += h + 'h ';
                             result += m + 'm ' + s + 's';
                             
                             this.timeRemaining = result;
                         }
                     }">
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Access Expires In</div>
                    <div class="text-2xl font-black text-red-500 font-mono tracking-tight" x-text="timeRemaining"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- PLAN CARDS --}}
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

    {{-- PURCHASE HISTORY TABLE --}}
    @if(count($this->subscriptionHistory) > 0)
        <div class="bg-zinc-950 border border-slate-800 rounded-2xl shadow-lg overflow-hidden mt-8">
            <div class="px-6 py-5 border-b border-slate-800 flex justify-between items-center bg-black">
                <h3 class="text-lg font-bold text-white">Purchase History</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Plan Name</th>
                            <th class="px-6 py-4">Valid Until</th>
                            <th class="px-6 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @foreach($this->subscriptionHistory as $history)
                            <tr class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-slate-300">{{ $history->created_at->format('M d, Y') }}</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ $history->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-slate-200 font-bold">{{ $history->plan->name ?? 'Deleted Plan' }}</span>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-slate-400">
                                    {{ $history->expires_at ? $history->expires_at->format('M d, Y - h:i A') : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if($history->status === 'active' && $history->expires_at && $history->expires_at > now())
                                        <span class="px-2.5 py-1 bg-red-900/30 text-red-500 border border-red-700/50 rounded-md text-[11px] font-bold">Active</span>
                                    @else
                                        <span class="px-2.5 py-1 bg-slate-800 text-slate-400 border border-slate-700 rounded-md text-[11px] font-medium">Expired</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 1. CONFIRMATION MODAL --}}
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

    {{-- 2. SUCCESS / ERROR RESULT MODAL --}}
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