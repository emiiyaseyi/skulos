<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "forgot password" request (user directive 2026-07-13): a not-yet
 * authenticated user picks their own new password, but it is held,
 * already hashed, until a school admin approves it — the account's OLD
 * password keeps working until then. Deliberately not folded into
 * ProfileChangeRequest, that model's `requested`/`original` are a JSON
 * diff of plain field values, storing even a hashed password alongside
 * plaintext name/email fields is a needless mixing of a sensitive
 * secret into a general-purpose diff blob.
 */
class PasswordResetRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [
        'user_id', 'password_hash', 'status', 'reviewed_by_user_id', 'reviewed_at', 'notes',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * Built manually, not a plain belongsTo(): see the matching comment
     * on Staff::user(), a bare belongsTo() would inherit this model's
     * tenant connection onto User (a platform model).
     */
    public function applicant(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'user_id', 'id', 'applicant');
    }
}
