<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes, Concerns\Audited;

    protected $casts = ['date' => 'datetime', 'needs_review' => 'boolean'];

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'document_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function memberCard(): BelongsTo
    {
        return $this->belongsTo(MemberCard::class);
    }

    /** Frontend `Transaction` shape. */
    public function toApi(): array
    {
        return [
            ...collect($this->attributesToArray())->except(['lines', 'warehouse', 'customer', 'creator', 'payments', 'register', 'member_card', 'deleted_at'])->all(),
            'warehouse' => $this->warehouse?->name,
            'customer' => $this->customer?->name,
            'balance' => $this->grand_total - $this->paid,
            'created_by' => $this->creator?->username,
            'items' => $this->lines->map(fn ($line) => collect($line->toArray())->except(['id', 'document_id'])->all())->values(),
            'member_card' => $this->memberCard?->card_no,
            'payments' => $this->relationLoaded('payments') ? $this->payments : [],
        ];
    }
}
