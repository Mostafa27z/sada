<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    use ApiResponse;

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    /**
     * Register a new user account.
     */
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], __('messages.registration_successful'), 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Login / Logout
    |--------------------------------------------------------------------------
    */

    /**
     * Authenticate a user and return a token.
     */
    public function login(LoginRequest $request, LoginUser $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], __('messages.login_successful'));
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request, LogoutUser $action): JsonResponse
    {
        $action->execute($request->user());

        return $this->success(null, __('messages.logout_successful'));
    }

    /**
     * Revoke all access tokens for the user.
     */
    public function logoutAll(Request $request, LogoutUser $action): JsonResponse
    {
        $action->executeAll($request->user());

        return $this->success(null, __('messages.logout_all_successful'));
    }

    /*
    |--------------------------------------------------------------------------
    | Password Reset
    |--------------------------------------------------------------------------
    */

    /**
     * Send a password reset link to the given email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->success(null, __('messages.password_reset_link_sent'));
        }

        return $this->error(__($status), 400);
    }

    /**
     * Reset the user's password using a valid token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => $password,
                ])->save();

                // Revoke all existing tokens for security
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, __('messages.password_reset_successful'));
        }

        return $this->error(__($status), 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Verification
    |--------------------------------------------------------------------------
    */

    /**
     * Mark the user's email as verified.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== (int) $id) {
            return $this->error(__('messages.forbidden'), 403);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, __('messages.email_already_verified'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->success(null, __('messages.email_verified'));
    }

    /**
     * Resend the email verification notification.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->success(null, __('messages.email_already_verified'));
        }

        $request->user()->sendEmailVerificationNotification();

        return $this->success(null, __('messages.verification_link_sent'));
    }

    /*
    |--------------------------------------------------------------------------
    | Profile Management
    |--------------------------------------------------------------------------
    */

    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user())
        );
    }

    /**
     * Update the authenticated user's profile.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validated();

        // If email changed, require re-verification
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        $user->fill($data);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->success(
            new UserResource($user->fresh()),
            __('messages.profile_updated')
        );
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        return $this->success(null, __('messages.password_changed'));
    }
}
