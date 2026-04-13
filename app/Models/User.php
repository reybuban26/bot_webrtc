<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\ChatSession;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Only users with the 'admin' role can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    /**
     * Check if the user has the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Support threads assigned to this admin
     */
    public function assignedThreads(): HasMany
    {
        return $this->hasMany(SupportThread::class, 'assigned_admin_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'dob',
        'phone',
        'avatar',
        'public_key',
    ];

    /** Return a public URL for the avatar, or null if not set. */
    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
        return null;
    }

        // Ito ang mag-ge-generate ng tamang URL (http://127.0.0.1:8000/storage/avatars/filename.gif)
        return asset(Storage::url($this->avatar));
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dob'      => 'date:Y-m-d',
        ];
    }
}
