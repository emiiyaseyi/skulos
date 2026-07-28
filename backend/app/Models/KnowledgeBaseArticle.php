<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeBaseArticle extends Model
{
    use Auditable;

    protected $fillable = ['knowledge_base_category_id', 'title', 'slug', 'body', 'steps', 'start_route', 'hidden_for_family_scoped', 'hidden_for_teacher_scoped', 'sequence', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'steps' => 'array', 'hidden_for_family_scoped' => 'boolean', 'hidden_for_teacher_scoped' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseCategory::class, 'knowledge_base_category_id');
    }
}
