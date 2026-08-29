<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginUser
{
    /**
     * Authenticate a user and generate a Sanctum token.
     *
     * @param array{email: string, password: string, device_name?: string} $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function execute(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => [__('messages.account_suspended')],
            ]);
        }

        if ($user->isInactive()) {
            throw ValidationException::withMessages([
                'email' => [__('messages.account_inactive')],
            ]);
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        $deviceName = $data['device_name'] ?? config('sada.token.name', 'api-token');
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $user->fresh(),
            'token' => $token,
        ];
    }
}
