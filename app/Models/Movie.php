<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Movie extends Model
{
    protected $guarded = [];

    protected $casts = [
        'release_date' => 'date',
        'is_premium' => 'boolean',
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(MovieCategory::class, 'movie_category_id');
    }

    // Backblaze Stream URL Accessor
    public function getStreamUrlAttribute()
    {
        // Generates an expiring URL to protect your Backblaze bandwidth
        return Storage::disk('b2')->temporaryUrl($this->video_path, now()->addHours(3));
    }

    // --- SORTING SCOPES (Use these in Livewire: Movie::mostViewed()->get()) ---

    public function scopeLatestReleases($query)
    {
        return $query->orderBy('release_date', 'desc');
    }

    public function scopeMostViewed($query)
    {
        return $query->orderBy('views', 'desc');
    }

    public function scopeTopTen($query)
    {
        return $query->orderBy('views', 'desc')->orderBy('rating', 'desc')->take(10);
    }
}