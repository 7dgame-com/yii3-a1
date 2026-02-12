<?php

declare(strict_types=1);

namespace App\Policy;

use App\Model\User;
use App\Model\Verse;

/**
 * VersePolicy provides authorization checks for Verse-level access control.
 *
 * All permission checks are ownership-based, using the author_id field
 * on the Verse model to determine if the given user is the author.
 *
 * @see Requirements 9.3
 */
final class VersePolicy
{
    /**
     * Determine if the user can view the given verse.
     *
     * Currently, all authenticated users can view any verse.
     */
    public function canView(User $user, Verse $verse): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the given verse.
     *
     * Only the author (owner) of the verse can update it.
     */
    public function canUpdate(User $user, Verse $verse): bool
    {
        return $this->isOwner($user, $verse);
    }

    /**
     * Determine if the user can delete the given verse.
     *
     * Only the author (owner) of the verse can delete it.
     */
    public function canDelete(User $user, Verse $verse): bool
    {
        return $this->isOwner($user, $verse);
    }

    /**
     * Check if the user is the owner (author) of the verse.
     */
    private function isOwner(User $user, Verse $verse): bool
    {
        return $user->get('id') === $verse->get('author_id');
    }
}
