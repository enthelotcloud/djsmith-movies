<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class WalletService
{
    /**
     * Credit wallet with amount
     */
    public function creditWallet($userId, $amount, $referenceType, $referenceId, $description)
    {
        try {
            return DB::transaction(function () use ($userId, $amount, $referenceType, $referenceId, $description) {
                $user = DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$user) {
                    throw new Exception("User not found.");
                }

                $balanceBefore = (float) $user->wallet_balance;
                $balanceAfter = $balanceBefore + (float) $amount;

                DB::table('users')
                    ->where('id', $userId)
                    ->update(['wallet_balance' => $balanceAfter]);

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

                Log::info('Wallet credited', [
                    'user_id' => $userId,
                    'amount' => $amount,
                ]);

                return $balanceAfter;
            });
        } catch (Exception $e) {
            Log::error('Wallet credit failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Debit wallet for specific amount
     */
    public function debitWallet($userId, $amount, $referenceType, $referenceId, $description)
    {
        try {
            return DB::transaction(function () use ($userId, $amount, $referenceType, $referenceId, $description) {
                $user = DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$user) {
                    throw new Exception("User not found.");
                }

                $balanceBefore = (float) $user->wallet_balance;

                if ($balanceBefore < (float) $amount) {
                    throw new Exception("Insufficient balance. Have: {$balanceBefore}, Need: {$amount}");
                }

                $balanceAfter = $balanceBefore - (float) $amount;

                DB::table('users')
                    ->where('id', $userId)
                    ->update(['wallet_balance' => $balanceAfter]);

                DB::table('wallet_transactions')->insert([
                    'user_id' => $userId,
                    'type' => 'debit',
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('Wallet debited', [
                    'user_id' => $userId,
                    'amount' => $amount,
                ]);

                return $balanceAfter;
            });
        } catch (Exception $e) {
            Log::error('Wallet debit failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Purchase or extend a plan
     */
    public function purchasePlan($userId, $planId, $isAutoRenewal = false)
    {
        try {
            return DB::transaction(function () use ($userId, $planId, $isAutoRenewal) {
                // 1. Fetch user with lock
                $user = DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$user) {
                    throw new Exception("User not found.");
                }

                // 2. Fetch plan
                $plan = DB::table('plans')
                    ->where('id', $planId)
                    ->where('is_active', true)
                    ->first();

                if (!$plan) {
                    throw new Exception("Plan not found or inactive.");
                }

                // 3. Check balance
                $walletBalance = (float) $user->wallet_balance;
                $planPrice = (float) $plan->price;

                if ($walletBalance < $planPrice) {
                    throw new Exception(
                        "Insufficient wallet balance. You have KES " .
                        number_format($walletBalance, 0) .
                        " but need KES " .
                        number_format($planPrice, 0)
                    );
                }

                // 4. Debit wallet
                $this->debitWallet(
                    $userId,
                    $planPrice,
                    $isAutoRenewal ? 'auto_renewal' : 'plan_purchase',
                    $planId,
                    "Payment for " . $plan->name
                );

                // 5. Get any existing active subscription (could be same plan or different)
                $existingSubscription = DB::table('subscriptions')
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->first();

                // Calculate remaining minutes from current subscription
                $remainingMinutes = 0;
                if ($existingSubscription) {
                    $currentExpiry = Carbon::parse($existingSubscription->expires_at);
                    $remainingMinutes = now()->diffInMinutes($currentExpiry, false);
                    // Only add positive remaining time
                    $remainingMinutes = max(0, (int) $remainingMinutes);

                    Log::info('Remaining time from existing subscription', [
                        'user_id' => $userId,
                        'existing_plan_id' => $existingSubscription->plan_id,
                        'new_plan_id' => $planId,
                        'remaining_minutes' => $remainingMinutes,
                    ]);
                }

                // Deactivate all current active subscriptions
                if ($existingSubscription) {
                    DB::table('subscriptions')
                        ->where('user_id', $userId)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'inactive',
                            'updated_at' => now(),
                        ]);
                }

                // 6. Calculate new expiry
                if ($existingSubscription && $existingSubscription->plan_id == $planId) {
                    // EXTENDING SAME PLAN: Add to existing expiry
                    $newExpiry = $currentExpiry->copy()->addMinutes((int) $plan->duration_minutes);
                    $actionType = 'extension';
                    $message = "Plan extended! New expiry: {$newExpiry->format('M d, Y h:i A')}";
                } else {
                    // SWITCHING TO DIFFERENT PLAN: Start from now + new time + remaining time
                    $newExpiry = now()->addMinutes((int) $plan->duration_minutes + $remainingMinutes);

                    if ($remainingMinutes > 0) {
                        $actionType = 'switch_with_credit';
                        $message = "Plan switched! {$remainingMinutes} minutes credited from previous plan. Expires: {$newExpiry->format('M d, Y h:i A')}";
                    } else {
                        $actionType = 'new';
                        $message = "Plan activated! Expires: {$newExpiry->format('M d, Y h:i A')}";
                    }
                }

                // 7. Create new subscription
                DB::table('subscriptions')->insert([
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => $newExpiry,
                    'auto_renew' => $isAutoRenewal ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('Plan purchase completed', [
                    'user_id' => $userId,
                    'plan_name' => $plan->name,
                    'action_type' => $actionType,
                    'new_expiry' => $newExpiry->toDateTimeString(),
                    'remaining_minutes_credited' => $remainingMinutes,
                ]);

                // 8. Record in purchase history
                DB::table('plan_purchases')->insert([
                    'user_id' => $userId,
                    'plan_name' => $plan->name,
                    'amount_paid' => $planPrice,
                    'duration_minutes' => $plan->duration_minutes,
                    'expires_at' => $newExpiry,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 9. Return success response
                return [
                    'success' => true,
                    'plan_name' => $plan->name,
                    'amount' => $planPrice,
                    'expires_at' => $newExpiry->toDateTimeString(),
                    'type' => $actionType,
                    'message' => $message,
                ];

            });
        } catch (Exception $e) {
            Log::error('Plan purchase failed', [
                'user_id' => $userId,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get user's wallet balance
     */
    public function getBalance($userId)
    {
        return DB::table('users')
            ->where('id', $userId)
            ->value('wallet_balance') ?? 0;
    }

    /**
     * Get user's active subscription
     */
    public function getActiveSubscription($userId)
    {
        return DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.user_id', $userId)
            ->where('subscriptions.status', 'active')
            ->where('subscriptions.expires_at', '>', now())
            ->select(
                'subscriptions.*',
                'plans.name as plan_name',
                'plans.id as plan_id'
            )
            ->orderBy('subscriptions.created_at', 'desc')
            ->first();
    }

    /**
     * Get purchase history
     */
    public function getPurchaseHistory($userId, $limit = 10)
    {
        return DB::table('plan_purchases')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();
    }
}
