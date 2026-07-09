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
        // If there's nothing pending, do nothing (Zero database queries = highly efficient)
        if (empty($this->pendingCheckouts)) {
            return;
        }

        // Check if any tracked transactions have been marked 'completed' or 'failed' by the webhook
        $updatedTransactions = MpesaTransaction::whereIn('checkout_request_id', $this->pendingCheckouts)
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        foreach ($updatedTransactions as $tx) {
            if ($tx->status === 'completed') {
                // Fire a browser event to trigger the success toast
                $this->dispatch('notify-toast', type: 'success', message: 'KES ' . number_format($tx->amount) . ' added to your wallet!');
            } else {
                // Fire a browser event to trigger the error toast
                $this->dispatch('notify-toast', type: 'danger', message: 'Payment failed: ' . ($tx->result_desc ?? 'Cancelled'));
            }

            // Remove it from the tracking array so we don't alert the user twice
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
    class="max-w-4xl mx-auto space-y-6"
>

    {{-- 1. LIVE WALLET BALANCE CARD --}}
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-emerald-900/30 text-emerald-500 rounded-full flex items-center justify-center border border-emerald-500/30">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h2 class="text-sm font-medium text-slate-400 uppercase tracking-wider">Current Wallet Balance</h2>
                {{-- This dynamically updates when the poller catches the completed transaction --}}
                <div class="text-3xl font-black text-white mt-1">KES {{ number_format($this->currentBalance, 2) }}</div>
            </div>
        </div>
        
        @if(count($pendingCheckouts) > 0)
            <div class="flex items-center gap-2 bg-amber-900/20 border border-amber-500/30 text-amber-500 px-4 py-2 rounded-full text-sm font-medium animate-pulse">
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Waiting for M-Pesa reply...
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {{-- 2. TOP UP FORM --}}
        <div class="lg:col-span-1 bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-sm h-fit">
            <h3 class="text-lg font-bold text-white mb-4">Add Funds</h3>
            
            @if(session()->has('message'))
                <div class="mb-4 bg-emerald-900/30 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl text-sm font-medium">
                    {{ session('message') }}
                </div>
            @endif

            @if(session()->has('error'))
                <div class="mb-4 bg-red-900/30 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm font-medium">
                    {{ session('error') }}
                </div>
            @endif

            <form wire:submit="initiateTopup" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Phone Number</label>
                    <input type="text" wire:model="phone" class="w-full bg-slate-950 border @error('phone') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-[#25D366] focus:ring-1 focus:ring-[#25D366] transition">
                    @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Amount (KES)</label>
                    <input type="number" wire:model="amount" class="w-full bg-slate-950 border @error('amount') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-emerald-400 font-mono text-lg focus:border-[#25D366] focus:ring-1 focus:ring-[#25D366] transition">
                    @error('amount') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" class="w-full mt-2 py-3 rounded-lg bg-[#25D366] hover:bg-[#20b858] text-slate-900 font-bold transition shadow-sm flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="initiateTopup">Pay with M-Pesa</span>
                    <span wire:loading wire:target="initiateTopup" class="flex items-center gap-2">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Pushing...
                    </span>
                </button>
            </form>
        </div>

        {{-- 3. TRANSACTION HISTORY TABLE --}}
        <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-800">
                <h3 class="text-lg font-bold text-white">Recent Transactions</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-slate-950 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Receipt No.</th>
                            <th class="px-6 py-4 text-right">Amount</th>
                            <th class="px-6 py-4 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->transactions as $tx)
                            <tr class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-slate-300">{{ $tx->created_at->format('d M, Y') }}</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ $tx->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-slate-300">
                                    {{ $tx->mpesa_receipt_number ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-right font-mono text-slate-300">
                                    KES {{ number_format($tx->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if($tx->status === 'completed')
                                        <span class="px-2.5 py-1 bg-emerald-900/30 text-emerald-400 border border-emerald-700/50 rounded-md text-[11px] font-medium">Completed</span>
                                    @elseif($tx->status === 'pending')
                                        <span class="px-2.5 py-1 bg-amber-900/30 text-amber-400 border border-amber-700/50 rounded-md text-[11px] font-medium flex items-center justify-end gap-1 w-max ml-auto">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span> Pending
                                        </span>
                                    @else
                                        <span class="px-2.5 py-1 bg-red-900/30 text-red-400 border border-red-700/50 rounded-md text-[11px] font-medium" title="{{ $tx->result_desc }}">Failed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                    No transactions found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>