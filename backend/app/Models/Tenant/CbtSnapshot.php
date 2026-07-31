<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtSnapshot extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'type', 'path', 'captured_at'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
