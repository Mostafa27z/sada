<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;

class RegisterUser
{
    /**
     * Register a new user and generate a Sanctum token.
     *
     * @param array{name: string, email: string, password: string} $data
     * @return array{user: User, token: string}
     */
    public function execute(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // Hashed by the model cast
            'status' => User::STATUS_ACTIVE,
        ]);

        event(new Registered($user));

        $token = $user->createToken(
            config('sada.token.name', 'api-token')
        )->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
