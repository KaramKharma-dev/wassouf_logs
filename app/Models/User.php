<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Support\Facades\Log; // <- مؤقت

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_admin' => 'bool',
        'password' => 'hashed',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        // مؤقت: سجّل للتشخيص في لوج Railway
        Log::info('canAccessPanel', [
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
        ]);

        return (bool) $this->is_admin;
    }
}
