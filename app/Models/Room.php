<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'description', 'rent', 'status'])]
class Room extends Model
{
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
