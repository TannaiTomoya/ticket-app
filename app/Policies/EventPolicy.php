<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    /**
     * Published events are visible to everyone; others only to the organizer and admins.
     * Everyone else gets a 404 so that unpublished events stay hidden.
     */
    public function view(?User $user, Event $event): Response
    {
        if ($event->isPublished()) {
            return Response::allow();
        }

        return $user !== null && ($this->owns($user, $event) || $user->isAdmin())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Only organizers can create events.
     */
    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Only the organizer of the event can update it.
     */
    public function update(User $user, Event $event): bool
    {
        return $this->owns($user, $event);
    }

    /**
     * The organizer can delete the event only while it has no orders.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->owns($user, $event) && ! $event->orders()->exists();
    }

    /**
     * The organizer of the event and admins can see its sales.
     */
    public function viewOrders(User $user, Event $event): bool
    {
        return $this->owns($user, $event) || $user->isAdmin();
    }

    /**
     * Only admins can unpublish events.
     */
    public function unpublish(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user is the organizer of the event.
     */
    private function owns(User $user, Event $event): bool
    {
        return $user->id === $event->organizer_id;
    }
}
