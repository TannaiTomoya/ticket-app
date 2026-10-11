<?php

namespace App\Models;

use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property int $price
 * @property int $capacity
 * @property Carbon $sale_starts_at
 * @property Carbon $sale_ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'capacity', 'sale_starts_at', 'sale_ends_at'])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Determine whether the ticket type is within its sale period now.
     */
    public function isOnSale(): bool
    {
        return now()->between($this->sale_starts_at, $this->sale_ends_at);
    }

    /**
     * Determine whether the ticket type can be deleted (it has never been ordered).
     */
    public function isDeletable(): bool
    {
        return $this->orderItems()->doesntExist();
    }
}
