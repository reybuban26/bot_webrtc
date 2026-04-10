<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'sender_id',
        'body',
        'type',
        'metadata',
        'is_read',
        'is_encrypted',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'is_encrypted' => 'boolean',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(SupportThread::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Create a system-generated message (no human sender).
     * System messages are always marked as read and never encrypted.
     */
    public static function createSystem(int $threadId, string $body): self
    {
        return self::create([
            'thread_id'    => $threadId,
            'sender_id'    => null,
            'body'         => $body,
            'type'         => 'system',
            'is_read'      => true,
            'is_encrypted' => false,
        ]);
    }
}
