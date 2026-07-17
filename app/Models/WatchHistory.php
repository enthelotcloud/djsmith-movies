<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchHistory extends Model
{
    protected $fillable = [
        'user_id',
        'movie_id',
        'progress_seconds',
        'is_completed'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }
}
