<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebRtcSession extends Model
{
    use HasFactory;

    protected $table = 'webrtc_sessions';

    protected $fillable = [
        'room_id',
        'host_peer_id',
        'guest_peer_id',
        'status',
        'signals',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'signals'    => 'array',
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }

    /**
     * Push a signal into the queue for a specific target peer.
     */
    public function pushSignal(string $targetPeerId, array $signal): void
    {
        $signals = $this->signals ?? [];
        $signals[$targetPeerId][] = $signal;
        $this->update(['signals' => $signals]);
    }

    /**
     * Drain signals for a specific peer and return them.
     */
    public function drainSignals(string $peerId): array
    {
        $signals = $this->signals ?? [];
        $pending = $signals[$peerId] ?? [];
        unset($signals[$peerId]);
        $this->update(['signals' => $signals]);
        return $pending;
    }
}
