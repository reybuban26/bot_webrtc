<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportThread extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'encrypted_keys',
        'chat_status',
        'assigned_admin_id',
        'ai_confidence',
        'requires_escalation',
        'queue_position',
        'metadata',
        'resolution_status',
        'is_resolved_by_user',
        'feedback_rating',
        'feedback_comment',
    ];

    protected $casts = [
        'encrypted_keys'      => 'array',
        'ai_confidence'       => 'decimal:2',
        'requires_escalation' => 'boolean',
        'metadata'            => 'array',
        'is_resolved_by_user' => 'boolean',
        'feedback_rating'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
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
            ['title' => 'Support', 'chat_status' => 'waiting']
        );
    }

    /**
     * Pick the first available admin user for assignment.
     */
    public static function nextAvailableAdmin(): ?User
    {
        return User::where('role', 'admin')->first();
    }

    /**
     * Scope: Find threads queued for escalation, ordered by queue position
     */
    public function scopeQueuedForEscalation($query)
    {
        return $query->where('chat_status', 'escalating')->orderBy('queue_position');
    }

    /**
     * Check if thread is currently being handled by AI
     */
    public function isAiActive(): bool
    {
        return $this->chat_status === 'ai_active';
    }

    /**
     * Check if escalation was requested
     */
    public function hasEscalationRequested(): bool
    {
        return $this->requires_escalation;
    }
}
