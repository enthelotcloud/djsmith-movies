<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {
    
    public $confirmingPlan = null;
    public $showResultModal = false;
    public $modalType = ''; 
    public $modalMessage = '';
    public $isProcessing = false;

    // Direct M-Pesa fields
    public $phone;
    public $missingAmount = 0;
    public $pendingCheckouts = [];

    public function mount()
    {
        $this->phone = Auth::user()->phone;

        // Pick up tracking if they refresh mid-payment
        $this->pendingCheckouts = MpesaTransaction::where('user_id', Auth::id())
            ->where('status', 'pending')
            ->whereNotNull('target_plan_id')
            ->pluck('checkout_request_id')
            ->toArray();
    }

    #[Computed]
    public function plans() { return Plan::where('is_active', true)->orderBy('price')->get(); }

    #[Computed]
    public function currentBalance() { return DB::table('users')->where('id', Auth::id())->value('wallet_balance'); }

    #[Computed]
    public function activeSubscription()
    {
        return Subscription::with('plan')->where('user_id', Auth::id())->where('status', 'active')
            ->whereNotNull('expires_at')->where('expires_at', '>', now())->first();
    }

    #[Computed]
    public function subscriptionHistory()
    {
        return Subscription::with('plan')->where('user_id', Auth::id())->whereNotNull('plan_id') 
            ->orderBy('created_at', 'desc')->take(20)->get();
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlan = Plan::find($planId);
        $currentWallet = DB::table('users')->where('id', Auth::id())->value('wallet_balance');
        
        // Calculate exactly what they are missing
        $this->missingAmount = max(0, $this->confirmingPlan->price - $currentWallet);
    }

    // PATH A: They have enough money in the wallet
    public function executeWalletPurchase()
    {
        $this->isProcessing = true;
        $userId = Auth::id();
        $plan = $this->confirmingPlan;
        
        DB::table('users')->where('id', $userId)->decrement('wallet_balance', $plan->price);

        Subscription::where('user_id', $userId)->where('status', 'active')->update(['status' => 'inactive']);

        Subscription::create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMinutes($plan->duration_minutes),
            'auto_renew' => true, 
        ]);

        $this->modalType = 'success';
        $this->modalMessage = 'Success! Wallet deducted KES ' . number_format($plan->price, 0) . ' for ' . $plan->name . '.';
        $this->showResultModal = true;
        $this->confirmingPlan = null;
        $this->isProcessing = false;
        
        unset($this->currentBalance, $this->activeSubscription, $this->subscriptionHistory);
    }

    // PATH B: They need to pay the missing amount via M-Pesa STK
    public function executeMpesaPurchase(MpesaService $mpesaService)
    {
        $this->validate(['phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/']]);
        
        $this->isProcessing = true;
        $reference = 'PLN' . $this->confirmingPlan->id . 'UID' . Auth::id();

        $response = $mpesaService->stkPush($this->phone, $this->missingAmount, $reference);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            MpesaTransaction::create([
                'user_id' => Auth::id(),
                'target_plan_id' => $this->confirmingPlan->id, // This tells the webhook to auto-buy!
                'merchant_request_id' => $response['MerchantRequestID'],
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $this->missingAmount,
                'phone' => $this->phone,
                'status' => 'pending',
            ]);

            $this->pendingCheckouts[] = $response['CheckoutRequestID'];
            $this->isProcessing = false;
            // Leave the modal open so the poller can show success in real time
        } else {
            $this->modalType = 'error';
            $this->modalMessage = 'M-Pesa STK Push failed. Please try again.';
            $this->showResultModal = true;
            $this->confirmingPlan = null;
            $this->isProcessing = false;
        }
    }

    // Poller to catch the webhook success
    public function checkPendingStatus()
    {
        if (empty($this->pendingCheckouts)) return;

        $updatedTx = MpesaTransaction::whereIn('checkout_request_id', $this->pendingCheckouts)
            ->whereIn('status', ['completed', 'failed'])->get();

        foreach ($updatedTx as $tx) {
            if ($tx->status === 'completed') {
                $this->modalType = 'success';
                $this->modalMessage = 'M-Pesa Payment Received! Your ' . $this->confirmingPlan->name . ' is now active.';
                $this->showResultModal = true;
                $this->confirmingPlan = null;
                
                unset($this->currentBalance, $this->activeSubscription, $this->subscriptionHistory);
            } else {
                $this->modalType = 'error';
                $this->modalMessage = 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled by user');
                $this->showResultModal = true;
                $this->confirmingPlan = null;
            }
            $this->pendingCheckouts = array_diff($this->pendingCheckouts, [$tx->checkout_request_id]);
        }
    }

    public function closeModals()
    {
        $this->confirmingPlan = null;
        $this->showResultModal = false;
    }
};
?>

<div class="max-w-5xl mx-auto space-y-10 relative" wire:poll.3s="checkPendingStatus">

    {{-- HEADER & REACTIVE WALLET --}}
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-zinc-950 border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Movie Plans</h1>
            <p class="text-slate-400 mt-1">Select a plan to start watching.</p>
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

    {{-- ACTIVE PLAN & SERVER COUNTDOWN --}}
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
                                Offline Downloads Active
                            </span>
                        @endif
                        <span class="text-sm text-slate-400">Purchased for KES {{ number_format($this->activeSubscription->amount_paid, 0) }}</span>
                    </div>
                </div>

                @php $secondsRemaining = now()->diffInSeconds($this->activeSubscription->expires_at, false); @endphp

                <div class="bg-black/50 border border-red-500/20 rounded-xl p-5 text-center min-w-[250px] backdrop-blur-md" 
                     x-data="{
                         secondsLeft: {{ $secondsRemaining }},
                         timeRemaining: 'Calculating...',
                         init() {
                             this.updateDisplay();
                             setInterval(() => { this.secondsLeft--; this.updateDisplay(); }, 1000);
                         },
                         updateDisplay() {
                             if (this.secondsLeft <= 0) { this.timeRemaining = 'EXPIRED'; return; }
                             let d = Math.floor(this.secondsLeft / 86400);
                             let h = Math.floor((this.secondsLeft % 86400) / 3600);
                             let m = Math.floor((this.secondsLeft % 3600) / 60);
                             let s = Math.floor(this.secondsLeft % 60);
                             this.timeRemaining = (d>0?d+'d ':'') + (h>0||d>0?h+'h ':'') + m+'m ' + s+'s';
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
                    <div class="absolute top-0 right-0 bg-red-600 text-xs font-bold px-3 py-1 rounded-bl-lg text-white shadow-md">Downloads</div>
                @endif

                <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                <div class="text-3xl font-black text-red-600 mb-6">KES {{ number_format($plan->price, 0) }}</div>

                <ul class="space-y-3 mb-8 flex-1 text-sm text-slate-300">
                    <li class="flex items-center gap-2">HD Streaming</li>
                    <li class="flex items-center gap-2">Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}</li>
                </ul>

                @php
                    $isCurrent = $this->activeSubscription && $this->activeSubscription->plan_id === $plan->id;
                    $hasActive = !is_null($this->activeSubscription);
                    $btnText = $isCurrent ? 'Extend Plan' : ($hasActive ? 'Switch Plan' : 'Select Plan');
                    $btnColor = $isCurrent ? 'bg-red-600 text-white' : 'bg-zinc-900 text-red-500 hover:bg-red-600 hover:text-white';
                @endphp

                <button wire:click="confirmPurchase({{ $plan->id }})" class="w-full py-3 rounded-lg border border-red-600/30 {{ $btnColor }} font-bold transition-all shadow-sm">
                    {{ $btnText }}
                </button>
            </div>
        @empty
            <div class="col-span-3 text-center text-slate-500 py-12 bg-black border border-slate-800 rounded-2xl">No plans available.</div>
        @endforelse
    </div>

    {{-- PURCHASE HISTORY TABLE --}}
    @if(count($this->subscriptionHistory) > 0)
        <div class="bg-zinc-950 border border-slate-800 rounded-2xl shadow-lg overflow-hidden mt-8">
            <div class="px-6 py-5 border-b border-slate-800 bg-black"><h3 class="text-lg font-bold text-white">Transparent Ledger</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Plan</th>
                            <th class="px-6 py-4 text-right">Deducted</th>
                            <th class="px-6 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @foreach($this->subscriptionHistory as $history)
                            <tr class="hover:bg-slate-800/20">
                                <td class="px-6 py-4">
                                    <div class="text-slate-300">{{ $history->created_at->format('M d, Y') }}</div>
                                    <div class="text-[11px] text-slate-500">{{ $history->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="px-6 py-4"><span class="text-slate-200 font-bold">{{ $history->plan->name ?? 'Deleted' }}</span></td>
                                <td class="px-6 py-4 text-right font-mono text-red-400">- KES {{ number_format($history->amount_paid, 2) }}</td>
                                <td class="px-6 py-4 text-right">
                                    @php $sec = $history->expires_at ? now()->diffInSeconds($history->expires_at, false) : 0; @endphp
                                    @if($history->status === 'active' && $sec > 0)
                                        <div class="inline-flex items-center gap-2 bg-red-900/30 text-red-500 border border-red-700/50 px-3 py-1 rounded-md">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span> Active
                                        </div>
                                    @else
                                        <span class="px-3 py-1 bg-slate-800 text-slate-400 rounded-md text-xs">Expired</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- THE DUAL-MODE MODAL --}}
    @if($confirmingPlan)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-zinc-950 border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-6 text-center">
                    
                    @if(count($pendingCheckouts) > 0)
                        {{-- M-PESA WAITING STATE --}}
                        <div class="w-16 h-16 bg-amber-950/50 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-amber-900/50 animate-pulse">
                            <svg class="w-8 h-8 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Check Your Phone</h3>
                        <p class="text-sm text-slate-400 mb-6">Enter your M-Pesa PIN. We will automatically activate your plan when payment is received.</p>
                    @else
                        {{-- NORMAL CONFIRMATION STATE --}}
                        <div class="w-16 h-16 bg-red-950/50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">{{ $confirmingPlan->name }}</h3>
                        
                        @if($missingAmount <= 0)
                            <p class="text-sm text-slate-400 mb-6">Deduct <strong class="text-red-500">KES {{ number_format($confirmingPlan->price, 2) }}</strong> from your wallet?</p>
                            <button wire:click="executeWalletPurchase" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm mb-3">Pay From Wallet</button>
                        @else
                            <div class="bg-red-950/30 border border-red-900/50 rounded-xl p-4 mb-5 text-left">
                                <div class="text-xs text-slate-400">Plan Cost: <span class="float-right">KES {{ number_format($confirmingPlan->price, 0) }}</span></div>
                                <div class="text-xs text-slate-400">Wallet: <span class="float-right text-red-400">- KES {{ number_format($this->currentBalance, 0) }}</span></div>
                                <div class="border-t border-slate-800 my-2 pt-2 text-sm font-bold text-white">Missing: <span class="float-right">KES {{ number_format($missingAmount, 0) }}</span></div>
                            </div>
                            
                            <div class="mb-4 text-left">
                                <label class="block text-xs font-medium text-slate-400 mb-1.5">M-Pesa Number</label>
                                <input type="text" wire:model="phone" class="w-full bg-black border border-slate-700 rounded-lg px-4 py-2.5 text-slate-200 focus:border-red-500">
                                @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <button wire:click="executeMpesaPurchase" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-[#25D366] hover:bg-[#20b858] text-black font-bold transition shadow-sm mb-3">
                                <span wire:loading.remove wire:target="executeMpesaPurchase">Pay KES {{ number_format($missingAmount, 0) }} with M-Pesa</span>
                                <span wire:loading wire:target="executeMpesaPurchase">Pushing...</span>
                            </button>
                        @endif
                        
                        <button wire:click="closeModals" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">Cancel</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- SUCCESS / ERROR RESULT MODAL --}}
    @if($showResultModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-zinc-950 border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-6 text-center">
                    @if($modalType === 'success')
                        <div class="w-16 h-16 bg-emerald-950/50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-emerald-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Success!</h3>
                    @else
                        <div class="w-16 h-16 bg-red-950/50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Error</h3>
                    @endif
                    <p class="text-sm text-slate-400 mb-6 leading-relaxed">{{ $modalMessage }}</p>
                    <button wire:click="closeModals" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>