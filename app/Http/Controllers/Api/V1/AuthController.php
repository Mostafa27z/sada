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
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthVerificationCode;
use App\Models\User;
use App\Services\TenantService;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    /**
     * Register a new user account and tenant workspace.
     */
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'tenant' => [
                'id' => $result['tenant']->id,
                'name' => $result['tenant']->name,
                'slug' => $result['tenant']->slug,
            ],
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
    | Password Reset & OTP
    |--------------------------------------------------------------------------
    */

    /**
     * Send a 6-digit OTP to the given email for password reset.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            return $this->error('لم نتمكن من العثور على مستخدم بهذا البريد الإلكتروني.', 404);
        }

        // Generate 6-digit OTP code
        $code = sprintf('%06d', random_int(100000, 999999));

        // Invalidate older codes
        AuthVerificationCode::where('email', $email)
            ->where('type', 'password_reset')
            ->delete();

        AuthVerificationCode::create([
            'email' => $email,
            'code' => $code,
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(15),
        ]);

        Log::info("Password reset OTP generated for [{$email}]: {$code}");

        $responsePayload = ['email' => $email];
        if (config('app.debug')) {
            $responsePayload['debug_code'] = $code;
        }

        return $this->success($responsePayload, 'تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح.');
    }

    /**
     * Verify 6-digit OTP code and return an authorized reset token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $code = $request->validated('code');
        $type = $request->validated('type') ?? 'password_reset';

        $record = AuthVerificationCode::where('email', $email)
            ->where('code', $code)
            ->where('type', $type)
            ->where('expires_at', '>=', now())
            ->first();

        if (!$record) {
            return $this->error('رمز التحقق غير صحيح أو انتهت صلاحيته.', 422);
        }

        // Generate a temporary reset token
        $resetToken = Str::random(64);
        $record->update(['token' => $resetToken]);

        return $this->success([
            'email' => $email,
            'token' => $resetToken,
        ], 'تم التحقق من الرمز بنجاح!');
    }

    /**
     * Reset the user's password using a valid token or 6-digit OTP.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $token = $request->validated('token');
        $password = $request->validated('password');

        $user = User::where('email', $email)->first();
        if (!$user) {
            return $this->error('المستخدم غير موجود.', 404);
        }

        // 1. Check in auth_verification_codes by token or direct code
        $validOtp = AuthVerificationCode::where('email', $email)
            ->where('type', 'password_reset')
            ->where(function ($query) use ($token) {
                $query->where('token', $token)->orWhere('code', $token);
            })
            ->where('expires_at', '>=', now()->subMinutes(15))
            ->first();

        if ($validOtp) {
            $user->forceFill([
                'password' => $password,
            ])->save();

            // Revoke all tokens for security
            $user->tokens()->delete();
            $validOtp->delete();

            return $this->success(null, __('messages.password_reset_successful'));
        }

        // 2. Fallback to default Laravel Password broker
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => $password,
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, __('messages.password_reset_successful'));
        }

        return $this->error('رمز إعادة التعيين غير صالح أو منتهي الصلاحية.', 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Verification & Email OTP
    |--------------------------------------------------------------------------
    */

    /**
     * Send email verification OTP code.
     */
    public function sendEmailOtp(Request $request): JsonResponse
    {
        $email = $request->input('email') ?? $request->user()?->email;
        if (!$email) {
            return $this->error('البريد الإلكتروني مطلوب لإرسال رمز التحقق.', 422);
        }

        $code = sprintf('%06d', random_int(100000, 999999));

        AuthVerificationCode::where('email', $email)
            ->where('type', 'email_verification')
            ->delete();

        AuthVerificationCode::create([
            'email' => $email,
            'code' => $code,
            'type' => 'email_verification',
            'expires_at' => now()->addMinutes(15),
        ]);

        Log::info("Email verification OTP for [{$email}]: {$code}");

        $responsePayload = ['email' => $email];
        if (config('app.debug')) {
            $responsePayload['debug_code'] = $code;
        }

        return $this->success($responsePayload, 'تم إرسال رمز التحقق إلى بريدك الإلكتروني بنجاح.');
    }

    /**
     * Verify email using 6-digit OTP code.
     */
    public function verifyEmailOtp(VerifyOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $code = $request->validated('code');

        $record = AuthVerificationCode::where('email', $email)
            ->where('code', $code)
            ->where('type', 'email_verification')
            ->where('expires_at', '>=', now())
            ->first();

        if (!$record) {
            return $this->error('رمز التحقق غير صحيح أو انتهت صلاحيته.', 422);
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $record->delete();

        return $this->success(null, 'تم تأكيد البريد الإلكتروني بنجاح!');
    }

    /**
     * Mark the user's email as verified via Signed URL.
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
        $user = $request->user() ?? User::where('email', $request->input('email'))->first();

        if (!$user) {
            return $this->error('المستخدم غير موجود.', 404);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, __('messages.email_already_verified'));
        }

        $user->sendEmailVerificationNotification();

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
    public function updateProfile(UpdateProfileRequest $request, TenantService $tenantService): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('avatar', $data) && $data['avatar']) {
            $data['avatar'] = $tenantService->storeImage($data['avatar'], 'avatars');
        }

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
