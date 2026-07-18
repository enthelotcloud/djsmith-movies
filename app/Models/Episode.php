<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Episode extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'description',
        'thumbnail',
        'video_path',
        'duration_in_seconds',
        'is_premium',
        'status',
    ];

    /**
     * An Episode belongs to Seasons via the pivot table.
     */
    public function seasons()
    {
        return $this->belongsToMany(Season::class, 'episode_season')
                    ->withTimestamps();
    }
}
