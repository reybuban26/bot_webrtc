<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;


class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_session_id',
        'role',
        'content',
        'audio_url',
        'tokens_used',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function getContentPreviewAttribute(): string
    {
        return Str::limit($this->content, 100);
    }

    // ── At-Rest Encryption for AI Chat Content ────────────────────────────────

    /**
     * Accessor: transparently decrypt content when reading.
     * Falls back gracefully for existing plaintext rows.
     */
    public function getContentAttribute(string $value): string
    {
        if (empty($value)) {
            return $value;
        }
        // Laravel's encrypted payload always starts with 'eyJ' (base64 of {"iv":...})
        // Use that as a heuristic to detect already-encrypted values.
        if (str_starts_with($value, 'eyJ')) {
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable) {
                // Not actually encrypted (coincidence) — return raw value.
            }
        }
        return $value;
    }

    /**
     * Mutator: transparently encrypt content when writing.
     */
    public function setContentAttribute(string $value): void
    {
        $this->attributes['content'] = Crypt::encryptString($value);
    }
}
