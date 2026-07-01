<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'label',
        'type',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];
}
