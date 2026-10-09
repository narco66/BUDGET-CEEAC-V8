<?php

namespace App\Domains\Monitoring\Models;

use App\Domains\PAP\Models\PapEnrichment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jalon d’une activité (description S&E §29) : date prévue, date réelle,
 * preuve. Le statut est calculé (franchi, en retard, non renseigné).
 */
class SeMilestone extends Model
{
    protected $table = 'se_milestones';

    /** Colonnes assignables : liste explicite, jamais d’affectation massive ouverte. */
    protected $fillable = [
        'pap_enrichment_id',
        'pap_task_id',
        'position',
        'label',
        'planned_on',
        'achieved_on',
        'proof_label',
        'responsible_label',
    ];

    protected function casts(): array
    {
        return ['planned_on' => 'date', 'achieved_on' => 'date'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->achieved_on !== null => 'franchi',
            $this->planned_on !== null && $this->planned_on->lt(today()) => 'en_retard',
            default => 'non_renseigne',
        };
    }
}
