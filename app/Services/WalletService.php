<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class WalletService
{
    public function creditWallet($userId, $amount, $referenceType, $referenceId, $description)
    {
        return DB::transaction(function () use ($userId, $amount, $referenceType, $referenceId, $description) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            $balanceBefore = $user->wallet_balance;
            $balanceAfter = $balanceBefore + $amount;

            DB::table('users')->where('id', $userId)->update(['wallet_balance' => $balanceAfter]);

            DB::table('wallet_transactions')->insert([
                'user_id' => $userId,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $balanceAfter;
        });
    }

    public function purchasePlan($userId, $planId, $isAutoRenewal = false)
    {
        return DB::transaction(function () use ($userId, $planId, $isAutoRenewal) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $plan = DB::table('plans')->where('id', $planId)->first();

            if (!$plan) throw new Exception("Plan not found.");
            if ($user->wallet_balance < $plan->price) throw new Exception("Insufficient wallet balance.");

            $balanceBefore = $user->wallet_balance;
            $balanceAfter = $balanceBefore - $plan->price;

            // 1. Deduct from wallet
            DB::table('users')->where('id', $userId)->update(['wallet_balance' => $balanceAfter]);

            // 2. Write to immutable ledger
            $referenceType = $isAutoRenewal ? 'auto_renewal' : 'plan_purchase';
            DB::table('wallet_transactions')->insert([
                'user_id' => $userId,
                'type' => 'debit',
                'amount' => $plan->price,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $plan->id,
                'description' => "Payment for " . $plan->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Write subscription receipt
            DB::table('plan_purchases')->insert([
                'user_id' => $userId,
                'plan_name' => $plan->name,
                'amount_paid' => $plan->price,
                'duration_minutes' => $plan->duration_minutes,
                'expires_at' => now()->addMinutes($plan->duration_minutes),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Revoke old access & Grant new
            DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')->update(['status' => 'inactive']);

            DB::table('subscriptions')->insert([
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addMinutes($plan->duration_minutes),
                'auto_renew' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }
}
