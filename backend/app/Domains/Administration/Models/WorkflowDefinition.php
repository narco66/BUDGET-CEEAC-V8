<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'module', 'label'])]
class WorkflowDefinition extends Model
{
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function activeVersion(): ?WorkflowVersion
    {
        return $this->versions()->where('status', 'actif')->latest('version')->first();
    }
}
