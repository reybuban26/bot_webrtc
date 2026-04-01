<?php

namespace App\Models;

use App\Scopes\UserChatScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class ChatSession extends Model
{
    use HasFactory;

    /**
     * Boot the model — register the global user scope.
     * This ensures every query is automatically scoped to Auth::id().
     * Use ->withoutGlobalScope(UserChatScope::class) in admin contexts.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new UserChatScope());

        // Automatically assign user_id when creating a session
        static::creating(function (self $session) {
            if (empty($session->user_id) && Auth::check()) {
                $session->user_id = Auth::id();
            }
        });
    }

    protected $fillable = [
        'user_id',
        'session_token',
        'title',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata'  => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Explicit named scope for admin/Filament contexts
     * where the global scope has been removed.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Quick ownership check without a policy.
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function getMessageCountAttribute(): int
    {
        return $this->messages()->count();
    }

    /**
     * Build the conversation history array for Qwen API consumption.
     */
    public function getConversationHistory(int $limit = 20): array
    {
        return $this->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();
    }
}
