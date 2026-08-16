<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargoType extends Model
{
    protected $casts = [
        'type' => Enums\CargoType::class,
        'min_cargo_split' => 'integer',
        'min_payload' => 'integer',
        'max_payload' => 'integer',
    ];

    public $timestamps = false;

    protected $fillable = ['type', 'text', 'min_cargo_split', 'min_payload', 'max_payload'];
}
