<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Route, DB, Storage, Log, Cache, Auth};
use Illuminate\Support\Str;
use App\Http\Controllers\PushController;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('/live', 'pages::live')->name('live');
Route::view('/about', 'about')->name('about');
Route::view('/contact', 'contact')->name('contact');
Route::view('/faqs', 'faqs')->name('faqs');


Route::livewire('/search', 'pages::search-result')->name('client.search');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy');
Route::view('/terms-of-services', 'terms-of-services')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::post('/push-subscribe', [PushController::class, 'subscribe']);
});

// // 1. Manifest Proxy
// Route::get('/api/stream/{slug}/manifest.m3u8', function ($slug) {
//     $movie = DB::table('movies')->where('slug', $slug)->first();
//     if (!$movie) abort(404);

//     if (!Storage::disk('b2')->exists($movie->video_path)) {
//         Log::error("Manifest path missing: " . $movie->video_path);
//         abort(404, "Manifest file not found.");
//     }

//     $manifestContent = Storage::disk('b2')->get($movie->video_path);

//     // Generate Burner Token (valid for 30s)
//     $token = Str::random(32);
//     Cache::put('key_token_' . $token, true, now()->addSeconds(30));
//     $keyUrl = route('video.key', ['slug' => $slug]) . '?t=' . $token;

//     // Inject Key
//     $manifestContent = preg_replace('/#EXT-X-KEY:METHOD=AES-128,URI="[^"]+"/', '#EXT-X-KEY:METHOD=AES-128,URI="' . $keyUrl . '"', $manifestContent);

//     // Inject Chunk URLs
//     $bucket = config('filesystems.disks.b2.bucket');
//     $endpoint = str_replace('https://', '', config('filesystems.disks.b2.endpoint'));
//     $baseDir = dirname($movie->video_path);
//     $backblazeBaseUrl = "https://{$bucket}.{$endpoint}/" . ($baseDir !== '.' ? $baseDir . '/' : '');
//     $manifestContent = preg_replace('/^(?!#)(?!http)(.+)$/m', $backblazeBaseUrl . '$1', $manifestContent);

//     return response($manifestContent, 200, [
//         'Content-Type' => 'application/vnd.apple.mpegurl',
//         'Cache-Control' => 'no-cache, no-store, must-revalidate'
//     ]);
// })->middleware(['auth', 'secure.video'])->name('stream.manifest');

// // 2. Single Source of Truth for Decryption Key
// Route::get('/api/video-key/{slug}', function (Request $request, $slug) {
//     $token = $request->query('t');

//     // Check if the burner token is valid
//     if (!$token || !Cache::has('key_token_' . $token)) {
//         abort(403, 'Key token expired or invalid.');
//     }

//     $movie = DB::table('movies')->where('slug', $slug)->first();

//     // BUILD THE ABSOLUTE OS PATH DIRECTLY
//     $absolutePath = storage_path("app/video_keys/{$movie->slug}.key");

//     // USE NATIVE PHP TO CHECK THE FILE (Bypasses Laravel's disk config)
//     if (!file_exists($absolutePath)) {
//         Log::error("OS cannot find key at: " . $absolutePath);
//         abort(404, 'Key file missing on server.');
//     }

//     // Serve the file directly from the OS
//     return response()->file($absolutePath, [
//         'Content-Type' => 'application/octet-stream',
//         'Cache-Control' => 'no-cache, no-store, must-revalidate',
//     ]);
// })->middleware(['auth'])->name('video.key');

// 1. Manifest Proxy (Bulletproof Edition for Movies & Episodes)
Route::get('/api/stream/{slug}/manifest.m3u8', function ($slug) {
    $media = DB::table('movies')->where('slug', $slug)->first()
             ?? DB::table('episodes')->where('slug', $slug)->first();

    if (!$media) abort(404, "Media not found.");

    if (!Storage::disk('b2')->exists($media->video_path)) {
        Log::error("Manifest path missing: " . $media->video_path);
        abort(404, "Manifest file not found.");
    }

    $manifestContent = Storage::disk('b2')->get($media->video_path);

    // 🚨 BUG FIX 1: Strip Windows Carriage Returns so chunk URLs don't break with %0D
    $manifestContent = str_replace("\r", "", $manifestContent);

    // Generate Burner Token
    $token = Str::random(32);
    Cache::put('key_token_' . $token, true, now()->addSeconds(30));
    $keyUrl = route('video.key', ['slug' => $slug]) . '?t=' . $token;

    // 🚨 BUG FIX 2: Bulletproof Regex (Handles ANY order of the EXT-X-KEY tags)
    $manifestContent = preg_replace('/(#EXT-X-KEY:.*?)URI="[^"]+"/', '$1URI="' . $keyUrl . '"', $manifestContent);

    // Inject Chunk URLs
    $bucket = config('filesystems.disks.b2.bucket');
    $endpoint = str_replace('https://', '', config('filesystems.disks.b2.endpoint'));
    $baseDir = dirname($media->video_path);
    $backblazeBaseUrl = "https://{$bucket}.{$endpoint}/" . ($baseDir !== '.' ? $baseDir . '/' : '');

    // Inject Backblaze URL to chunks
    $manifestContent = preg_replace('/^(?!#)(?!http)(.+)$/m', $backblazeBaseUrl . '$1', $manifestContent);

    return response($manifestContent, 200, [
        'Content-Type' => 'application/vnd.apple.mpegurl',
        'Cache-Control' => 'no-cache, no-store, must-revalidate'
    ]);
})->middleware(['auth', 'secure.video'])->name('stream.manifest');

// 2. Single Source of Truth for Decryption Key (Updated for Movies & Episodes)
Route::get('/api/video-key/{slug}', function (Request $request, $slug) {
    $token = $request->query('t');

    // Check if the burner token is valid
    if (!$token || !Cache::has('key_token_' . $token)) {
        abort(403, 'Key token expired or invalid.');
    }

    // 1. LOOKUP: Try movies, fallback to episodes
    $media = DB::table('movies')->where('slug', $slug)->first()
             ?? DB::table('episodes')->where('slug', $slug)->first();

    if (!$media) {
        Log::error("Key Request Failed: Media not found for slug: " . $slug);
        abort(404, 'Media not found.');
    }

    // 2. BUILD THE ABSOLUTE OS PATH
    // We use $media->slug to ensure the filename matches regardless of where it was found
    $absolutePath = storage_path("app/video_keys/{$media->slug}.key");

    // 3. USE NATIVE PHP TO CHECK THE FILE
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

Route::livewire('/series/watch/{slug}', 'pages::series-player')
    ->middleware(['auth', 'secure.video'])
    ->name('client.series.player');

Route::livewire('/category/{slug}', 'pages::category-single')->name('category.single');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin'])
    ->group(function () {

    Route::livewire('/users', 'admin::user-manager')->name('users');
    Route::livewire('/dashboard', 'admin::dashboard')->name('dashboard');
    Route::livewire('/plans', 'admin::plan-manager')->name('plans');
    Route::livewire('/movies', 'admin::movie-manager')->name('movies');
    Route::livewire('/episodes', 'admin::episodes-manager')->name('episodes');
    Route::livewire('/seasons', 'admin::seasons-manager')->name('seasons');
    Route::livewire('/series', 'admin::series-manager')->name('series');
    Route::livewire('/categories', 'admin::category-manager')->name('categories');
});

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'role:client'])
    ->group(function () {

    Route::livewire('/dashboard', 'client::dashboard')->name('dashboard');
    Route::livewire('/wallet-topup', 'client::wallet-topup')->name('wallet-topup');
    Route::livewire('/subscriptions', 'client::subscriptions')->name('subscriptions');
    Route::livewire('/series/{slug}', 'pages::series-show')->name('series.show');

});

Route::prefix('staff')
    ->name('staff.')
    ->middleware(['auth', 'role:staff'])
    ->group(function () {

    Route::livewire('/dashboard', 'staff::dashboard')->name('dashboard');
});

// Route::get('/debug-key', function () {
//     $slug = 'snakes-on-a-plane-6a58244f0caf8'; // Your exact movie slug
//     $keyPath = "video_keys/{$slug}.key";

//     // Get the exact Windows path Laravel is looking at
//     $absolutePath = storage_path("app/" . $keyPath);
//     $exists = Storage::disk('local')->exists($keyPath);

//     return response()->json([
//         'laravel_is_looking_here' => $absolutePath,
//         'does_the_file_exist' => $exists ? 'YES!' : 'NO - FILE MISSING',
//         'files_in_this_folder' => Storage::disk('local')->files('video_keys')
//     ]);
// });

Route::get('/debug-episode/{slug}', function ($slug) {
    $episode = DB::table('episodes')->where('slug', $slug)->first();
    if (!$episode) return "Episode not found in database.";

    $manifestExists = Storage::disk('b2')->exists($episode->video_path);
    $keyPath = storage_path("app/video_keys/{$episode->slug}.key");
    $keyExists = file_exists($keyPath);

    return response()->json([
        '1_episode_slug' => $episode->slug,
        '2_b2_manifest_path' => $episode->video_path,
        '3_does_manifest_exist_on_b2' => $manifestExists ? 'YES' : 'NO - MISSING',
        '4_expected_key_location_on_server' => $keyPath,
        '5_does_key_exist_on_server' => $keyExists ? 'YES' : 'NO - MISSING',
    ]);
});

require __DIR__.'/settings.php';
