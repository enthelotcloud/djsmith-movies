<?php

use Livewire\Component;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {
    
    // We fetch active plans to show the user
    public function getPlansProperty()
    {
        return Plan::where('is_active', true)->orderBy('price')->get();
    }

    public function purchasePlan($planId)
    {
        $user = Auth::user();
        $plan = Plan::findOrFail($planId);

        // 1. Check if they have enough money
        if ($user->wallet_balance < $plan->price) {
            session()->flash('error', 'Insufficient funds. Please top up your wallet.');
            return;
        }

        // 2. Deduct the money from the wallet safely
        $user->decrement('wallet_balance', $plan->price);

        // 3. Deactivate old subscriptions so they don't overlap
        Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        // 4. Create the new Active Subscription
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes($plan->duration_minutes),
            'auto_renew' => true, // You can toggle this based on a checkbox later
        ]);

        session()->flash('success', 'You are now subscribed to ' . $plan->name . '! Get the popcorn ready.');
        
        // Refresh the user's auth state so gates work instantly
        $user->refresh();
    }
};
?>

<div class="max-w-5xl mx-auto space-y-8">

    <div class="text-center mb-10">
        <h1 class="text-3xl font-black text-white">Choose Your Plan</h1>
        <p class="text-slate-400 mt-2">Deducted instantly from your KES {{ number_format(auth()->user()->wallet_balance, 2) }} wallet balance.</p>
    </div>

    @if(session()->has('error'))
        <div class="bg-red-900/30 border border-red-500/30 text-red-400 p-4 rounded-xl text-center font-medium">
            {{ session('error') }} <a href="{{ route('client.wallet-topup') }}" class="underline text-white">Top up here.</a>
        </div>
    @endif

    @if(session()->has('success'))
        <div class="bg-emerald-900/30 border border-emerald-500/30 text-emerald-400 p-4 rounded-xl text-center font-medium">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @forelse($this->plans as $plan)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 flex flex-col relative overflow-hidden transition hover:border-slate-600">
                
                @if($plan->can_download)
                    <div class="absolute top-0 right-0 bg-blue-600 text-xs font-bold px-3 py-1 rounded-bl-lg text-white">
                        Downloads Included
                    </div>
                @endif

                <h3 class="text-xl font-bold text-white mb-2">{{ $plan->name }}</h3>
                <div class="text-3xl font-black text-emerald-400 mb-6">
                    KES {{ number_format($plan->price, 0) }}
                </div>

                <ul class="space-y-3 mb-8 flex-1 text-sm text-slate-300">
                    <li class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Full HD Streaming
                    </li>
                    <li class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Access for {{ $plan->duration_minutes < 60 ? $plan->duration_minutes . ' Mins' : floor($plan->duration_minutes / 60) . ' Hours' }}
                    </li>
                    <li class="flex items-center gap-2 {{ $plan->can_download ? 'text-slate-300' : 'text-slate-600 line-through' }}">
                        <svg class="w-5 h-5 {{ $plan->can_download ? 'text-emerald-500' : 'text-slate-700' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Offline Downloads
                    </li>
                </ul>

                <button 
                    wire:click="purchasePlan({{ $plan->id }})" 
                    wire:loading.attr="disabled"
                    class="w-full py-3 rounded-lg bg-white text-black hover:bg-slate-200 font-bold transition shadow-sm"
                >
                    <span wire:loading.remove wire:target="purchasePlan({{ $plan->id }})">Buy Now</span>
                    <span wire:loading wire:target="purchasePlan({{ $plan->id }})">Processing...</span>
                </button>
            </div>
        @empty
            <div class="col-span-3 text-center text-slate-500 py-12">
                No plans available right now. Check back later!
            </div>
        @endforelse
    </div>
</div>