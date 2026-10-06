<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'value', 'critical'])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return ['critical' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SettingVersion::class);
    }
}
