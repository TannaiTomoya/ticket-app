<?php

namespace App\Models;

use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|string|null $held_quantity
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
     * Load the number of seats held by paid and recent pending orders as `held_quantity`.
     *
     * @param  Builder<TicketType>  $query
     */
    #[Scope]
    protected function withHeldQuantity(Builder $query): void
    {
        $query->withSum([
            'orderItems as held_quantity' => fn (Builder $query) => $query->whereIn('order_id', Order::query()->holdingSeats()->select('id')),
        ], 'quantity');
    }

    /**
     * Get the number of tickets still available (capacity minus held seats, never below zero).
     */
    public function remaining(): int
    {
        $held = array_key_exists('held_quantity', $this->attributes)
            ? (int) $this->held_quantity
            : (int) $this->orderItems()->whereIn('order_id', Order::query()->holdingSeats()->select('id'))->sum('quantity');

        return max(0, $this->capacity - $held);
    }

    /**
     * Get why the ticket type cannot be bought right now, or null when it can.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            now()->lt($this->sale_starts_at) => '販売前',
            now()->gt($this->sale_ends_at) => '販売終了',
            $this->remaining() === 0 => '売り切れ',
            default => null,
        };
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
