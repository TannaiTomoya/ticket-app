<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;

class TicketTypePolicy
{
    /**
     * Only the organizer of the event can add ticket types to it.
     */
    public function create(User $user, Event $event): bool
    {
        return $user->id === $event->organizer_id;
    }

    /**
     * Only the organizer of the event can update its ticket types.
     */
    public function update(User $user, TicketType $ticketType): bool
    {
        return $user->id === $ticketType->event->organizer_id;
    }

    /**
     * The organizer can delete a ticket type only while it has never been ordered.
     */
    public function delete(User $user, TicketType $ticketType): bool
    {
        return $this->update($user, $ticketType) && $ticketType->isDeletable();
    }
}
