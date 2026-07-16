<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Route, DB, Storage, Log, Cache, Auth};
use Illuminate\Support\Str;

Route::livewire('/', 'pages::home')->name('home');
Route::view('/about', 'about')->name('about');
Route::view('/contact', 'contact')->name('contact');
Route::view('/faqs', 'faqs')->name('faqs');
Route::livewire('/search', 'pages::search-result')->name('client.search');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy');
Route::view('/terms-of-services', 'terms-of-services')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// 1. Manifest Proxy
Route::get('/api/stream/{slug}/manifest.m3u8', function ($slug) {
    $movie = DB::table('movies')->where('slug', $slug)->first();
    if (!$movie) abort(404);

    if (!Storage::disk('b2')->exists($movie->video_path)) {
        Log::error("Manifest path missing: " . $movie->video_path);
        abort(404, "Manifest file not found.");
    }

    $manifestContent = Storage::disk('b2')->get($movie->video_path);

    // Generate Burner Token (valid for 30s)
    $token = Str::random(32);
    Cache::put('key_token_' . $token, true, now()->addSeconds(30));
    $keyUrl = route('video.key', ['slug' => $slug]) . '?t=' . $token;

    // Inject Key
    $manifestContent = preg_replace('/#EXT-X-KEY:METHOD=AES-128,URI="[^"]+"/', '#EXT-X-KEY:METHOD=AES-128,URI="' . $keyUrl . '"', $manifestContent);

    // Inject Chunk URLs
    $bucket = config('filesystems.disks.b2.bucket');
    $endpoint = str_replace('https://', '', config('filesystems.disks.b2.endpoint'));
    $baseDir = dirname($movie->video_path);
    $backblazeBaseUrl = "https://{$bucket}.{$endpoint}/" . ($baseDir !== '.' ? $baseDir . '/' : '');
    $manifestContent = preg_replace('/^(?!#)(?!http)(.+)$/m', $backblazeBaseUrl . '$1', $manifestContent);

    return response($manifestContent, 200, [
        'Content-Type' => 'application/vnd.apple.mpegurl',
        'Cache-Control' => 'no-cache, no-store, must-revalidate'
    ]);
})->middleware(['auth', 'secure.video'])->name('stream.manifest');

// 2. Single Source of Truth for Decryption Key
Route::get('/api/video-key/{slug}', function (Request $request, $slug) {
    $token = $request->query('t');

    // Check if the burner token is valid
    if (!$token || !Cache::has('key_token_' . $token)) {
        abort(403, 'Key token expired or invalid.');
    }

    $movie = DB::table('movies')->where('slug', $slug)->first();

    // BUILD THE ABSOLUTE OS PATH DIRECTLY
    $absolutePath = storage_path("app/video_keys/{$movie->slug}.key");

    // USE NATIVE PHP TO CHECK THE FILE (Bypasses Laravel's disk config)
    if (!file_exists($absolutePath)) {
        Log::error("OS cannot find key at: " . $absolutePath);
        abort(404, 'Key file missing on server.');
    }

    // Serve the file directly from the OS
    return response()->file($absolutePath, [
        'Content-Type' => 'application/octet-stream',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->middleware(['auth'])->name('video.key');

Route::livewire('/watch/{slug}', 'pages::player')
    ->middleware(['auth', 'secure.video'])
    ->name('client.player');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin'])
    ->group(function () {

    Route::livewire('/users', 'admin::user-manager')->name('users');
    Route::livewire('/dashboard', 'admin::dashboard')->name('dashboard');
    Route::livewire('/plans', 'admin::plan-manager')->name('plans');
    Route::livewire('/movies', 'admin::movie-manager')->name('movies');
    Route::livewire('/categories', 'admin::category-manager')->name('categories');
});

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'role:client'])
    ->group(function () {

    Route::livewire('/dashboard', 'client::dashboard')->name('dashboard');
    Route::livewire('/wallet-topup', 'client::wallet-topup')->name('wallet-topup');
    Route::livewire('/subscriptions', 'client::subscriptions')->name('subscriptions');
});

Route::prefix('staff')
    ->name('staff.')
    ->middleware(['auth', 'role:staff'])
    ->group(function () {

    Route::livewire('/dashboard', 'staff::dashboard')->name('dashboard');
});

Route::get('/debug-key', function () {
    $slug = 'snakes-on-a-plane-6a58244f0caf8'; // Your exact movie slug
    $keyPath = "video_keys/{$slug}.key";

    // Get the exact Windows path Laravel is looking at
    $absolutePath = storage_path("app/" . $keyPath);
    $exists = Storage::disk('local')->exists($keyPath);

    return response()->json([
        'laravel_is_looking_here' => $absolutePath,
        'does_the_file_exist' => $exists ? 'YES!' : 'NO - FILE MISSING',
        'files_in_this_folder' => Storage::disk('local')->files('video_keys')
    ]);
});

require __DIR__.'/settings.php';
