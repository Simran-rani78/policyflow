<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'status',
        'provider_reference',
        'policy_number',
        'attempt_count',
        'last_response',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'sum_insured' => 'integer',
        'premium' => 'decimal:2',
        'attempt_count' => 'integer',
    ];
}
