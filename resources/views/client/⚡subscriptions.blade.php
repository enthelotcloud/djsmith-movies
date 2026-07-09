<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\PlanPurchase;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

new class extends Component {
    
    // 🚨 FIX: We ONLY store the ID (a number) so Livewire never throws a 419 serialization error
    public $confirmingPlanId = null; 
    
    public $showResultModal = false;
    public $modalType = ''; 
    public $modalMessage = '';
    public $isProcessing = false;
    public $phone;
    public $pendingCheckouts = [];

    public function mount()
    {
        $this->phone = Auth::user()->phone;
        $this->pendingCheckouts = DB::table('mpesa_transactions')
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->whereNotNull('target_plan_id')
            ->pluck('checkout_request_id')
            ->toArray();
    }

    #[Computed]
    public function plans() { return Plan::where('is_active', true)->orderBy('price')->get(); }

    #[Computed]
    public function currentBalance() { return DB::table('users')->where('id', Auth::id())->value('wallet_balance'); }

    // FETCH CONFIRMING PLAN DYNAMICALLY (Safe for Livewire)
    #[Computed]
    public function confirmingPlan()
    {
        return $this->confirmingPlanId ? Plan::find($this->confirmingPlanId) : null;
    }

    #[Computed]
    public function activeSubscription()
    {
        return Subscription::with('plan')
            ->where('user_id', Auth::id())
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    #[Computed]
    public function purchaseLedger()
    {
        return PlanPurchase::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlanId = $planId; // Storing just the integer!
    }

    public function executeWalletPurchase()
    {
        $this->isProcessing = true;
        $userId = Auth::id();
        $plan = $this->confirmingPlan;

        try {
            DB::transaction(function () use ($userId, $plan) {
                $currentWallet = DB::table('users')->where('id', $userId)->lockForUpdate()->value('wallet_balance');

                if ($currentWallet < $plan->price) {
                    throw new \Exception('Insufficient funds.');
                }

                // 1. DEDUCT WALLET
                DB::table('users')->where('id', $userId)->decrement('wallet_balance', $plan->price);

                // 2. DEACTIVATE OLD PLANS
                DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')->update(['status' => 'inactive']);

                // 3. GRANT ACCESS
                DB::table('subscriptions')->insert([
                    'user_id' => $userId,
                    'plan_id' => $plan->id,
                    'amount_paid' => $plan->price,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addMinutes($plan->duration_minutes),
                    'auto_renew' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 4. 🚨 WRITE PERMANENT RECEIPT TO LEDGER
                DB::table('plan_purchases')->insert([
                    'user_id' => $userId,
                    'plan_name' => $plan->name,
                    'amount_paid' => $plan->price,
                    'duration_minutes' => $plan->duration_minutes,
                    'expires_at' => now()->addMinutes($plan->duration_minutes),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->modalType = 'success';
            $this->modalMessage = 'Success! Deducted KES ' . number_format($plan->price, 0) . ' from your wallet for ' . $plan->name . '.';
            $this->showResultModal = true;

        } catch (\Exception $e) {
            Log::error('Wallet Purchase Error: ' . $e->getMessage());
            $this->modalType = 'error';
            $this->modalMessage = 'Transaction Failed: ' . $e->getMessage();
            $this->showResultModal = true;
        }

        $this->confirmingPlanId = null;
        $this->isProcessing = false;
        unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
    }

    public function executeMpesaPurchase(MpesaService $mpesaService)
    {
        $this->validate(['phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/']]);
        
        $plan = $this->confirmingPlan;
        $missingAmount = max(0, $plan->price - $this->currentBalance);
        $this->isProcessing = true;
        
        $reference = 'PLN' . $plan->id . 'UID' . Auth::id();
        $response = $mpesaService->stkPush($this->phone, $missingAmount, $reference);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            DB::table('mpesa_transactions')->insert([
                'user_id' => Auth::id(),
                'target_plan_id' => $plan->id,
                'merchant_request_id' => $response['MerchantRequestID'],
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $missingAmount,
                'phone' => $this->phone,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->pendingCheckouts[] = $response['CheckoutRequestID'];
            $this->isProcessing = false;
        } else {
            $this->modalType = 'error';
            $this->modalMessage = 'M-Pesa STK Push failed. Please try again.';
            $this->showResultModal = true;
            $this->confirmingPlanId = null;
            $this->isProcessing = false;
        }
    }

    public function checkPendingStatus()
    {
        if (empty($this->pendingCheckouts)) return;

        $updatedTx = DB::table('mpesa_transactions')
            ->whereIn('checkout_request_id', $this->pendingCheckouts)
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        foreach ($updatedTx as $tx) {
            if ($tx->status === 'completed') {
                $this->modalType = 'success';
                $this->modalMessage = 'M-Pesa Deposit Received! Your plan was automatically purchased.';
                $this->showResultModal = true;
            } else {
                $this->modalType = 'error';
                $this->modalMessage = 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled by user');
                $this->showResultModal = true;
            }
            $this->confirmingPlanId = null;
            $this->pendingCheckouts = array_diff($this->pendingCheckouts, [$tx->checkout_request_id]);
            unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
        }
    }

    public function closeModals()
    {
        $this->confirmingPlanId = null;
        $this->showResultModal = false;
    }
};
?>

<div class="max-w-5xl mx-auto space-y-10 relative" wire:poll.3s="checkPendingStatus">

    {{-- HEADER & REACTIVE WALLET --}}
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
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

    {{-- ══════════════════ 1. ACTIVE PLAN BANNER & LIVE TIMER ══════════════════ --}}
    @if($this->activeSubscription && $this->activeSubscription->plan)
        <div class="bg-gradient-to-r from-red-950 to-black border border-red-900 rounded-2xl p-8 shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-red-600/10 blur-3xl rounded-full pointer-events-none"></div>
            <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-6">
                <div>
                    <h2 class="text-sm font-bold text-red-500 uppercase tracking-widest mb-1">Currently Active</h2>
                    <div class="text-4xl font-black text-white">{{ $this->activeSubscription->plan->name }}</div>
                </div>

                @php $secondsRemaining = now()->diffInSeconds(\Carbon\Carbon::parse($this->activeSubscription->expires_at), false); @endphp

                <div class="bg-black/60 border border-red-500/30 rounded-xl p-5 text-center min-w-[250px]" 
                     x-data="{
                         s: {{ max(0, $secondsRemaining) }},
                         lbl: 'Loading...',
                         init() { this.tick(); setInterval(() => { this.s--; this.tick(); }, 1000); },
                         tick() {
                             if (this.s <= 0) { this.lbl = 'EXPIRED'; return; }
                             let d = Math.floor(this.s / 86400);
                             let h = Math.floor((this.s % 86400) / 3600);
                             let m = Math.floor((this.s % 3600) / 60);
                             let sec = Math.floor(this.s % 60);
                             this.lbl = (d>0?d+'d ':'') + (h>0||d>0?h+'h ':'') + m+'m ' + sec+'s';
                         }
                     }">
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Access Expires In</div>
                    <div class="text-3xl font-black text-red-500 font-mono tracking-tight" x-text="lbl"></div>
                </div>
            </div>
        </div>
    @else
        {{-- EMPTY STATE --}}
        <div class="bg-[#111111] border border-slate-800 rounded-2xl p-8 shadow-lg text-center flex flex-col items-center justify-center">
            <div class="w-12 h-12 bg-zinc-900 text-slate-500 rounded-full flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-white mb-1">No Active Plan</h2>
            <p class="text-sm text-slate-400">You currently don't have an active subscription. Select a plan below.</p>
        </div>
    @endif

    {{-- ══════════════════ 2. PLAN CARDS (WITH UPGRADE LOGIC) ══════════════════ --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @forelse($this->plans as $plan)
            <div class="bg-black border border-slate-800 rounded-2xl p-8 flex flex-col relative overflow-hidden shadow-lg">
                @if($plan->can_download)
                    <div class="absolute top-0 right-0 bg-red-600 text-xs font-bold px-3 py-1 rounded-bl-lg text-white">Downloads</div>
                @endif
                <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                <div class="text-3xl font-black text-red-600 mb-6">KES {{ number_format($plan->price, 0) }}</div>

                <ul class="space-y-3 mb-8 flex-1 text-sm text-slate-300">
                    <li class="flex items-center gap-2">HD Streaming</li>
                    <li class="flex items-center gap-2">Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}</li>
                </ul>

                @php
                    $hasActive = !is_null($this->activeSubscription);
                    $isCurrent = $hasActive && $this->activeSubscription->plan_id === $plan->id;
                    $btnText = $isCurrent ? 'Extend Time' : ($hasActive ? 'Upgrade Plan' : 'Select Plan');
                    $btnColor = $isCurrent ? 'bg-red-900/50 text-red-500 border-red-600/50 hover:bg-red-600 hover:text-white' : ($hasActive ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-zinc-900 text-red-500 border-red-600/30 hover:bg-red-600 hover:text-white');
                @endphp

                <button wire:click="confirmPurchase({{ $plan->id }})" class="w-full py-3 rounded-lg border {{ $btnColor }} font-bold transition-all">
                    {{ $btnText }}
                </button>
            </div>
        @empty
            <div class="col-span-3 text-center text-slate-500 py-12 bg-black border border-slate-800 rounded-2xl">No plans available.</div>
        @endforelse
    </div>

    {{-- ══════════════════ 3. PLAN PURCHASE HISTORY LEDGER ══════════════════ --}}
    <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden mt-8">
        <div class="px-6 py-5 border-b border-slate-800 bg-black"><h3 class="text-lg font-bold text-white">Plan History</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Plan Name</th>
                        <th class="px-6 py-4 text-right">Amount Deducted</th>
                        <th class="px-6 py-4 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->purchaseLedger as $history)
                        <tr class="hover:bg-zinc-900/50">
                            <td class="px-6 py-4">
                                <div class="text-slate-300">{{ $history->created_at->format('M d, Y') }}</div>
                                <div class="text-[11px] text-slate-500">{{ $history->created_at->format('h:i A') }}</div>
                            </td>
                            <td class="px-6 py-4"><span class="text-slate-200 font-bold">{{ $history->plan_name }}</span></td>
                            <td class="px-6 py-4 text-right font-mono text-red-400 font-bold">- KES {{ number_format($history->amount_paid, 2) }}</td>
                            <td class="px-6 py-4 text-right">
                                @php $sec = now()->diffInSeconds($history->expires_at, false); @endphp
                                @if($sec > 0)
                                    <div class="inline-flex items-center gap-2 bg-red-900/30 text-red-500 border border-red-700/50 px-3 py-1 rounded-md">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                                        <span x-data="{ s: {{ $sec }}, lbl: '', init() { this.tk(); setInterval(() => { this.s--; this.tk(); }, 1000); }, tk() { if (this.s <= 0) { this.lbl = 'EXPIRED'; return; } let m = Math.floor((this.s % 3600) / 60); let sec = Math.floor(this.s % 60); this.lbl = m+'m ' + sec+'s'; } }" x-text="lbl" class="font-mono text-xs font-bold"></span>
                                    </div>
                                @else
                                    <span class="px-3 py-1.5 bg-slate-800 text-slate-500 rounded-md text-xs font-bold uppercase">Expired</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                <div class="flex flex-col items-center justify-center">
                                    <svg class="w-8 h-8 mb-2 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    No purchase history found. Your transparent ledger will appear here.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ══════════════════ 4. CONFIRMATION MODAL ══════════════════ --}}
    @if($this->confirmingPlan)
        @php
            $missingAmount = max(0, $this->confirmingPlan->price - $this->currentBalance);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-6 text-center">
                    
                    @if(count($pendingCheckouts) > 0)
                        <div class="w-16 h-16 bg-amber-950/50 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-amber-900/50 animate-pulse">
                            <svg class="w-8 h-8 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Check Your Phone</h3>
                        <p class="text-sm text-slate-400 mb-6">Enter your M-Pesa PIN. We will automatically activate your plan when the deposit reaches your wallet.</p>
                    @else
                        <div class="w-16 h-16 bg-red-950/50 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 border border-red-900/50">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">{{ $this->confirmingPlan->name }}</h3>
                        
                        @if($missingAmount <= 0)
                            <p class="text-sm text-slate-400 mb-6">Deduct <strong class="text-red-500">KES {{ number_format($this->confirmingPlan->price, 2) }}</strong> from your wallet?</p>
                            <button wire:click="executeWalletPurchase" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold transition mb-3">
                                <span wire:loading.remove wire:target="executeWalletPurchase">Confirm & Deduct</span>
                                <span wire:loading wire:target="executeWalletPurchase">Processing...</span>
                            </button>
                        @else
                            <div class="bg-red-950/30 border border-red-900/50 rounded-xl p-4 mb-5 text-left">
                                <div class="text-xs text-slate-400">Plan Cost: <span class="float-right">KES {{ number_format($this->confirmingPlan->price, 0) }}</span></div>
                                <div class="text-xs text-slate-400">Wallet: <span class="float-right text-red-400">- KES {{ number_format($this->currentBalance, 0) }}</span></div>
                                <div class="border-t border-slate-800 my-2 pt-2 text-sm font-bold text-white">Top-up Required: <span class="float-right">KES {{ number_format($missingAmount, 0) }}</span></div>
                            </div>
                            
                            <div class="mb-4 text-left">
                                <label class="block text-xs font-medium text-slate-400 mb-1.5">M-Pesa Number</label>
                                <input type="text" wire:model="phone" class="w-full bg-black border border-slate-700 rounded-lg px-4 py-2.5 text-slate-200">
                                @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <button wire:click="executeMpesaPurchase" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-[#25D366] hover:bg-[#20b858] text-black font-bold mb-3">
                                <span wire:loading.remove wire:target="executeMpesaPurchase">Top-Up KES {{ number_format($missingAmount, 0) }} via M-Pesa</span>
                                <span wire:loading wire:target="executeMpesaPurchase">Pushing...</span>
                            </button>
                        @endif
                        
                        <button wire:click="closeModals" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium">Cancel</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ 5. RESULT MODAL ══════════════════ --}}
    @if($showResultModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
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
                        <h3 class="text-xl font-bold text-white mb-2">Notice</h3>
                    @endif
                    <p class="text-sm text-slate-400 mb-6 leading-relaxed">{{ $modalMessage }}</p>
                    <button wire:click="closeModals" class="w-full py-3 rounded-lg bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>