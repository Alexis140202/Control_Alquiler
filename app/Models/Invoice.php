<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['contract_id', 'type', 'period', 'amount', 'due_at', 'paid_at', 'late_fee', 'status', 'previous_reading', 'current_reading', 'rate', 'consumption'])]
class Invoice extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getTotalAttribute(): float
    {
        $days = max(0, Carbon::parse($this->due_at)->startOfDay()->diffInDays(now()->startOfDay(), false));

        return (float) $this->amount + (float) $this->late_fee + ($this->status === 'pending' ? min(config('rental.late_fee_max_days'), $days) * config('rental.late_fee_per_day') : 0);
    }
}
