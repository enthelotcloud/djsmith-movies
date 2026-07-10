<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MpesaService;

new class extends Component {
    public $confirmingPlanId = null;
    public $phone;
    public $isProcessing = false;

    // Replaced the array with a single active state
    public $activeCheckoutId = null;
    public $isWaitingForMpesa = false;

    public $showResultModal = false;
    public $modalType = 'success';
    public $modalMessage = '';

    public function mount()
    {
        $this->phone = Auth::user()->phone;

        // If they refresh the page, check if they were in the middle of a transaction
        $pendingTx = DB::table('mpesa_transactions')
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->whereNotNull('target_plan_id')
            ->first();

        if ($pendingTx) {
            $this->confirmingPlanId = $pendingTx->target_plan_id;
            $this->activeCheckoutId = $pendingTx->checkout_request_id;
            $this->isWaitingForMpesa = true;
        }
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
        $this->isWaitingForMpesa = false; // Reset state if they click a new plan
    }

    /**
     * 🔥 UPDATED: Inline wallet purchase to avoid 419 error
     */
    public function executeWalletPurchase()
    {
        if ($this->isProcessing) return;

        $planId = $this->confirmingPlanId;
        $userId = Auth::id();

        $this->isProcessing = true;

        try {
            DB::beginTransaction();

            // Lock user row
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) throw new \Exception("User not found.");

            // Get plan
            $plan = DB::table('plans')
                ->where('id', $planId)
                ->where('is_active', true)
                ->first();

            if (!$plan) throw new \Exception("Plan not found or inactive.");

            // Check balance
            $balance = (float) $user->wallet_balance;
            $price = (float) $plan->price;

            if ($balance < $price) {
                throw new \Exception("Insufficient wallet balance. You have KES " . number_format($balance, 0) . " but need KES " . number_format($price, 0));
            }

            // Deduct wallet
            $newBalance = $balance - $price;
            DB::table('users')
                ->where('id', $userId)
                ->update(['wallet_balance' => $newBalance]);

            // Record wallet transaction
            DB::table('wallet_transactions')->insert([
                'user_id' => $userId,
                'type' => 'debit',
                'amount' => $price,
                'balance_before' => $balance,
                'balance_after' => $newBalance,
                'reference_type' => 'plan_purchase',
                'reference_id' => (string) $planId,
                'description' => 'Payment for ' . $plan->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Check existing subscription
            $existingSubscription = DB::table('subscriptions')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            $remainingMinutes = 0;
            $currentExpiry = null;

            if ($existingSubscription) {
                $currentExpiry = \Carbon\Carbon::parse($existingSubscription->expires_at);
                $remainingMinutes = max(0, (int) now()->diffInMinutes($currentExpiry, false));

                // Deactivate old subscriptions
                DB::table('subscriptions')
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive', 'updated_at' => now()]);
            }

            // Calculate new expiry
            if ($existingSubscription && $existingSubscription->plan_id == $planId && $currentExpiry) {
                // Extending same plan - add to existing expiry
                $newExpiry = $currentExpiry->copy()->addMinutes((int) $plan->duration_minutes);
            } else {
                // New plan or switching - carry over remaining time
                $newExpiry = now()->addMinutes((int) $plan->duration_minutes + $remainingMinutes);
            }

            // Create new subscription
            DB::table('subscriptions')->insert([
                'user_id' => $userId,
                'plan_id' => $planId,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => $newExpiry,
                'auto_renew' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Record purchase
            DB::table('plan_purchases')->insert([
                'user_id' => $userId,
                'plan_name' => $plan->name,
                'amount_paid' => $price,
                'duration_minutes' => $plan->duration_minutes,
                'expires_at' => $newExpiry,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // Success message
            $this->modalType = 'success';
            $this->modalMessage = 'Success! ' . $plan->name . ' activated. KES ' . number_format($price, 0) . ' deducted from wallet. Expires: ' . $newExpiry->format('M d, Y h:i A');
            $this->showResultModal = true;
            $this->confirmingPlanId = null;
            unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);

            Log::info('Wallet purchase completed', [
                'user_id' => $userId,
                'plan' => $plan->name,
                'amount' => $price
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Wallet purchase failed', [
                'user_id' => $userId,
                'plan_id' => $planId,
                'error' => $e->getMessage()
            ]);

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

            // Switch UI to the countdown mode
            $this->activeCheckoutId = $response['CheckoutRequestID'];
            $this->isWaitingForMpesa = true;

        } else {
            $this->modalType = 'error';
            $this->modalMessage = 'M-Pesa push failed. Please check your number and try again.';
            $this->showResultModal = true;
            $this->confirmingPlanId = null;
        }
        $this->isProcessing = false;
    }

    // This is ONLY called once the 20-second timer finishes, or if the user clicks "Check Status" manually
    public function verifyPaymentStatus()
    {
        if (!$this->activeCheckoutId) return;

        $tx = DB::table('mpesa_transactions')
            ->where('checkout_request_id', $this->activeCheckoutId)
            ->first();

        if (!$tx) return;

        if ($tx->status === 'completed') {
            $this->modalType = 'success';
            $this->modalMessage = 'Payment received! Your plan is now active.';
            $this->showResultModal = true;
            $this->resetCheckoutState();
        } elseif ($tx->status === 'failed') {
            $this->modalType = 'error';
            $this->modalMessage = 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled by user');
            $this->showResultModal = true;
            $this->resetCheckoutState();
        } else {
            // Still pending after 20 seconds.
            // We don't close the modal, we just show a subtle error and let them manually check again.
            $this->dispatch('notify-toast', type: 'warning', message: 'Still waiting for Safaricom. Did you enter your PIN?');
        }

        unset($this->currentBalance, $this->activeSubscription, $this->purchaseLedger);
    }

    public function resetCheckoutState()
    {
        $this->confirmingPlanId = null;
        $this->isWaitingForMpesa = false;
        $this->activeCheckoutId = null;
    }

    public function closeModals()
    {
        $this->resetCheckoutState();
        $this->showResultModal = false;
    }
};
?>

{{-- 🚨 REMOVED wire:poll entirely. This container is now static. --}}
<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="alert($event.detail.message)">

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

    {{-- CONFIRMATION & COUNTDOWN MODAL --}}
    @if($this->confirmingPlan)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6 text-center">

                @if($this->isWaitingForMpesa)
                    {{-- 🚨 THE ALPINE.JS COUNTDOWN COMPONENT 🚨 --}}
                    <div x-data="{
                            countdown: 20,
                            timer: null,
                            startTimer() {
                                this.timer = setInterval(() => {
                                    if (this.countdown > 0) {
                                        this.countdown--;
                                    } else {
                                        clearInterval(this.timer);
                                        $wire.verifyPaymentStatus(); // Server ping ONLY at 0
                                    }
                                }, 1000);
                            }
                         }"
                         x-init="startTimer()"
                         class="space-y-4">

                        <div class="relative w-20 h-20 mx-auto">
                            <svg class="w-full h-full text-slate-800" viewBox="0 0 36 36"><path class="stroke-current" stroke-width="3" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/></svg>
                            <svg class="w-full h-full text-amber-500 absolute top-0 left-0 drop-shadow-[0_0_8px_rgba(245,158,11,0.5)]" viewBox="0 0 36 36" style="transform: rotate(-90deg);">
                                <path class="stroke-current" stroke-dasharray="100, 100" :stroke-dashoffset="100 - ((countdown / 20) * 100)" stroke-width="3" stroke-linecap="round" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" style="transition: stroke-dashoffset 1s linear;"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center text-xl font-bold text-white font-mono" x-text="countdown"></div>
                        </div>

                        <h3 class="text-xl font-bold text-white">Check Your Phone</h3>
                        <p class="text-sm text-slate-400">Please enter your M-Pesa PIN. We are waiting for Safaricom to process the payment.</p>

                        {{-- Manual verify button appears when countdown hits 0 --}}
                        <div x-show="countdown === 0" x-transition.opacity class="pt-4 border-t border-slate-800">
                            <button wire:click="verifyPaymentStatus" wire:loading.attr="disabled" class="w-full py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-amber-500 font-bold transition flex justify-center items-center gap-2">
                                <span wire:loading.remove wire:target="verifyPaymentStatus">Verify Payment Now</span>
                                <span wire:loading wire:target="verifyPaymentStatus">Checking Database...</span>
                            </button>
                        </div>
                    </div>
                @else
                    {{-- NORMAL CHECKOUT VIEW --}}
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

                {{-- The universal cancel button --}}
                <button wire:click="closeModals" class="w-full mt-3 py-3.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-800 text-slate-300 font-medium transition">
                    Cancel
                </button>
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
