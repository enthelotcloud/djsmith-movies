<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Route::livewire('/', 'pages::home')->name('home');
Route::view('/about', 'about')->name('about');
Route::view('/contact', 'contact')->name('contact');
Route::view('/faqs', 'faqs')->name('faqs');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy');
Route::view('/terms-of-services', 'terms-of-services')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::get('/api/video-key/{slug}', function (Request $request, $slug) {
    // 1. Verify the movie exists
    $movie = DB::table('movies')->where('slug', $slug)->first();
    if (!$movie) abort(404);

    // 2. Locate the key in your private local storage (NOT Backblaze)
    // Assuming you saved it as 'storage/app/video_keys/movie-slug.key'
    $keyPath = "video_keys/{$movie->slug}.key";

    if (!Storage::disk('local')->exists($keyPath)) {
        abort(404, 'Key not found.');
    }

    // 3. Serve the file securely with strict anti-caching
    return response()->stream(function () use ($keyPath) {
        echo Storage::disk('local')->get($keyPath);
    }, 200, [
        'Content-Type' => 'application/octet-stream',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->middleware(['auth', 'secure.video'])->name('video.key');

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


require __DIR__.'/settings.php';
