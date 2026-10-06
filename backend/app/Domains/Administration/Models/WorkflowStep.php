<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workflow_version_id', 'ordre', 'code', 'label', 'actor_role'])]
class WorkflowStep extends Model {}
