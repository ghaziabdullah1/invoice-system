<?php

namespace App\Http\Controllers\Admin;

use App\Traits\ResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminPermission;
use App\Models\OtpLog;
use App\Constants\Constants;
use App\Repository\Admin\Branch\BranchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Mail\OtpMail;
use App\Http\Requests\Admin\Auth\SendOtpRequest;
use App\Http\Requests\Admin\Auth\VerifyOtpRequest;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\ForgotPasswordRequest;
use App\Http\Requests\Admin\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    use ResponseTrait;

    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES   = 15;
    private const OTP_COOLDOWN_SECONDS = 120;
    private const OTP_EXPIRY_MINUTES = 10;

    public function __construct(
        private BranchRepository $branchRepository
    ) {}

    public function login(LoginRequest $request)
    {
        try {
            $validated = $request->validated();
            $ip    = $request->ip();
            $email = strtolower(trim($validated['email']));

            $lockKey = 'login_lockout_' . hash('sha256', $ip . '|' . $email);
            if (Cache::has($lockKey)) {
                $remaining = Cache::get($lockKey . '_remaining', self::LOCKOUT_MINUTES);
                return $this->failureResponse(
                    "Too many login attempts. Please try again in {$remaining} minutes.",
                    null,
                    Constants::RESPONSE_TOO_MANY_REQUESTS
                );
            }

            $user = User::with(['adminGroup.permissions'])
                ->where('email', $email)
                ->first();

            $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            $passwordValid = $user ? Hash::check($validated['password'], $user->password) : Hash::check('dummy', $dummyHash);

            if ($user && !$passwordValid) {
                $passwordValid = $this->matchesLegacySanitizedPassword($user, $validated['password']);
            }

            if (!$user || !$passwordValid) {
                $this->incrementFailedAttempts($ip, $email);
                return $this->failureResponse(
                    __('messages.invalid_credentials'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if (!$user->is_active) {
                return $this->failureResponse(
                    __('messages.inactive_account'),
                    null,
                    Constants::RESPONSE_FORBIDDEN
                );
            }

            $this->clearFailedAttempts($ip, $email);

            $otpError = $this->attemptSendOtp($user, $request);

            if ($otpError) {
                return $this->failureResponse($otpError, null, Constants::RESPONSE_TOO_MANY_REQUESTS);
            }

            return $this->successResponse(
                'Login credentials verified. An OTP has been sent to your email.',
                [
                    'requires_otp' => true,
                    'email'        => $user->email,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Login error', [
                'ip'    => $request->ip(),
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ]);
            return $this->failureResponse(
                __('messages.login_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    /**
     * كلمات المرور كانت تُنظَّف قبل التجزئة (strip_tags + htmlspecialchars) بسبب SanitizeInputMiddleware.
     * نقبل الصيغة القديمة لمن اختاروا رموزاً خاصة، ثم نعيد تجزئتها بالكلمة الأصلية فيُشفى حسابهم.
     */
    private function matchesLegacySanitizedPassword(User $user, string $password): bool
    {
        $legacy = htmlspecialchars(strip_tags($password), ENT_QUOTES, 'UTF-8');

        if ($legacy === $password || !Hash::check($legacy, $user->password)) {
            return false;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        return true;
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
            return $this->successResponse(__('messages.logout_success'), null);
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->failureResponse(
                    __('messages.unauthenticated'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if (!$user->is_active) {
                $request->user()->currentAccessToken()->delete();
                return $this->failureResponse(
                    __('messages.inactive_account'),
                    null,
                    Constants::RESPONSE_FORBIDDEN
                );
            }

            $user->load(['adminGroup.permissions']);
            $permissions = $this->getUserPermissions($user);
            $is_admin = $user->admin_group_id == Constants::SUPER_ADMIN_GROUP_ID;

            // ✅ جديد: جلب الفروع المسموح بها
            $branches = $this->branchRepository->getUserBranches($user);
            $defaultBranch = $this->branchRepository->getUserDefaultBranch($user);

            return $this->successResponse(
                __('messages.user_fetched'),
                [
                    'user'           => $this->formatUser($user, $is_admin),
                    'permissions'    => $permissions,
                    'is_admin'       => $is_admin,
                    'branches'       => $branches,
                    'default_branch' => $defaultBranch?->id,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Me endpoint error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function refresh(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->failureResponse(
                    __('messages.unauthenticated'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if (!$user->is_active) {
                $request->user()->currentAccessToken()->delete();
                return $this->failureResponse(
                    __('messages.inactive_account'),
                    null,
                    Constants::RESPONSE_FORBIDDEN
                );
            }

            $request->user()->currentAccessToken()->delete();
            $token = $user->createToken('invoice-system-token-' . Str::random(8))->plainTextToken;

            return $this->successResponse(
                __('messages.token_refreshed'),
                ['token' => $token, 'token_type' => 'Bearer']
            );
        } catch (\Exception $e) {
            Log::error('Refresh token error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $email = strtolower(trim($request->email));
            $user = User::where('email', $email)->first();

            if ($user && $user->is_active) {
                $token = Str::random(64);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $user->email],
                    [
                        'token'      => Hash::make($token),
                        'created_at' => now(),
                    ]
                );

                Log::info('Password reset token created', [
                    'user_id' => $user->id,
                    'ip'      => $request->ip(),
                ]);
            }

            return $this->successResponse(
                'إذا كان البريد الإلكتروني مسجلاً، ستصلك رسالة لإعادة تعيين كلمة المرور.',
                null
            );
        } catch (\Exception $e) {
            Log::error('Forgot password error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $validated = $request->validated();
            $email = strtolower(trim($validated['email']));

            $user = User::where('email', $email)->first();

            if (!$user) {
                return $this->failureResponse(
                    __('messages.invalid_token'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            $resetRecord = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$resetRecord || !Hash::check($validated['token'], $resetRecord->token)) {
                return $this->failureResponse(
                    __('messages.invalid_token'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if (now()->diffInMinutes($resetRecord->created_at) > 60) {
                return $this->failureResponse(
                    __('messages.token_expired'),
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            $user->update([
                'password' => Hash::make($validated['password']),
            ]);

            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $user->tokens()->delete();

            return $this->successResponse(__('messages.password_changed'), null);
        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        try {
            $email = strtolower(trim($request->email));
            $user = User::where('email', $email)->first();

            if (!$user || !$user->is_active) {
                return $this->successResponse(
                    'إذا كان البريد الإلكتروني مسجلاً، سيصلك رمز التحقق',
                    null
                );
            }

            $otpError = $this->attemptSendOtp($user, $request);

            if ($otpError) {
                return $this->failureResponse($otpError, null, Constants::RESPONSE_TOO_MANY_REQUESTS);
            }

            return $this->successResponse(
                'إذا كان البريد الإلكتروني مسجلاً، سيصلك رمز التحقق',
                null
            );
        } catch (\Exception $e) {
            Log::error('Send OTP error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $email = strtolower(trim($request->email));
            $user = User::with(['adminGroup.permissions'])->where('email', $email)->first();

            if (!$user) {
                return $this->failureResponse(
                    'رمز التحقق غير صحيح',
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if (!$user->is_active) {
                return $this->failureResponse(
                    __('messages.inactive_account'),
                    null,
                    Constants::RESPONSE_FORBIDDEN
                );
            }

            if (!$user->otp_created_at || abs($user->otp_created_at->diffInSeconds(now())) > (self::OTP_EXPIRY_MINUTES * 60)) {
                $user->update([
                    'otp'          => null,
                    'otp_attempts' => 0,
                ]);

                return $this->failureResponse(
                    'انتهت صلاحية الرمز، اطلب رمزاً جديداً',
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            if ($user->otp_attempts >= self::MAX_LOGIN_ATTEMPTS) {
                return $this->failureResponse(
                    'تم تجاوز الحد المسموح من المحاولات، اطلب رمزاً جديداً',
                    null,
                    Constants::RESPONSE_TOO_MANY_REQUESTS
                );
            }

            if (!$user->otp || !Hash::check($request->otp, $user->otp)) {
                $user->increment('otp_attempts');
                $remaining = self::MAX_LOGIN_ATTEMPTS - $user->fresh()->otp_attempts;

                return $this->failureResponse(
                    "رمز التحقق غير صحيح ({$remaining} محاولات متبقية)",
                    null,
                    Constants::RESPONSE_UNAUTHORIZED
                );
            }

            $user->update([
                'otp'             => null,
                'otp_via'         => null,
                'otp_created_at'  => null,
                'otp_attempts'    => 0,
                'otp_verified_at' => now(),
                'last_login_ip'   => $request->ip(),
                'last_login_at'   => now(),
            ]);

            $token = $user->createToken('invoice-system-token-' . Str::random(8))->plainTextToken;

            $permissions = $this->getUserPermissions($user);
            $is_admin = $user->admin_group_id == Constants::SUPER_ADMIN_GROUP_ID;

            // ✅ جديد: جلب الفروع المسموح بها
            $branches = $this->branchRepository->getUserBranches($user);
            $defaultBranch = $this->branchRepository->getUserDefaultBranch($user);

            OtpLog::where('user_id', $user->id)
                ->where('status', 'sent')
                ->whereNull('verified_at')
                ->latest()
                ->first()
                ?->update(['status' => 'verified', 'verified_at' => now()]);

            return $this->successResponse(
                __('messages.login_success'),
                [
                    'user'           => $this->formatUser($user, $is_admin),
                    'token'          => $token,
                    'token_type'     => 'Bearer',
                    'permissions'    => $permissions,
                    'is_admin'       => $is_admin,
                    'branches'       => $branches,
                    'default_branch' => $defaultBranch?->id,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Verify OTP error: ' . $e->getMessage());
            return $this->failureResponse(
                __('messages.operation_failed'),
                null,
                Constants::RESPONSE_SERVER_ERROR
            );
        }
    }

    private function attemptSendOtp(User $user, Request $request): ?string
    {
        if ($user->otp_created_at && abs($user->otp_created_at->diffInSeconds(now())) < self::OTP_COOLDOWN_SECONDS) {
            $remaining = self::OTP_COOLDOWN_SECONDS - abs($user->otp_created_at->diffInSeconds(now()));
            $minutes = ceil($remaining / 60);
            return "{$minutes} minutes remaining before you can request a new OTP.";
        }

        $plainOtp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
            'otp'            => Hash::make($plainOtp),
            'otp_via'        => 'email',
            'otp_created_at' => now(),
            'otp_attempts'   => 0,
        ]);

        OtpLog::create([
            'user_id'    => $user->id,
            'email'      => $user->email,
            'status'     => 'sent',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpMail($plainOtp));

        return null;
    }

    private function incrementFailedAttempts(string $ip, string $email): void
    {
        $attemptsKey = 'login_attempts_' . hash('sha256', $ip . '|' . $email);
        $lockKey     = 'login_lockout_' . hash('sha256', $ip . '|' . $email);

        try {
            $attempts = Cache::increment($attemptsKey);

            if ($attempts === 1) {
                Cache::put($attemptsKey, 1, now()->addMinutes(self::LOCKOUT_MINUTES));
            }

            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                Cache::put($lockKey, true, now()->addMinutes(self::LOCKOUT_MINUTES));
                Cache::put($lockKey . '_remaining', self::LOCKOUT_MINUTES, now()->addMinutes(self::LOCKOUT_MINUTES));
                Cache::forget($attemptsKey);

                Log::warning('Account locked', ['ip' => $ip, 'attempts' => $attempts]);
            }
        } catch (\Exception $e) {
            $attempts = Cache::get($attemptsKey, 0) + 1;
            Cache::put($attemptsKey, $attempts, now()->addMinutes(self::LOCKOUT_MINUTES));

            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                Cache::put($lockKey, true, now()->addMinutes(self::LOCKOUT_MINUTES));
                Cache::forget($attemptsKey);
            }
        }
    }

    private function clearFailedAttempts(string $ip, string $email): void
    {
        $attemptsKey = 'login_attempts_' . hash('sha256', $ip . '|' . $email);
        $lockKey     = 'login_lockout_' . hash('sha256', $ip . '|' . $email);

        Cache::forget($attemptsKey);
        Cache::forget($lockKey);
        Cache::forget($lockKey . '_remaining');
    }

    private function formatUser(User $user, bool $is_admin): array
    {
        $adminGroup = null;
        if ($user->adminGroup) {
            $adminGroup = [
                'id'          => $user->adminGroup->id,
                'title_en'    => $user->adminGroup->title_en,
                'title_ar'    => $user->adminGroup->title_ar,
                'description' => $user->adminGroup->description,
                'is_active'   => $user->adminGroup->is_active,
            ];
        }

        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'company_name'   => $user->company_name,
            'tax_number'     => $user->tax_number,
            'address'        => $user->address,
            'admin_group_id' => $user->admin_group_id,
            'is_active'      => $user->is_active,
            'is_admin'       => $is_admin,
            'img_url'        => $user->img_url,
            'last_login_at'  => $user->last_login_at?->toDateTimeString(),
            'adminGroup'     => $adminGroup,
        ];
    }

    private function getUserPermissions(User $user): array
    {
        try {
            if (!$user->is_active) {
                return [];
            }

            if ($user->admin_group_id == Constants::SUPER_ADMIN_GROUP_ID) {
                return AdminPermission::where('is_active', true)->pluck('title')->toArray();
            }

            if ($user->adminGroup && $user->adminGroup->permissions) {
                return $user->adminGroup->permissions
                    ->where('is_active', true)
                    ->pluck('title')
                    ->toArray();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('Get permissions error: ' . $e->getMessage());
            return [];
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if ($user->admin_group_id == Constants::SUPER_ADMIN_GROUP_ID) {
            return true;
        }

        if ($user->adminGroup && $user->adminGroup->permissions) {
            return $user->adminGroup->permissions
                ->where('is_active', true)
                ->where('title', $permission)
                ->isNotEmpty();
        }

        return false;
    }
}
