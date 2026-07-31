<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtIncident extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'type', 'severity', 'description', 'raised_by_user_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
