<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\WalletService;
use App\Services\MpesaService;

new class extends Component {
    public $confirmingPlanId = null;
    public $phone;
    public $isProcessing = false;
    public $pendingCheckouts = [];

    public $showResultModal = false;
    public $modalType = 'success';
    public $modalMessage = '';

    public function mount()
    {
        $this->phone = Auth::user()->phone;
        // Check if there's a pending M-Pesa payment specifically for a plan
        $this->pendingCheckouts = DB::table('mpesa_transactions')
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->whereNotNull('target_plan_id')
            ->pluck('checkout_request_id')
            ->toArray();
    }

    #[Computed]
    public function plans() { return DB::table('plans')->where('is_active', true)->orderBy('price')->get(); }

    #[Computed]
    public function currentBalance() { return DB::table('users')->where('id', Auth::id())->value('wallet_balance'); }

    #[Computed]
    public function activeSubscription()
    {
        return DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.user_id', Auth::id())
            ->where('subscriptions.status', 'active')
            ->where('subscriptions.expires_at', '>', now())
            ->select('subscriptions.*', 'plans.name as plan_name', 'plans.id as plan_id')
            ->orderBy('subscriptions.created_at', 'desc')
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

    #[Computed]
    public function confirmingPlan() { return $this->confirmingPlanId ? DB::table('plans')->where('id', $this->confirmingPlanId)->first() : null; }

    #[Computed]
    public function missingAmount() {
        if (!$this->confirmingPlan) return 0;
        return max(0, $this->confirmingPlan->price - $this->currentBalance);
    }

    public function confirmPurchase($planId)
    {
        $this->confirmingPlanId = $planId;
    }

    public function executeWalletPurchase(WalletService $walletService)
    {
        $this->isProcessing = true;
        try {
            $walletService->purchasePlan(Auth::id(), $this->confirmingPlanId);
            $this->modalType = 'success';
            $this->modalMessage = 'Success! ' . $this->confirmingPlan->name . ' activated. KES ' . number_format($this->confirmingPlan->price) . ' deducted from wallet.';
            $this->showResultModal = true;
            $this->confirmingPlanId = null;
            unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
        } catch (\Exception $e) {
            $this->modalType = 'error';
            $this->modalMessage = $e->getMessage();
            $this->showResultModal = true;
        }
        $this->isProcessing = false;
    }

    public function executeMpesaPurchase(MpesaService $mpesaService)
    {
        $this->validate(['phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/']]);
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
            $this->modalMessage = 'M-Pesa push failed. Please check your number and try again.';
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
                $this->modalMessage = 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled');
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

<div class="max-w-6xl mx-auto space-y-8 relative" wire:poll.10s="checkPendingStatus">

    {{-- ACTIVE PLAN BANNER --}}
    @if($this->activeSubscription)
        <div class="bg-gradient-to-r from-red-950 to-black border border-red-900 rounded-2xl p-8 shadow-2xl flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <p class="text-sm font-bold text-red-500 uppercase tracking-widest mb-1">Your Active Plan</p>
                <h2 class="text-4xl font-black text-white">{{ $this->activeSubscription->plan_name }}</h2>
            </div>
            <div class="bg-black/60 border border-red-500/30 rounded-xl p-5 text-center min-w-[250px]">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2">Access Expires On</p>
                <p class="text-xl font-bold text-red-500 font-mono">{{ \Carbon\Carbon::parse($this->activeSubscription->expires_at)->format('M d, Y h:i A') }}</p>
            </div>
        </div>
    @else
        <div class="bg-[#111111] border border-slate-800 rounded-2xl p-8 shadow-lg text-center flex flex-col items-center">
            <div class="w-16 h-16 bg-zinc-900 text-slate-500 rounded-full flex items-center justify-center mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">No Active Plan</h2>
            <p class="text-sm text-slate-400">You currently don't have an active subscription. Select a plan below to start watching.</p>
        </div>
    @endif

    {{-- PLANS GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($this->plans as $plan)
            @php
                $isActivePlan = $this->activeSubscription && $this->activeSubscription->plan_id === $plan->id;
                $hasActivePlan = !is_null($this->activeSubscription);
            @endphp

            <div class="bg-[#111111] border {{ $isActivePlan ? 'border-red-600 shadow-red-900/20' : 'border-slate-800 hover:border-red-600/50' }} transition-colors duration-300 rounded-2xl p-8 flex flex-col relative shadow-lg">
                @if($plan->can_download)
                    <div class="absolute top-0 right-0 bg-red-600 text-[10px] font-bold px-3 py-1 rounded-bl-lg text-white uppercase tracking-wider">Downloads</div>
                @endif

                <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                <p class="text-3xl font-black text-red-600 mb-6">KES {{ number_format($plan->price, 0) }}</p>
                <ul class="text-sm text-slate-300 space-y-3 mb-8 flex-1">
                    <li class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}
                    </li>
                </ul>

                <button wire:click="confirmPurchase({{ $plan->id }})" class="w-full py-3.5 rounded-xl font-bold transition shadow-sm {{ $isActivePlan ? 'bg-red-900/50 text-red-500 border border-red-600/50 hover:bg-red-600 hover:text-white' : ($hasActivePlan ? 'bg-zinc-800 text-white hover:bg-red-600' : 'bg-red-600 text-white hover:bg-red-700') }}">
                    {{ $isActivePlan ? 'Extend Plan' : ($hasActivePlan ? 'Switch to this Plan' : 'Select Plan') }}
                </button>
            </div>
        @endforeach
    </div>

    {{-- PLAN RECEIPT LEDGER --}}
    <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden mt-8">
        <div class="px-6 py-5 border-b border-slate-800 bg-black">
            <h3 class="text-lg font-bold text-white">Subscription Ledger</h3>
            <p class="text-sm text-slate-400">Record of plans activated on your account.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Plan Name</th>
                        <th class="px-6 py-4 text-right">Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->purchaseLedger as $entry)
                        <tr class="hover:bg-zinc-900/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-slate-300">{{ \Carbon\Carbon::parse($entry->created_at)->format('M d, Y') }}</div>
                                <div class="text-[11px] text-slate-500">{{ \Carbon\Carbon::parse($entry->created_at)->format('h:i A') }}</div>
                            </td>
                            <td class="px-6 py-4 font-bold text-white">{{ $entry->plan_name }}</td>
                            <td class="px-6 py-4 text-right font-mono text-red-400 font-bold">- KES {{ number_format($entry->amount_paid, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-12 text-center text-slate-500">No plan purchases found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- CONFIRMATION MODAL --}}
    @if($this->confirmingPlan)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6 text-center">

                @if(count($pendingCheckouts) > 0)
                    <svg class="w-12 h-12 animate-spin text-amber-500 mx-auto mb-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <h3 class="text-xl font-bold text-white mb-2">Check Your Phone</h3>
                    <p class="text-sm text-slate-400 mb-6">Enter your M-Pesa PIN. The plan will activate automatically when payment completes.</p>
                @else
                    <h2 class="text-xl font-bold text-white mb-1">Confirm Plan</h2>
                    <p class="text-sm text-slate-400 mb-5">You are selecting <strong class="text-white">{{ $this->confirmingPlan->name }}</strong></p>

                    <div class="bg-black border border-slate-800 rounded-xl p-4 mb-5 space-y-2">
                        <div class="flex justify-between text-sm text-slate-400">
                            <span>Plan Price</span><span class="font-mono text-white">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-slate-400">
                            <span>Wallet Balance</span><span class="font-mono text-red-400">KES {{ number_format($this->currentBalance, 0) }}</span>
                        </div>
                        <div class="border-t border-slate-800 pt-2 flex justify-between font-bold text-white mt-2">
                            @if($this->missingAmount <= 0)
                                <span>Wallet Deduction</span><span class="font-mono text-red-500">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                            @else
                                <span>Deficit via M-Pesa</span><span class="font-mono text-amber-500">KES {{ number_format($this->missingAmount, 0) }}</span>
                            @endif
                        </div>
                    </div>

                    @if($this->missingAmount <= 0)
                        <button wire:click="executeWalletPurchase" wire:loading.attr="disabled" class="w-full py-3.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition mb-3">
                            <span wire:loading.remove wire:target="executeWalletPurchase">Confirm & Deduct</span>
                            <span wire:loading wire:target="executeWalletPurchase">Processing...</span>
                        </button>
                    @else
                        <div class="mb-4 text-left">
                            <label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase">M-Pesa Number (Who is paying?)</label>
                            <input type="text" wire:model="phone" class="w-full bg-black border @error('phone') border-red-500 @else border-slate-700 @enderror rounded-xl px-4 py-3 text-slate-200">
                            @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                        <button wire:click="executeMpesaPurchase" wire:loading.attr="disabled" class="w-full py-3.5 rounded-xl bg-[#25D366] hover:bg-[#20b858] text-black font-bold transition mb-3">
                            <span wire:loading.remove wire:target="executeMpesaPurchase">Top-Up KES {{ number_format($this->missingAmount, 0) }} & Buy</span>
                            <span wire:loading wire:target="executeMpesaPurchase">Pushing to Phone...</span>
                        </button>
                    @endif
                @endif

                <button wire:click="closeModals" class="w-full py-3.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">Cancel</button>
            </div>
        </div>
    @endif

    {{-- RESULT MODAL --}}
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

                <p class="text-sm text-slate-400 mb-6">{{ $modalMessage }}</p>
                <button wire:click="closeModals" class="w-full py-3.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-white font-bold transition">Done</button>
            </div>
        </div>
    @endif
</div>
