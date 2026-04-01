<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportThread extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id')->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(SupportMessage::class, 'thread_id')->latestOfMany();
    }

    public function unreadMessages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id')
            ->where('is_read', false)
            ->whereHas('sender', fn($q) => $q->where('role', 'user'));
    }

    /**
     * Get or create the support thread for a given user.
     */
    public static function forUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            ['title' => 'Support']
        );
    }
}
