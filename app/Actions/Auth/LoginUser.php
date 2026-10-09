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
        $email = strtolower(trim((string) $data['email']));

        $user = User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        // Also check if email matches a tenant's company email
        if (!$user) {
            $tenant = \App\Models\Tenant::whereRaw("LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.company_email')))) = ?", [$email])->first();
            if ($tenant) {
                $user = $tenant->owner() ?? $tenant->users()->first();
            }
        }

        if (!$user || !Hash::check($data['password'], $user->password)) {
            \Illuminate\Support\Facades\Log::warning("Failed login attempt for [{$email}]. User exists: " . ($user ? "Yes (ID {$user->id}, user email {$user->email})" : "No"));
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->isSuspended() || ($user->currentTenant && $user->currentTenant->isSuspended())) {
            throw ValidationException::withMessages([
                'email' => [__('messages.account_pending_approval')],
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
