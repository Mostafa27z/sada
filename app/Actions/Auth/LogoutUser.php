<?php

namespace App\Actions\Auth;

use App\Models\User;

class LogoutUser
{
    /**
     * Revoke the current access token.
     */
    public function execute(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Revoke all access tokens for the user.
     */
    public function executeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
