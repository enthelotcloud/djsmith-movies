<?php

use Livewire\Component;
use App\Services\MpesaService;
use App\Models\MpesaTransaction;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public $phone;
    public $amount;
    public $isProcessing = false;

    public function mount()
    {
        // Pre-fill with the authenticated user's formatted phone number
        $this->phone = Auth::user()->phone;
    }

    public function initiateTopup(MpesaService $mpesaService)
    {
        $this->validate([
            'phone' => ['required', 'string', 'regex:/^254[0-9]{9}$/'], // Ensure it's 254...
            'amount' => ['required', 'numeric', 'min:1', 'max:300000'],
        ]);

        $this->isProcessing = true;

        $reference = 'UID' . Auth::id() . 'TOPUP';

        // Call our service
        $response = $mpesaService->stkPush($this->phone, $this->amount, $reference);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] == '0') {
            // Success: Safaricom accepted the request, save to database as pending
            MpesaTransaction::create([
                'user_id' => Auth::id(),
                'merchant_request_id' => $response['MerchantRequestID'],
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $this->amount,
                'phone' => $this->phone,
                'status' => 'pending',
            ]);

            session()->flash('message', 'STK Push sent! Please check your phone and enter your M-Pesa PIN.');
            
            // Clear the input
            $this->amount = '';
        } else {
            // Failure: Safaricom rejected the request outright
            $errorMessage = $response['errorMessage'] ?? $response['message'] ?? 'Failed to initiate transaction.';
            session()->flash('error', 'M-Pesa Error: ' . $errorMessage);
        }

        $this->isProcessing = false;
    }
};
?>

<div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-sm max-w-md w-full">
    <div class="flex items-center gap-3 mb-6">
        <div class="w-12 h-12 bg-[#25D366]/20 text-[#25D366] rounded-full flex items-center justify-center border border-[#25D366]/30">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
        </div>
        <div>
            <h2 class="text-xl font-bold text-white">M-Pesa Top Up</h2>
            <p class="text-sm text-slate-400">Instantly add funds to your wallet.</p>
        </div>
    </div>

    {{-- Messages --}}
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

    <form wire:submit="initiateTopup" class="space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-400 mb-1.5">M-Pesa Phone Number</label>
            <input type="text" wire:model="phone" placeholder="2547XXXXXXXX" class="w-full bg-slate-950 border @error('phone') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-3 text-slate-200 focus:border-[#25D366] focus:ring-1 focus:ring-[#25D366] transition">
            @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-400 mb-1.5">Amount (KES)</label>
            <input type="number" wire:model="amount" placeholder="e.g. 500" class="w-full bg-slate-950 border @error('amount') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-3 text-emerald-400 font-mono text-lg focus:border-[#25D366] focus:ring-1 focus:ring-[#25D366] transition">
            @error('amount') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" class="w-full py-3 rounded-lg bg-[#25D366] hover:bg-[#20b858] text-slate-900 font-bold text-lg transition shadow-sm flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
    
            <span wire:loading.remove wire:target="initiateTopup">Pay with M-Pesa</span>
            
            <span wire:loading wire:target="initiateTopup" class="flex items-center gap-2">
                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Processing...
            </span>
        </button>
    </form>
</div>