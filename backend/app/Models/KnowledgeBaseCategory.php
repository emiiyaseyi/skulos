<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBaseCategory extends Model
{
    use Auditable;

    protected $fillable = ['name', 'slug', 'sequence'];

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeBaseArticle::class);
    }
}
