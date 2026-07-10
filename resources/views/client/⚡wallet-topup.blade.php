<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Services\MpesaService;
use App\Models\MpesaTransaction;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public $phone;
    public $amount;
    public $isProcessing = false;

    // Track pending STK Push IDs so we know what to poll for
    public $pendingCheckouts = [];

    public function mount()
    {
        $this->phone = Auth::user()->phone;

        // If they refresh the page while a transaction is pending, keep tracking it
        $this->pendingCheckouts = MpesaTransaction::where('user_id', Auth::id())
            ->where('status', 'pending')
            ->pluck('checkout_request_id')
            ->toArray();
    }

    #[Computed]
    public function currentBalance()
    {
        // Fetch fresh balance directly from DB so it updates immediately when webhook fires
        return Auth::user()->fresh()->wallet_balance;
    }

    #[Computed]
    public function transactions()
    {
        // Grab the 5 most recent transactions for the history table
        return MpesaTransaction::where('user_id', Auth::id())
            ->latest()
            ->take(5)
            ->get();
    }

    public function initiateTopup(MpesaService $mpesaService)
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/'],
            'amount' => ['required', 'numeric', 'min:1', 'max:300000'],
        ]);

        $this->isProcessing = true;
        $reference = 'UID' . Auth::id() . 'TOPUP';

        $response = $mpesaService->stkPush($this->phone, $this->amount, $reference);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            MpesaTransaction::create([
                'user_id' => Auth::id(),
                'merchant_request_id' => $response['MerchantRequestID'],
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $this->amount,
                'phone' => $this->phone,
                'status' => 'pending',
            ]);

            // Add this new transaction to our tracking array so the poller starts watching it
            $this->pendingCheckouts[] = $response['CheckoutRequestID'];

            session()->flash('message', 'STK Push sent! Please check your phone to enter your PIN.');
            $this->amount = '';
        } else {
            $errorMessage = $response['errorMessage'] ?? $response['message'] ?? 'Failed to initiate transaction.';
            session()->flash('error', 'M-Pesa Error: ' . $errorMessage);
        }

        $this->isProcessing = false;
    }

    /**
     * This method runs automatically every 3 seconds via wire:poll
     */
    public function checkPendingStatus()
    {
        if (empty($this->pendingCheckouts)) {
            return;
        }

        $updatedTransactions = MpesaTransaction::whereIn('checkout_request_id', $this->pendingCheckouts)
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        foreach ($updatedTransactions as $tx) {
            if ($tx->status === 'completed') {
                $this->dispatch('notify-toast', type: 'success', message: 'KES ' . number_format($tx->amount) . ' added to your wallet!');
            } else {
                $this->dispatch('notify-toast', type: 'danger', message: 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled'));
            }

            $this->pendingCheckouts = array_diff($this->pendingCheckouts, [$tx->checkout_request_id]);
        }
    }
};
?>

{{-- We wrap everything in Alpine.js to listen for our custom Livewire event and trigger Flux toasts --}}
<div
    x-data
    @notify-toast.window="window.Flux ? Flux.toast({ text: $event.detail.message, variant: $event.detail.type }) : alert($event.detail.message)"
    wire:poll.3s="checkPendingStatus"
    class="max-w-6xl mx-auto space-y-8 relative"
>

    {{-- ══════════════════ 1. HEADER & REACTIVE WALLET ══════════════════ --}}
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Wallet Top-Up</h1>
            <p class="text-slate-400 mt-1">Add funds securely to your account via M-Pesa.</p>
        </div>
        <div class="flex items-center gap-3 bg-black px-5 py-3 rounded-xl border border-red-900/50">
            <div class="w-10 h-10 rounded-full bg-red-950/50 flex items-center justify-center text-red-500">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Current Balance</div>
                <div class="text-xl font-black text-red-500 font-mono">KES {{ number_format($this->currentBalance, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- PENDING TRANSACTION BANNER --}}
    @if(count($pendingCheckouts) > 0)
        <div class="bg-amber-950/30 border border-amber-900/50 rounded-2xl p-5 shadow-lg flex items-center justify-between">
            <div class="flex items-center gap-4">
                 <div class="w-12 h-12 bg-amber-950 text-amber-500 rounded-full flex items-center justify-center border border-amber-900/50 animate-pulse">
                    <svg class="w-6 h-6 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                 </div>
                 <div>
                     <h3 class="text-sm font-bold text-amber-500 uppercase tracking-widest">Awaiting M-Pesa Payment</h3>
                     <p class="text-sm text-amber-500/70 mt-0.5">Please check your phone and enter your PIN to complete the transaction.</p>
                 </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ══════════════════ 2. TOP UP FORM ══════════════════ --}}
        <div class="lg:col-span-1 bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden h-fit">
            <div class="px-6 py-5 border-b border-slate-800 bg-black">
                <h3 class="text-lg font-bold text-white">Add Funds</h3>
            </div>

            <div class="p-6">
                @if(session()->has('message'))
                    <div class="mb-5 bg-emerald-950/30 border border-emerald-900/50 text-emerald-500 px-4 py-3 rounded-xl text-sm font-medium">
                        {{ session('message') }}
                    </div>
                @endif

                @if(session()->has('error'))
                    <div class="mb-5 bg-red-950/30 border border-red-900/50 text-red-500 px-4 py-3 rounded-xl text-sm font-medium">
                        {{ session('error') }}
                    </div>
                @endif

                <form wire:submit="initiateTopup" class="space-y-5">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Phone Number</label>
                        <input type="text" wire:model="phone" class="w-full bg-black border @error('phone') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-xl px-4 py-3 text-slate-200 focus:border-red-500 focus:ring-1 focus:ring-red-500 transition">
                        @error('phone') <span class="text-xs text-red-500 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-2 uppercase tracking-wider">Amount (KES)</label>
                        <input type="number" wire:model="amount" class="w-full bg-black border @error('amount') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-xl px-4 py-3 text-red-500 font-mono text-xl focus:border-red-500 focus:ring-1 focus:ring-red-500 transition">
                        @error('amount') <span class="text-xs text-red-500 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" wire:loading.attr="disabled" class="w-full mt-2 py-3.5 rounded-xl bg-[#25D366] hover:bg-[#20b858] text-black font-bold transition shadow-sm flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span wire:loading.remove wire:target="initiateTopup">Pay with M-Pesa</span>
                        <span wire:loading wire:target="initiateTopup" class="flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Pushing...
                        </span>
                    </button>
                </form>
            </div>
        </div>

        {{-- ══════════════════ 3. TRANSACTION HISTORY TABLE ══════════════════ --}}
        <div class="lg:col-span-2 bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-800 bg-black">
                <h3 class="text-lg font-bold text-white">Recent Transactions</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Receipt No.</th>
                            <th class="px-6 py-4 text-right">Amount</th>
                            <th class="px-6 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->transactions as $tx)
                            <tr class="hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4">
                                   <div class="text-slate-300">{{ $tx->created_at ? \Carbon\Carbon::parse($tx->created_at)->format('M d, Y') : 'Unknown Date' }}</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ $tx->created_at ? \Carbon\Carbon::parse($tx->created_at)->format('h:i A') : '--' }}</div>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-slate-200 font-bold">
                                    {{ $tx->mpesa_receipt_number ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-white font-bold">
                                    KES {{ number_format($tx->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if($tx->status === 'completed')
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-emerald-950/50 text-emerald-500 border border-emerald-900/50 text-[11px] font-bold uppercase tracking-wide">
                                            Completed
                                        </span>
                                    @elseif($tx->status === 'pending')
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-950/50 text-amber-500 border border-amber-900/50 text-[11px] font-bold uppercase tracking-wide">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                            Pending
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-red-950/50 text-red-500 border border-red-900/50 text-[11px] font-bold uppercase tracking-wide" title="{{ $tx->result_desc }}">
                                            Failed
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <svg class="w-8 h-8 mb-2 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        No transactions found. Your top-up history will appear here.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
