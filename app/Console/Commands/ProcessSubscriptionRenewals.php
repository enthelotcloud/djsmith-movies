<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\WalletService;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionRenewals extends Command
{
    protected $signature = 'subscriptions:renew';
    protected $description = 'Process automatic subscription renewals from user wallets';

    public function handle(WalletService $walletService)
    {
        $expiredSubs = DB::table('subscriptions')
            ->where('status', 'active')
            ->where('auto_renew', 1)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredSubs as $sub) {
            try {
                // Try to purchase using wallet balance. If it throws an exception (insufficient funds), it drops to the catch block.
                $walletService->purchasePlan($sub->user_id, $sub->plan_id, true);
                $this->info("Successfully renewed subscription for User ID {$sub->user_id}");
            } catch (\Exception $e) {
                // Wallet empty. Suspend the subscription.
                DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'updated_at' => now()]);
                Log::info("Failed to renew sub for User ID {$sub->user_id}. Reason: " . $e->getMessage());
            }
        }
    }
}
