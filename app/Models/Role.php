<?php

namespace App\Models;

use App\Models\Enums\RoleNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

// @TODO: Eventually can merge this onto the user_role pivot to simplify, since roles are fixed/enumerated

class Role extends Model
{
    /**
     * @use HasFactory<\Database\Factories\RoleFactory>
     */
    use HasFactory;

    public $timestamps = false;

    #[Override]
    protected function casts(): array
    {
        return [
            'role' => RoleNames::class,
        ];
    }
}
