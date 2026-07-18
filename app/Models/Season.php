<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'series_id',
        'name',
        'slug',
        'excerpt',
        'poster',
        'trailer_url',
    ];

    /**
     * A Season belongs to a specific Series.
     */
    public function series()
    {
        return $this->belongsTo(Series::class);
    }

    /**
     * A Season acts like a category and has many Episodes via the pivot table.
     */
    public function episodes()
    {
        return $this->belongsToMany(Episode::class, 'episode_season')
                    ->withTimestamps();
    }
}
