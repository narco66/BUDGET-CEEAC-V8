<?php

namespace App\Domains\Ged\Models;

use Illuminate\Database\Eloquent\Model;

class GedLink extends Model
{
    protected $fillable = ['ged_document_id', 'entity_type', 'entity_id', 'relation', 'created_by'];
}
