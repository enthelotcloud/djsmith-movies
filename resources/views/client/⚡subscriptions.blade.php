<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\MpesaService; // Assuming you have this handling the actual API call

new class extends Component {
    // We only store the ID in public state to prevent serialization errors or tampering
    public $confirmingPlanId = null;

    // Payment specific state
    public $phone;
    public $missingAmount = 0;
    public $isProcessing = false;
    public $pendingCheckouts = [];

    // Result modal state
    public $showResultModal = false;
    public $modalType = 'success';
    public $modalMessage = '';

    public function mount()
    {
        $this->phone = Auth::user()->phone;

        // Resume tracking any pending automatic plan purchases if user refreshed
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
        return \App\Models\Subscription::with('plan') // Assuming you have relationships set up on the model
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

        // Calculate deficit. If wallet has 200 and plan is 500, missing is 300.
        $this->missingAmount = max(0, $plan->price - $currentWallet);
    }

    public function executeWalletPurchase()
    {
        $this->isProcessing = true;
        $userId = Auth::id();
        $plan = $this->confirmingPlan;

        try {
            DB::transaction(function () use ($userId, $plan) {
                // Lock the user row to prevent double spending race conditions
                $currentWallet = DB::table('users')->where('id', $userId)->lockForUpdate()->value('wallet_balance');

                if ($currentWallet < $plan->price) {
                    throw new \Exception('Insufficient wallet balance. Please refresh.');
                }

                // 1. Deduct Wallet
                DB::table('users')->where('id', $userId)->decrement('wallet_balance', $plan->price);

                // 2. Revoke Old Access
                DB::table('subscriptions')
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                // 3. Grant New Access
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

                // 4. Write to the Ledger
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
        // Bust the computed cache so the UI updates instantly
        unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
    }

    public function executeMpesaPurchase(MpesaService $mpesaService)
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/'],
        ]);

        $this->isProcessing = true;
        // Unique reference for STK
        $reference = 'PLN' . $this->confirmingPlanId . 'UID' . Auth::id();

        // Push only the MISSING amount
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
                $this->confirmingPlanId = null; // Close the confirmation modal
            } else {
                $this->modalType = 'error';
                $this->modalMessage = 'Payment failed or was cancelled: ' . ($tx->result_desc ?? 'Unknown error');
                $this->showResultModal = true;
            }

            // Stop polling this specific transaction
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

{{-- We use wire:poll.3s to check if the webhook has updated our database --}}
<div class="max-w-6xl mx-auto space-y-8" wire:poll.3s="checkPendingStatus">

    {{-- 1. HEADER & WALLET BALANCE --}}
    <div class="flex flex-col md:flex-row justify-between items-center bg-zinc-900 border border-zinc-800 rounded-2xl p-6">
        <div>
            <h1 class="text-2xl font-bold text-white">VOD Subscriptions</h1>
            <p class="text-zinc-400 text-sm">Choose a plan or top up to keep watching.</p>
        </div>
        <div class="mt-4 md:mt-0 bg-black border border-zinc-800 px-5 py-3 rounded-xl flex items-center gap-4">
            <div class="w-10 h-10 rounded-full bg-indigo-500/20 text-indigo-400 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </div>
            <div>
                <p class="text-xs text-zinc-500 font-semibold uppercase tracking-wider">Wallet Balance</p>
                <p class="text-xl font-bold text-white font-mono">KES {{ number_format($this->currentBalance, 2) }}</p>
            </div>
        </div>
    </div>

    {{-- 2. ACTIVE PLAN INDICATOR --}}
    @if($this->activeSubscription && $this->activeSubscription->plan)
        <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-2xl p-6 flex justify-between items-center">
            <div>
                <p class="text-sm font-bold text-indigo-400 uppercase tracking-widest mb-1">Active Plan</p>
                <p class="text-2xl font-black text-white">{{ $this->activeSubscription->plan->name }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm text-zinc-400 mb-1">Expires On</p>
                <p class="text-lg font-bold text-white">{{ \Carbon\Carbon::parse($this->activeSubscription->expires_at)->format('M d, Y h:i A') }}</p>
            </div>
        </div>
    @endif

    {{-- 3. PLANS GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach($this->plans as $plan)
            <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-6 flex flex-col justify-between">
                <div>
                    <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                    <p class="text-3xl font-black text-white mb-4">KES {{ number_format($plan->price, 0) }}</p>
                    <ul class="text-sm text-zinc-400 space-y-2 mb-6">
                        <li>Access for {{ $plan->duration_minutes }} minutes</li>
                        @if($plan->can_download) <li>Offline Downloads Included</li> @endif
                    </ul>
                </div>

                <flux:button
                    wire:click="confirmPurchase({{ $plan->id }})"
                    variant="primary"
                    class="w-full justify-center">
                    Select Plan
                </flux:button>
            </div>
        @endforeach
    </div>

    {{-- 4. THE TRANSPARENT LEDGER (Purchases ONLY, not top-ups) --}}
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden mt-8">
        <div class="p-6 border-b border-zinc-800">
            <h3 class="text-lg font-bold text-white">Subscription Ledger</h3>
            <p class="text-sm text-zinc-400">Record of plans paid for using your wallet balance.</p>
        </div>

        <flux:table>
            <flux:columns>
                <flux:column>Date</flux:column>
                <flux:column>Plan</flux:column>
                <flux:column>Duration</flux:column>
                <flux:column align="right">Amount Deducted</flux:column>
            </flux:columns>

            <flux:rows>
                @forelse($this->purchaseLedger as $entry)
                    <flux:row>
                        <flux:cell class="text-zinc-300">{{ \Carbon\Carbon::parse($entry->created_at)->format('d M Y, H:i') }}</flux:cell>
                        <flux:cell class="font-medium text-white">{{ $entry->plan_name }}</flux:cell>
                        <flux:cell class="text-zinc-400">{{ $entry->duration_minutes }} mins</flux:cell>
                        <flux:cell align="right" class="font-mono text-red-400">- KES {{ number_format($entry->amount_paid, 2) }}</flux:cell>
                    </flux:row>
                @empty
                    <flux:row>
                        <flux:cell colspan="4" class="text-center text-zinc-500 py-8">No plan purchases found.</flux:cell>
                    </flux:row>
                @endforelse
            </flux:rows>
        </flux:table>
    </div>

    {{-- 5. CONFIRMATION MODAL (Handles Wallet Check & M-Pesa STK) --}}
    <flux:modal wire:model.live="confirmingPlanId" class="max-w-md">
        @if($this->confirmingPlan)
            <div class="space-y-6">
                <div>
                    <h2 class="text-xl font-bold text-white mb-1">Confirm Subscription</h2>
                    <p class="text-sm text-zinc-400">You selected <strong class="text-white">{{ $this->confirmingPlan->name }}</strong></p>
                </div>

                @if(count($pendingCheckouts) > 0)
                    {{-- AWAITING MPESA PIN --}}
                    <div class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-6 text-center">
                        <div class="w-12 h-12 rounded-full border-2 border-amber-500 border-t-transparent animate-spin mx-auto mb-4"></div>
                        <h3 class="text-lg font-bold text-amber-500 mb-2">Check Your Phone</h3>
                        <p class="text-sm text-amber-400/80">Please enter your M-Pesa PIN. The plan will activate automatically once the payment clears.</p>
                    </div>
                @else
                    {{-- RECEIPT BREAKDOWN --}}
                    <div class="bg-black border border-zinc-800 rounded-xl p-4 space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-400">Plan Cost</span>
                            <span class="text-white font-mono">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-400">Wallet Balance</span>
                            <span class="text-indigo-400 font-mono">KES {{ number_format($this->currentBalance, 0) }}</span>
                        </div>
                        <div class="border-t border-zinc-800 pt-3 flex justify-between font-bold">
                            @if($missingAmount <= 0)
                                <span class="text-white">Amount to Deduct</span>
                                <span class="text-red-400 font-mono">KES {{ number_format($this->confirmingPlan->price, 0) }}</span>
                            @else
                                <span class="text-white">Deficit to Pay via M-Pesa</span>
                                <span class="text-amber-400 font-mono">KES {{ number_format($missingAmount, 0) }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- ACTIONS --}}
                    @if($missingAmount <= 0)
                        <flux:button wire:click="executeWalletPurchase" variant="primary" class="w-full" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="executeWalletPurchase">Confirm & Deduct from Wallet</span>
                            <span wire:loading wire:target="executeWalletPurchase">Processing...</span>
                        </flux:button>
                    @else
                        <div class="space-y-4">
                            <flux:input
                                wire:model="phone"
                                label="M-Pesa Number (Who is paying?)"
                                placeholder="254700000000" />

                            <flux:button wire:click="executeMpesaPurchase" variant="primary" class="w-full bg-[#25D366] hover:bg-[#20b858] text-black border-none" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="executeMpesaPurchase">Pay Deficit (KES {{ number_format($missingAmount, 0) }})</span>
                                <span wire:loading wire:target="executeMpesaPurchase">Pushing to Phone...</span>
                            </flux:button>
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </flux:modal>

    {{-- 6. RESULT MODAL (Success/Error Alerts) --}}
    <flux:modal wire:model.live="showResultModal" class="max-w-sm text-center space-y-4">
        @if($modalType === 'success')
            <div class="w-16 h-16 bg-emerald-500/20 text-emerald-500 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white">Success!</h2>
        @else
            <div class="w-16 h-16 bg-red-500/20 text-red-500 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white">Notice</h2>
        @endif

        <p class="text-zinc-400">{{ $modalMessage }}</p>

        <div class="pt-4">
            <flux:button wire:click="closeModals" variant="filled" class="w-full justify-center">Done</flux:button>
        </div>
    </flux:modal>
</div>
