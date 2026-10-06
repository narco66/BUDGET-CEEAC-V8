<?php

namespace App\Domains\Planning\Models;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Organization\Models\OrganizationUnit;
use App\Domains\PAP\Models\PapEnrichment;
use App\Domains\PAP\Models\PapTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'gar_version_id', 'parent_id', 'type', 'code', 'libelle', 'position', 'description',
    'objectifs', 'resultats_attendus', 'organization_unit_id', 'unite_responsable', 'periode',
    'date_debut', 'date_fin', 'indicateur', 'unite_mesure', 'cible', 'enveloppe', 'statut',
    'pap_enrichment_id', 'pap_task_id', 'budget_line_id',
])]
class GarNode extends Model
{
    public const TYPES = ['pilier', 'axe', 'produit', 'sous_produit', 'activite', 'tache'];

    public const ARCHIVE = 'archive';

    /**
     * @var array<string, string|list<string>|null>
     */
    public const PARENTS = [
        'pilier' => null,
        'axe' => 'pilier',
        'produit' => 'axe',
        'sous_produit' => 'produit',
        'activite' => ['produit', 'sous_produit'],
        'tache' => 'activite',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enveloppe' => 'integer',
            'date_debut' => 'date',
            'date_fin' => 'date',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GarVersion::class, 'gar_version_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationUnit::class, 'gar_node_units')->withTimestamps();
    }

    public function enrichment(): BelongsTo
    {
        return $this->belongsTo(PapEnrichment::class, 'pap_enrichment_id');
    }

    public function papTask(): BelongsTo
    {
        return $this->belongsTo(PapTask::class, 'pap_task_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }
}
