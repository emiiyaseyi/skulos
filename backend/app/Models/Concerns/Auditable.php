<?php

namespace App\Models\Concerns;

use App\Services\Auditing\Recorder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Drop-in audit logging for any model. Records created / updated /
 * deleted / force_deleted / restored with old/new values into the
 * model's own database (platform or tenant).
 *
 * Hidden attributes (passwords, tokens) never reach the log; add
 * `protected array $auditExclude = [...]` on a model to exclude more.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(Recorder::class)->recordModel(
                $model,
                'created',
                [],
                $model->auditableValues($model->getAttributes()),
            );
        });

        static::updated(function ($model) {
            $new = $model->auditableValues($model->getChanges());
            unset($new['updated_at']);

            if ($new === []) {
                return;
            }

            $old = array_intersect_key(
                $model->auditableValues($model->getRawOriginal()),
                $new,
            );

            app(Recorder::class)->recordModel($model, 'updated', $old, $new);
        });

        static::deleted(function ($model) {
            $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';

            app(Recorder::class)->recordModel(
                $model,
                $action,
                $model->auditableValues($model->getRawOriginal()),
                [],
            );
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model) {
                app(Recorder::class)->recordModel($model, 'restored');
            });
        }
    }

    /**
     * Strip attributes that must never be written to a log.
     */
    public function auditableValues(array $attributes): array
    {
        $excluded = array_merge(
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }
}
