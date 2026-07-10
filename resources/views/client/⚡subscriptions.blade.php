<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\MpesaService;

new class extends Component {
    public $confirmingPlanId = null;

    public $phone;
    public $missingAmount = 0;
    public $isProcessing = false;
    public $pendingCheckouts = [];

    public $showResultModal = false;
    public $modalType = 'success';
    public $modalMessage = '';

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
    public function plans()
    {
        return DB::table('plans')->where('is_active', true)->orderBy('price')->get();
    }

    #[Computed]
    public function currentBalance()
    {
        return DB::table('users')->where('id', Auth::id())->value('wallet_balance');
    }

    #[Computed]
    public function confirmingPlan()
    {
        return $this->confirmingPlanId ? DB::table('plans')->where('id', $this->confirmingPlanId)->first() : null;
    }

    #[Computed]
    public function activeSubscription()
    {
        return \App\Models\Subscription::with('plan')
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
        return DB::table('plan_purchases')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlanId = $planId;
        $plan = DB::table('plans')->where('id', $planId)->first();
        $currentWallet = $this->currentBalance;

        $this->missingAmount = max(0, $plan->price - $currentWallet);
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
                    throw new \Exception('Insufficient wallet balance. Please refresh.');
                }

                DB::table('users')->where('id', $userId)->decrement('wallet_balance', $plan->price);

                DB::table('subscriptions')
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

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
            $this->modalMessage = 'Plan activated successfully! KES ' . number_format($plan->price) . ' was deducted from your wallet.';
            $this->showResultModal = true;
            $this->confirmingPlanId = null;

        } catch (\Exception $e) {
            $this->modalType = 'error';
            $this->modalMessage = $e->getMessage();
            $this->showResultModal = true;
        }

        $this->isProcessing = false;
        unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
    }

    public function executeMpesaPurchase(MpesaService $mpesaService)
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/'],
        ]);

        $this->isProcessing = true;
        $reference = 'PLN' . $this->confirmingPlanId . 'UID' . Auth::id();

        $response = $mpesaService->stkPush($this->phone, $this->missingAmount, $reference);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            DB::table('mpesa_transactions')->insert([
                'user_id' => Auth::id(),
                'target_plan_id' => $this->confirmingPlanId,
                'merchant_request_id' => $response['MerchantRequestID'],
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $this->missingAmount,
                'phone' => $this->phone,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->pendingCheckouts[] = $response['CheckoutRequestID'];
        } else {
            $this->modalType = 'error';
            $this->modalMessage = 'M-Pesa STK Push failed to initiate. Please check your number and try again.';
            $this->showResultModal = true;
            $this->confirmingPlanId = null;
        }

        $this->isProcessing = false;
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
                $this->modalMessage = 'Payment received! Your plan is now active.';
                $this->showResultModal = true;
                $this->confirmingPlanId = null;
            } else {
                $this->modalType = 'error';
                $this->modalMessage = 'Payment failed or was cancelled: ' . ($tx->result_desc ?? 'Unknown error');
                $this->showResultModal = true;
            }

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

<div class="max-w-6xl mx-auto space-y-8 relative" wire:poll.3s="checkPendingStatus">

    {{-- 1. HEADER & WALLET BALANCE --}}
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">VOD Subscriptions</h1>
            <p class="text-slate-400 mt-1">Choose a plan or top up to keep watching.</p>
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

    {{-- 2. ACTIVE PLAN INDICATOR --}}
    @if($this->activeSubscription && $this->activeSubscription->plan)
        <div class="bg-gradient-to-r from-red-950 to-black border border-red-900 rounded-2xl p-6 shadow-2xl flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <p class="text-sm font-bold text-red-500 uppercase tracking-widest mb-1">Active Plan</p>
                <p class="text-3xl font-black text-white">{{ $this->activeSubscription->plan->name }}</p>
            </div>
            <div class="bg-black/60 border border-red-500/30 rounded-xl p-4 text-center min-w-[200px]">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-1">Expires On</p>
                <p class="text-lg font-bold text-red-500 font-mono">{{ \Carbon\Carbon::parse($this->activeSubscription->expires_at)->format('M d, Y h:i A') }}</p>
            </div>
        </div>
    @endif

    {{-- 3. PLANS GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($this->plans as $plan)
            <div class="bg-[#111111] border border-slate-800 hover:border-red-600/50 transition-colors duration-300 rounded-2xl p-6 flex flex-col justify-between shadow-lg relative overflow-hidden">
                @if($plan->can_download)
                    <div class="absolute top-0 right-0 bg-red-600 text-[10px] font-bold px-3 py-1 rounded-bl-lg text-white uppercase tracking-wider">Downloads</div>
                @endif

                <div>
                    <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                    <p class="text-3xl font-black text-red-600 mb-4">KES {{ number_format($plan->price, 0) }}</p>
                    <ul class="text-sm text-slate-400 space-y-2 mb-8">
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}
                        </li>
                    </ul>
                </div>

                <button wire:click="confirmPurchase({{ $plan->id }})" class="w-full py-3 rounded-xl bg-zinc-900 text-red-500 border border-red-600/30 hover:bg-red-600 hover:text-white font-bold transition shadow-sm">
                    Select Plan
                </button>
            </div>
        @endforeach
    </div>

    {{-- 4. THE TRANSPARENT LEDGER --}}
    <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden mt-8">
        <div class="px-6 py-5 border-b border-slate-800 bg-black">
            <h3 class="text-lg font-bold text-white">Subscription Ledger</h3>
            <p class="text-sm text-slate-400">Record of plans paid for using your wallet balance.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Plan Name</th>
                        <th class="px-6 py-4">Duration</th>
                        <th class="px-6 py-4 text-right">Amount Deducted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->purchaseLedger as $entry)
                        <tr class="hover:bg-zinc-900/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-slate-300">{{ \Carbon\Carbon::parse($entry->created_at)->format('M d, Y') }}</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">{{ \Carbon\Carbon::parse($entry->created_at)->format('h:i A') }}</div>
                            </td>
                            <td class="px-6 py-4 font-bold text-white">{{ $entry->plan_name }}</td>
                            <td class="px-6 py-4 text-slate-400">{{ $entry->duration_minutes }} mins</td>
                            <td class="px-6 py-4 text-right font-mono text-red-400 font-bold">- KES {{ number_format($entry->amount_paid, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                No plan purchases found in your history.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 5. CONFIRMATION MODAL --}}
    @if($this->confirmingPlan)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6">

                <h2 class="text-xl font-bold text-white mb-1">Confirm Subscription</h2>
                <p class="text-sm text-slate-400 mb-6">You selected <strong class="text-white">{{ $this->confirmingPlan->name }}</strong></p>

                @if(count($pendingCheckouts) > 0)
                    {{-- AWAITING MPESA PIN --}}
                    <div class="bg-amber-950/30 border border-amber-900/50 rounded-xl p-6 text-center">
                        <svg class="w-10 h-10 animate-spin text-amber-500 mx-auto mb-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <h3 class="text-lg font-bold text-amber-500 mb-2">Check Your Phone</h3>
                        <p class="text-sm text-amber-500/70">Please enter your M-Pesa PIN. The plan will activate automatically once the payment clears.</p>
                    </div>
                @else
                    {{-- RECEIPT BREAKDOWN --}}
                    <div class="bg-black border border-slate-800 rounded-xl p-5 mb-6 space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-400">Plan Cost</span>
                            <span class="text-white font-mono">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-400">Wallet Balance</span>
                            <span class="text-red-500 font-mono">KES {{ number_format($this->currentBalance, 0) }}</span>
                        </div>
                        <div class="border-t border-slate-800 pt-3 flex justify-between font-bold">
                            @if($missingAmount <= 0)
                                <span class="text-white">Amount to Deduct</span>
                                <span class="text-red-500 font-mono">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                            @else
                                <span class="text-white">Deficit Required</span>
                                <span class="text-amber-500 font-mono">KES {{ number_format($missingAmount, 0) }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- ACTIONS --}}
                    @if($missingAmount <= 0)
                        <button wire:click="executeWalletPurchase" wire:loading.attr="disabled" class="w-full py-3.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition mb-3">
                            <span wire:loading.remove wire:target="executeWalletPurchase">Confirm & Deduct from Wallet</span>
                            <span wire:loading wire:target="executeWalletPurchase">Processing...</span>
                        </button>
                    @else
                        <div class="space-y-4 mb-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">M-Pesa Number (Who is paying?)</label>
                                <input type="text" wire:model="phone" class="w-full bg-black border @error('phone') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-xl px-4 py-3 text-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 transition">
                                @error('phone') <span class="text-xs text-red-500 mt-1 block font-medium">{{ $message }}</span> @enderror
                            </div>

                            <button wire:click="executeMpesaPurchase" wire:loading.attr="disabled" class="w-full py-3.5 rounded-xl bg-[#25D366] hover:bg-[#20b858] text-black font-bold transition flex items-center justify-center gap-2">
                                <span wire:loading.remove wire:target="executeMpesaPurchase">Top-Up Deficit (KES {{ number_format($missingAmount, 0) }})</span>
                                <span wire:loading wire:target="executeMpesaPurchase" class="flex items-center gap-2">
                                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    Pushing to Phone...
                                </span>
                            </button>
                        </div>
                    @endif
                @endif

                <button wire:click="closeModals" wire:loading.attr="disabled" class="w-full py-3.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- 6. RESULT MODAL --}}
    @if($showResultModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6 text-center">
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

                @if($modalType === 'success')
                    <button onclick="window.location.reload()" class="w-full py-3.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition">Done</button>
                @else
                    <button wire:click="closeModals" class="w-full py-3.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">Close</button>
                @endif
            </div>
        </div>
    @endif
</div>
