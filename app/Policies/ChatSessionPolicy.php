<?php

namespace App\Policies;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatSessionPolicy
{
    use HandlesAuthorization;

    /**
     * Admins (is_admin flag) can do anything.
     */
    public function before(User $user): ?bool
    {
        // Admins can do anything
        if ($user->isAdmin()) {
            return true;
        }
        return null;
    }

    /** View the session and its messages */
    public function view(User $user, ChatSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    /** Send a message inside the session */
    public function interact(User $user, ChatSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    /** Delete the session */
    public function delete(User $user, ChatSession $session): bool
    {
        return $user->id === $session->user_id;
    }
}
