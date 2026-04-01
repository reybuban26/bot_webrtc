<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $fillable = [
        'service',
        'endpoint',
        'method',
        'status_code',
        'request_payload',
        'response_payload',
        'error_message',
        'duration_ms',
        'ip_address',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
