<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded visit to a marketing-site page, platform database. See the
 * migration for why this exists and what it deliberately does not try to
 * be (a precise per-second analytics identity).
 */
class MarketingPageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'path', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'session_id', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
