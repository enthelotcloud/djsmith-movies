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

        // 0 means success in Safaricom Daraja
        if ($resultCode == 0) {
            $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;

            // Extract the receipt number from Safaricom's weird array format
            foreach ($callbackMetadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                    break;
                }
            }

            // Update Transaction
            $transaction->update([
                'status' => 'completed',
                'mpesa_receipt_number' => $receiptNumber,
                'result_desc' => $resultDesc,
            ]);

            // Safely Increment the User's Wallet
            $transaction->user->increment('wallet_balance', $transaction->amount);

        } else {
            // User cancelled, insufficient funds, or timeout
            $transaction->update([
                'status' => 'failed',
                'result_desc' => $resultDesc,
            ]);
        }

        // Safaricom expects a success response so they stop retrying
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}