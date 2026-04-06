<?php
use Illuminate\Support\Facades\Broadcast;
use App\Models\SupportThread;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Support thread channel — user must own the thread or be admin
Broadcast::channel('support.thread.{threadId}', function ($user, $threadId) {
    if ($user->role === 'admin') return true;
    $thread = SupportThread::find($threadId);
    return $thread && $thread->user_id === $user->id;
});