<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'latin_motto',
        'description',
        'accent_color',
        'icon_name',
    ];

    public function works()
    {
        return $this->hasMany(Work::class);
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }
}
