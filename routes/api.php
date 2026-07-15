<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MpesaCallbackController;
use Illuminate\Support\Facades\Crypt;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/mpesa/callback', [MpesaCallbackController::class, 'handleCallback']);


Route::middleware('auth:sanctum')->group(function () {

    // 🔒 Decrypt video URL endpoint
    Route::post('/video/decrypt', function (Request $request) {
        $request->validate([
            'encrypted_url' => 'required|string',
            'session_id' => 'required|string',
        ]);

        try {
            // Verify session matches
            if ($request->session_id !== session()->getId()) {
                abort(403, 'Invalid session');
            }

            // Decrypt the URL
            $decryptedUrl = Crypt::decryptString($request->encrypted_url);

            return response()->json([
                'url' => $decryptedUrl,
                'expires_in' => 14400 // 4 hours
            ]);
        } catch (\Exception $e) {
            abort(403, 'Invalid or expired video URL');
        }
    })->name('api.video.decrypt');
});
