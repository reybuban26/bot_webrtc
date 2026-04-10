<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\WebRtcController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PushController;
use Illuminate\Http\Request;

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::get('/login', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ── Registration ──────────────────────────────────────────────────────────────
Route::get('/register', [AuthController::class, 'showRegister'])->name('register')->middleware('guest');
Route::post('/register', [AuthController::class, 'register'])->name('register.post')->middleware('guest');

// ── Forgot Password (API) ─────────────────────────────────────────────────────
Route::get('/forgot-password', function () {
    return view('auth.forgot-password');
})->name('password.request')->middleware('guest');
Route::post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email')->middleware('guest');
Route::post('/api/auth/verify-otp', [AuthController::class, 'verifyOtp'])->name('password.verify')->middleware('guest');
Route::post('/api/auth/reset-password', [AuthController::class, 'resetPassword'])->name('password.update')->middleware('guest');

// ── Email Verification ────────────────────────────────────────────────────────
Route::get('/email/verify', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = \App\Models\User::findOrFail($id);

    // Security check: I-check kung tama ba yung hash para sa email na 'to
    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        return redirect()->route('login')->withErrors(['email' => 'Invalid verification link.']);
    }

    // I-verify ang user kapag hindi pa verified
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new \Illuminate\Auth\Events\Verified($user));
    }

    // Force Auto-login para diretso pasok
    \Illuminate\Support\Facades\Auth::login($user);

    // Ipasa sa chat dashboard!
    return redirect('/chat')->with('message', 'Email verified successfully!');
})->name('verification.verify');

Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return back()->with('message', 'Verification link sent!');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

// ── Chat UI (auth required, verified) ─────────────────────────────────────────
Route::get('/', [ChatController::class, 'index'])->name('chat.index')->middleware(['auth', 'verified']);
Route::get('/chat', [ChatController::class, 'index'])->name('chat')->middleware(['auth', 'verified']);

// ── Chat API (auth required, verified) ────────────────────────────────────────
Route::prefix('api/chat')->name('chat.')->middleware(['auth', 'verified'])->group(function () {
    Route::post('/send', [ChatController::class, 'sendMessage'])->name('send');
    Route::post('/session/new', [ChatController::class, 'newSession'])->name('session.new');
    Route::get('/session/{token}/history', [ChatController::class, 'history'])->name('history');
    Route::delete('/session/{token}', [ChatController::class, 'deleteSession'])->name('session.delete');
    Route::get('/sessions', [ChatController::class, 'sessions'])->name('sessions');
});


// ── WebRTC Signaling API (auth required, verified) ────────────────────────────
Route::prefix('api/webrtc')->name('webrtc.')->middleware(['auth', 'verified'])->group(function () {
    Route::post('/room/join', [WebRtcController::class, 'joinRoom'])->name('join');
    Route::post('/room/end',  [WebRtcController::class, 'endRoom'])->name('end');
    Route::post('/room',      [WebRtcController::class, 'createRoom'])->name('create');
    Route::post('/signal',    [WebRtcController::class, 'sendSignal'])->name('signal');
    Route::post('/poll',      [WebRtcController::class, 'pollSignals'])->name('poll');
    Route::post('/agora/token', [WebRtcController::class, 'generateAgoraToken'])->name('agora.token');
});

// ── Call Request API (role-based, auth required, verified) ────────────────────
Route::prefix('api/call')->name('call.')->middleware(['auth', 'verified'])->group(function () {
    Route::post('/request',        [CallController::class, 'request'])->name('request');     // user→admin
    Route::post('/call-user',      [CallController::class, 'callUser'])->name('call-user');  // admin→user
    Route::get('/pending',         [CallController::class, 'pending'])->name('pending');     // admin polls
    Route::get('/incoming',        [CallController::class, 'incoming'])->name('incoming');   // user polls for admin-initiated
    Route::post('/respond',        [CallController::class, 'respond'])->name('respond');     // accept/reject (admin-side)
    Route::post('/user-respond',   [CallController::class, 'userRespond'])->name('user-respond'); // accept/reject (user-side)
    Route::get('/status/{callId}', [CallController::class, 'status'])->name('status');      // caller or target polls
    Route::post('/end',            [CallController::class, 'end'])->name('end');             // end call
});

// ── Support Chat API (auth required, verified) ────────────────────────────────
Route::prefix('api/support')->name('support.')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/threads',                         [SupportController::class, 'threads'])->name('threads');
    Route::get('/thread',                          [SupportController::class, 'thread'])->name('thread');
    Route::get('/thread/{threadId}/messages',      [SupportController::class, 'messages'])->name('messages');
    Route::post('/thread/{threadId}/mark-seen',    [SupportController::class, 'markAsSeen'])->name('mark-seen');
    Route::post('/thread/{threadId}/message',      [SupportController::class, 'send'])->name('send');
    Route::post('/thread/{threadId}/meeting',      [SupportController::class, 'saveMeeting'])->name('meeting');
    Route::post('/thread/{threadId}/typing',       [SupportController::class, 'typing'])->name('typing');
    // E2EE key exchange
    Route::get('/thread/{threadId}/keys',          [SupportController::class, 'getThreadKeys'])->name('thread.keys.get');
    Route::put('/thread/{threadId}/keys',          [SupportController::class, 'storeThreadKeys'])->name('thread.keys.store');
});

// ── Profile API (auth required, verified) ─────────────────────────────────────
Route::prefix('api/profile')->name('profile.')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/',         [ProfileController::class, 'show'])->name('show');
    Route::put('/',         [ProfileController::class, 'update'])->name('update');
    Route::post('/avatar',  [ProfileController::class, 'uploadAvatar'])->name('avatar.upload');
    Route::delete('/avatar',[ProfileController::class, 'deleteAvatar'])->name('avatar.delete');
});

// ── E2EE Public Key API (auth required, verified) ─────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('/api/user/public-key',          [ProfileController::class, 'storePublicKey'])->name('user.public-key.store');
    Route::get('/api/user/{userId}/public-key', [ProfileController::class, 'getPublicKey'])->name('user.public-key.get');
});

Route::get('/api/voice/status', [CallController::class, 'voiceStatus'])->name('voice.status');

Route::prefix('api/push')->middleware(['auth', 'verified'])->group(function () {
    Route::post('/subscribe',   [PushController::class, 'subscribe']);
    Route::post('/unsubscribe', [PushController::class, 'unsubscribe']);
    Route::get('/vapid-key',    fn() => response()->json(['key' => config('services.vapid.public_key')]));
});
