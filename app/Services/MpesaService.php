<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MpesaService
{
    public string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('mpesa.environment') === 'production' 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Generate Daraja Access Token
     */
    public function getAccessToken(): ?string
    {
        $credentials = base64_encode(config('mpesa.consumer_key') . ':' . config('mpesa.consumer_secret'));

        $response = Http::withHeaders([
            'Authorization' => 'Basic ' . $credentials
        ])->get($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');

        if ($response->successful()) {
            return $response->json('access_token');
        }

        Log::error('M-Pesa Access Token Error: ' . $response->body());
        return null;
    }

    /**
     * Initiate STK Push
     */
    public function stkPush(string $phone, float $amount, string $accountReference)
    {
        $token = $this->getAccessToken();
        if (!$token) return ['success' => false, 'message' => 'Authentication failed'];

        $timestamp = Carbon::now()->format('YmdHis');
        $shortcode = config('mpesa.shortcode');
        $passkey = config('mpesa.passkey');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) $amount, // Safaricom expects integers
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => config('mpesa.callback_url'),
            'AccountReference' => $accountReference,
            'TransactionDesc' => 'Wallet Topup'
        ];

        $response = Http::withToken($token)->post($this->baseUrl . '/mpesa/stkpush/v1/processrequest', $payload);

        return $response->json();
    }
}