<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Password reset link points to the frontend app
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('sada.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });

        // Email verification link points to the API signed route
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $verifyUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );

            return config('sada.frontend_url') . '/verify-email?url=' . urlencode($verifyUrl);
        });

        // Gate Check Definition
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if ($user->hasPermission($ability)) {
                return true;
            }
        });
    }
}
