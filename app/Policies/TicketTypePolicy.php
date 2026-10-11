<?php

namespace App\Policies;

use App\Models\TicketType;
use App\Models\User;

class TicketTypePolicy
{
    /**
     * Whoever can update the event can delete its ticket types, but only while they have never been ordered.
     */
    public function delete(User $user, TicketType $ticketType): bool
    {
        return $user->can('update', $ticketType->event) && $ticketType->isDeletable();
    }
}
