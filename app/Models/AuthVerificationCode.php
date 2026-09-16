<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthVerificationCode extends Model
{
    protected $table = 'auth_verification_codes';

    protected $fillable = [
        'email',
        'code',
        'type',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
