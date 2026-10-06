<?php

namespace App\Domains\PAP\Models;

use App\Domains\Budget\Models\BudgetLine;
use App\Domains\Monitoring\Models\SeMilestone;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'responsible_user_id',
    'actual_start',
    'actual_end',
    'se_status',
    'se_status_motif',
    'budget_line_id',
    'status',
    'pilier',
    'axe',
    'objectif_general',
    'objectif_specifique',
    'produit',
    'sous_produit',
    'activite',
    'sous_activite',
    'resultats_attendus',
    'indicateur',
    'unite_mesure',
    'valeur_reference',
    'cible',
    'source_verification',
    'unite_responsable',
    'beneficiaires',
    'localisation',
    'periode',
    'date_debut',
    'date_fin',
    'livrables',
    'risques',
    'observations',
])]
class PapEnrichment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
        ];
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(SeMilestone::class)->orderBy('position')->orderBy('planned_on');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PapTask::class)->orderBy('position');
    }

    /**
     * @return array{score: int, elements: list<array{cle: string, libelle: string, renseigne: bool}>}
     */
    public function completeness(): array
    {
        $tasksOk = $this->relationLoaded('tasks')
            ? $this->tasks->contains(fn (PapTask $task) => $task->validated)
            : $this->tasks()->where('validated', true)->exists();

        $elements = [
            ['cle' => 'pilier', 'libelle' => 'Pilier', 'renseigne' => filled($this->pilier)],
            ['cle' => 'axe', 'libelle' => 'Axe stratégique', 'renseigne' => filled($this->axe)],
            ['cle' => 'produit', 'libelle' => 'Produit', 'renseigne' => filled($this->produit)],
            ['cle' => 'activite', 'libelle' => 'Activité', 'renseigne' => filled($this->activite)],
            ['cle' => 'taches', 'libelle' => 'Tâches', 'renseigne' => $tasksOk],
            ['cle' => 'resultats', 'libelle' => 'Résultats attendus', 'renseigne' => filled($this->resultats_attendus)],
            ['cle' => 'indicateur', 'libelle' => 'Indicateur', 'renseigne' => filled($this->indicateur)],
            ['cle' => 'cible', 'libelle' => 'Cible', 'renseigne' => filled($this->cible)],
            ['cle' => 'periode', 'libelle' => 'Période', 'renseigne' => filled($this->periode)],
            ['cle' => 'beneficiaires', 'libelle' => 'Bénéficiaires', 'renseigne' => filled($this->beneficiaires)],
        ];

        $filled = collect($elements)->where('renseigne', true)->count();

        return [
            'score' => (int) round($filled / count($elements) * 100),
            'elements' => $elements,
        ];
    }
}
