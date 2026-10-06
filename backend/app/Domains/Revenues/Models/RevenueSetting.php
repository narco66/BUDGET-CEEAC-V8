<?php

namespace App\Domains\Revenues\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class RevenueSetting extends Model
{
    public static function int(string $key, int $default): int
    {
        $value = static::query()->where('key', $key)->value('value');

        return is_numeric($value) ? (int) $value : $default;
    }
}
