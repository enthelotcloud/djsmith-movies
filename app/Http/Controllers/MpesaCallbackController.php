<?php

namespace App\Http\Controllers;

use App\Models\MpesaTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\PlanPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MpesaCallbackController extends Controller
{
    public function handleCallback(Request $request)
    {
        $payload = $request->all();
        
        // Log for your own debugging if Safaricom fails
        Log::info('M-Pesa Callback Received:', $payload);

        $stkCallback = $payload['Body']['stkCallback'] ?? null;
        
        if (!$stkCallback) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload'], 400);
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $resultCode = $stkCallback['ResultCode'];
        $resultDesc = $stkCallback['ResultDesc'];

        // Find the pending transaction
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)
            ->where('status', 'pending')
            ->first();

        // If not found or already processed, tell Safaricom we got it so they stop retrying
        if (!$transaction) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Transaction already processed or not found']);
        }

        // RESULT CODE 0 = SUCCESS
        if ($resultCode == 0) {
            $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;

            foreach ($callbackMetadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                    break;
                }
            }

            // 1. Mark transaction as completed
            $transaction->update([
                'status' => 'completed',
                'mpesa_receipt_number' => $receiptNumber,
                'result_desc' => $resultDesc,
            ]);

            // 2. CREDIT WALLET (Strict DB query to avoid caching issues)
            DB::table('users')->where('id', $transaction->user_id)->increment('wallet_balance', $transaction->amount);

            // 3. DUAL-MODE ROUTING: Did they intend to buy a plan?
            if ($transaction->target_plan_id) {
                $plan = Plan::find($transaction->target_plan_id);
                
                // Fetch the fresh wallet balance securely
                $currentWallet = DB::table('users')->where('id', $transaction->user_id)->value('wallet_balance');

                if ($plan && $currentWallet >= $plan->price) {
                    
                    // A. Deduct the wallet
                    DB::table('users')->where('id', $transaction->user_id)->decrement('wallet_balance', $plan->price);

                    // B. Deactivate old subscriptions
                    Subscription::where('user_id', $transaction->user_id)
                        ->where('status', 'active')
                        ->update(['status' => 'inactive']);

                    // C. Grant Movie Access
                    Subscription::create([
                        'user_id' => $transaction->user_id,
                        'plan_id' => $plan->id,
                        'amount_paid' => $plan->price,
                        'status' => 'active',
                        'starts_at' => Carbon::now(),
                        'expires_at' => Carbon::now()->addMinutes($plan->duration_minutes),
                        'auto_renew' => true,
                    ]);

                    // D. Write to the Permanent Plan History Ledger
                    PlanPurchase::create([
                        'user_id' => $transaction->user_id,
                        'plan_name' => $plan->name,
                        'amount_paid' => $plan->price,
                        'duration_minutes' => $plan->duration_minutes,
                        'expires_at' => Carbon::now()->addMinutes($plan->duration_minutes),
                    ]);
                }
            }

        } else {
            // RESULT CODE != 0 (FAILED, CANCELLED, TIMEOUT)
            $transaction->update([
                'status' => 'failed',
                'result_desc' => $resultDesc,
            ]);
        }

        // Safaricom strictly requires this exact JSON format to acknowledge receipt
        return response()->json([
            'ResultCode' => 0, 
            'ResultDesc' => 'Accepted'
        ]);
    }
}