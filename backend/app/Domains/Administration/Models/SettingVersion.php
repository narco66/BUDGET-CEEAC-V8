<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['system_setting_id', 'before', 'after', 'actor_id', 'motif'])]
class SettingVersion extends Model {}
