<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Series extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'poster',
        'trailer_url',
        'status',
    ];

    /**
     * A Series has many Seasons.
     */
    public function seasons()
    {
        return $this->hasMany(Season::class);
    }

    /**
     * Optional Helper: Get all episodes across all seasons for this series.
     */
    public function episodes()
    {
        return $this->hasManyThrough(Episode::class, Season::class);
    }
}
