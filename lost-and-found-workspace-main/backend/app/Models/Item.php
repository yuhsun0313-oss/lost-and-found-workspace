<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'title',
        'category',
        'description',
        'location',
        'publisher_email',
        'date',
        'type',
        'image',
        'claimant_email',
        'vlm_fail_count',
        'review_status',
        'manual_review_deadline',
        'claim_proof_image',
        'vlm_last_reason',
    ];

    protected $attributes = [
        'vlm_fail_count' => 0,
        'review_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'vlm_fail_count' => 'integer',
            'manual_review_deadline' => 'datetime',
        ];
    }
}
