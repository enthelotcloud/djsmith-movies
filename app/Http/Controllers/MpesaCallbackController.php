<?php

namespace App\Http\Controllers;

use App\Models\MpesaTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function handleCallback(Request $request)
    {
        $payload = $request->all();
        
        // Log the raw payload for debugging purposes
        Log::info('M-Pesa Callback Received:', $payload);

        $stkCallback = $payload['Body']['stkCallback'] ?? null;
        if (!$stkCallback) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $resultCode = $stkCallback['ResultCode'];
        $resultDesc = $stkCallback['ResultDesc'];

        // Find the pending transaction in our database
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found or already processed'], 404);
        }

        // Inside handleCallback(), replace the $resultCode == 0 block with this:

        if ($resultCode == 0) {
            $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;

            foreach ($callbackMetadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                    break;
                }
            }

            $transaction->update([
                'status' => 'completed',
                'mpesa_receipt_number' => $receiptNumber,
                'result_desc' => $resultDesc,
            ]);

            // 1. Add the money to the wallet (The Ledger stays balanced)
            $transaction->user->increment('wallet_balance', $transaction->amount);

            // 2. NEW: Auto-Purchase the Plan if this was a Direct Payment!
            if ($transaction->target_plan_id) {
                $plan = \App\Models\Plan::find($transaction->target_plan_id);
                $user = $transaction->user;

                if ($plan && $user->wallet_balance >= $plan->price) {
                    // Deduct wallet safely
                    $user->decrement('wallet_balance', $plan->price);

                    // Deactivate old subscriptions
                    \App\Models\Subscription::where('user_id', $user->id)
                        ->where('status', 'active')
                        ->update(['status' => 'inactive']);

                    // Create new active subscription!
                    \App\Models\Subscription::create([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'amount_paid' => $plan->price, // Transparent ledger
                        'status' => 'active',
                        'starts_at' => now(),
                        'expires_at' => now()->addMinutes($plan->duration_minutes),
                        'auto_renew' => true,
                    ]);
                }
            }
        }

        // Safaricom expects a success response so they stop retrying
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}