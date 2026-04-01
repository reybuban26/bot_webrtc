<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Mail\ResetPasswordOtpMail;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Handle Forgot Password: Generate OTP and send email.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Return success even if not found to prevent email enumeration attacks
            return response()->json(['message' => 'If your email is registered, you will receive an OTP shortly.']);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_otps')->updateOrInsert(
            ['email' => $user->email],
            [
                'otp' => $otp,
                'expires_at' => Carbon::now()->addMinutes(15),
                'created_at' => Carbon::now()
            ]
        );

        try {
            Mail::to($user->email)->send(new ResetPasswordOtpMail($otp));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send email. Please try again later.'], 500);
        }

        return response()->json(['message' => 'If your email is registered, you will receive an OTP shortly.']);
    }

    /**
     * Verify the provided OTP.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ]);

        $record = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record || Carbon::parse($record->expires_at)->isPast()) {
            return response()->json(['error' => 'Invalid or expired OTP.'], 400);
        }

        return response()->json(['message' => 'OTP verified successfully. You may now reset your password.']);
    }

    /**
     * Reset the password using the OTP.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $record = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record || Carbon::parse($record->expires_at)->isPast()) {
            return response()->json(['error' => 'Invalid or expired OTP.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if ($user) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        // Delete the extremely sensitive OTP so it can't be reused
        DB::table('password_reset_otps')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Show the login form.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('chat');
        }
        return view('auth.login');
    }

    /**
     * Handle login form submission.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $emailIpKey = \Illuminate\Support\Str::lower($request->input('email')) . '|' . $request->ip();
        $throttleKey = 'login_attempts_' . $emailIpKey;
        $lockoutKey  = 'login_lockout_' . $emailIpKey;

        // 1. Check if user is already locked out
        if (\Illuminate\Support\Facades\Cache::has($lockoutKey)) {
            $expireTime = \Illuminate\Support\Facades\Cache::get($lockoutKey);
            $secondsLeft = max(0, $expireTime - time());
            
            $hours = floor($secondsLeft / 3600);
            $minutes = floor(($secondsLeft / 60) % 60);
            
            $timeStr = '';
            if ($hours > 0) $timeStr .= "{$hours}h ";
            $timeStr .= "{$minutes}m";

            throw ValidationException::withMessages([
                'email' => "Account locked due to too many failed attempts. Please try again in {$timeStr}.",
            ]);
        }

        // 2. Attempt Authentication
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Ensure the key exists (initialize to 0 if missing) before incrementing
            // This is required for the 'database' cache driver.
            \Illuminate\Support\Facades\Cache::add($throttleKey, 0, 86400);
            
            // Increment failed attempts
            $attempts = \Illuminate\Support\Facades\Cache::increment($throttleKey);

            // 3. Trigger 24-hour lock if they hit 5 fails
            if ($attempts >= 5) {
                \Illuminate\Support\Facades\Cache::put($lockoutKey, time() + 86400, 86400);
                \Illuminate\Support\Facades\Cache::forget($throttleKey); // reset attempts for tomorrow
                
                throw ValidationException::withMessages([
                    'email' => "Account locked due to 5 consecutive failed attempts. Please try again in 24 hours.",
                ]);
            }

            $attemptsLeft = 5 - $attempts;
            throw ValidationException::withMessages([
                'email' => "Invalid email or password. You have {$attemptsLeft} attempt(s) remaining.",
            ]);
        }

        // 4. Clear bad attempts on successful login
        \Illuminate\Support\Facades\Cache::forget($throttleKey);
        \Illuminate\Support\Facades\Cache::forget($lockoutKey);

        $request->session()->regenerate();

        // Use intended() but fall back to /chat if the saved URL is an API route.
        // This prevents JS polling calls (e.g. /api/call/pending) from being
        // saved as the "intended" destination and redirecting here after login.
        $intended = $request->session()->pull('url.intended', route('chat'));
        if (str_starts_with(parse_url($intended, PHP_URL_PATH), '/api/')) {
            $intended = route('chat');
        }

        return redirect($intended);
    }

    /**
     * Show the registration form.
     */
    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('chat');
        }
        return view('auth.register');
    }

    /**
     * Handle registration form submission.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users',
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
            'phone'    => 'required|string|max:30',
            'dob'      => 'required|date',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone'    => $validated['phone'],
            'dob'      => $validated['dob'],
            'role'     => 'user', // All self-registrations default to 'user'
        ]);

        event(new \Illuminate\Auth\Events\Registered($user));

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('just_registered', true);

        return redirect()->route('chat');
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
