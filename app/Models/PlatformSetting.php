<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'amazon_associate_tag',
        'default_affiliate_disclaimer',
        'require_moderation_for_new_works',
        'editorial_edition_label',
    ];

    protected function casts(): array
    {
        return [
            'require_moderation_for_new_works' => 'boolean',
        ];
    }
}
