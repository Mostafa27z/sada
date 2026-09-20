<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'disk',
        'size_bytes',
        'status',
        'triggered_by',
    ];
}
