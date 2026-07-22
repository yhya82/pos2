<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minimal shell for now — the POS terminal (Phase 05) needs to search and
 * select a customer for credit sales; full Customer management (profile,
 * purchase/credit/payment history UI) is Phase 06.
 */
class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'credit_enabled',
        'credit_limit',
        'outstanding_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credit_enabled' => 'boolean',
            'credit_limit' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function availableCredit(): float
    {
        return (float) $this->credit_limit - (float) $this->outstanding_balance;
    }
}
