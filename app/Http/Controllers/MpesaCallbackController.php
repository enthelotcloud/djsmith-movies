<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\WalletService;

class MpesaCallbackController extends Controller
{
    public function handleCallback(Request $request, WalletService $walletService)
    {
        $payload = $request->all();
        Log::info('M-Pesa Callback Received', $payload);

        $stkCallback = $payload['Body']['stkCallback'] ?? null;
        if (!$stkCallback) return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload'], 400);

        $checkoutRequestId = $stkCallback['CheckoutRequestID'];
        $resultCode = $stkCallback['ResultCode'];

        try {
            DB::transaction(function () use ($checkoutRequestId, $resultCode, $stkCallback, $walletService) {
                // Lock the transaction row to prevent duplicate Daraja callbacks from double-crediting
                $transaction = DB::table('mpesa_transactions')
                    ->where('checkout_request_id', $checkoutRequestId)
                    ->lockForUpdate()
                    ->first();

                if (!$transaction || $transaction->status !== 'pending') {
                    return; // Already processed or doesn't exist
                }

                if ($resultCode == 0) {
                    $receiptNumber = null;
                    foreach ($stkCallback['CallbackMetadata']['Item'] ?? [] as $item) {
                        if ($item['Name'] === 'MpesaReceiptNumber') {
                            $receiptNumber = $item['Value'];
                            break;
                        }
                    }

                    // 1. Mark STK Completed
                    DB::table('mpesa_transactions')->where('id', $transaction->id)->update([
                        'status' => 'completed',
                        'mpesa_receipt_number' => $receiptNumber,
                        'result_desc' => 'Success',
                        'updated_at' => now(),
                    ]);

                    // 2. Credit the Wallet securely via Ledger
                    $walletService->creditWallet(
                        $transaction->user_id,
                        $transaction->amount,
                        'mpesa_topup',
                        $transaction->id,
                        "M-Pesa Deposit ($receiptNumber)"
                    );

                    // 3. The Crossover: Auto-buy plan if this was a direct checkout
                    if ($transaction->target_plan_id) {
                        try {
                            $walletService->purchasePlan($transaction->user_id, $transaction->target_plan_id);
                        } catch (\Exception $e) {
                            Log::error("Wallet topped up, but plan purchase failed: " . $e->getMessage());
                            // Money stays in wallet if plan purchase fails. Safe fallback.
                        }
                    }
                } else {
                    DB::table('mpesa_transactions')->where('id', $transaction->id)->update([
                        'status' => 'failed',
                        'result_desc' => $stkCallback['ResultDesc'],
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Mpesa Callback Error: ' . $e->getMessage());
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal Error'], 500);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
