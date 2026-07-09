<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function handleCallback(Request $request)
    {
        $payload = $request->all();
        $stkCallback = $payload['Body']['stkCallback'] ?? null;
        
        if (!$stkCallback) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload'], 400);
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $resultCode = $stkCallback['ResultCode'];

        // Find the pending transaction using raw DB
        $transaction = DB::table('mpesa_transactions')
            ->where('checkout_request_id', $checkoutRequestId)
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Processed']);
        }

        if ($resultCode == 0) {
            $receiptNumber = null;
            foreach ($stkCallback['CallbackMetadata']['Item'] ?? [] as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                    break;
                }
            }

            // 1. Mark transaction as completed
            DB::table('mpesa_transactions')->where('id', $transaction->id)->update([
                'status' => 'completed',
                'mpesa_receipt_number' => $receiptNumber,
                'result_desc' => 'Success',
                'updated_at' => now(),
            ]);

            // 2. CREDIT WALLET (Raw SQL)
            DB::table('users')->where('id', $transaction->user_id)->increment('wallet_balance', $transaction->amount);

            // 3. AUTO-PURCHASE PLAN IF REQUESTED
            if ($transaction->target_plan_id) {
                $plan = DB::table('plans')->where('id', $transaction->target_plan_id)->first();
                $currentWallet = DB::table('users')->where('id', $transaction->user_id)->value('wallet_balance');

                if ($plan && $currentWallet >= $plan->price) {
                    
                    // A. Deduct Wallet
                    DB::table('users')->where('id', $transaction->user_id)->decrement('wallet_balance', $plan->price);

                    // B. Deactivate Old Plans
                    DB::table('subscriptions')
                        ->where('user_id', $transaction->user_id)
                        ->where('status', 'active')
                        ->update(['status' => 'inactive', 'updated_at' => now()]);

                    // C. Force Insert New Plan & Ledger History (Bypasses Model Rules)
                    DB::table('subscriptions')->insert([
                        'user_id' => $transaction->user_id,
                        'plan_id' => $plan->id,
                        'amount_paid' => $plan->price,
                        'status' => 'active',
                        'starts_at' => now(),
                        'expires_at' => now()->addMinutes($plan->duration_minutes),
                        'auto_renew' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

        } else {
            // Failed M-Pesa transaction
            DB::table('mpesa_transactions')->where('id', $transaction->id)->update([
                'status' => 'failed',
                'result_desc' => $stkCallback['ResultDesc'],
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}