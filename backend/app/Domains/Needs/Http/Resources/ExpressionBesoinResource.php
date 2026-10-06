<?php

namespace App\Domains\Needs\Http\Resources;

use App\Domains\Administration\Services\ReferentialReader;
use App\Domains\Budget\Enums\BudgetNature;
use App\Domains\Needs\Models\ExpressionBesoin;
use App\Domains\Needs\Services\ExpressionBesoinWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExpressionBesoin */
class ExpressionBesoinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workflow = app(ExpressionBesoinWorkflow::class);
        $enrichment = $this->budgetLine?->enrichment;
        $completeness = $this->nature === BudgetNature::Pap && $enrichment
            ? $enrichment->completeness()
            : null;

        $disponible = $this->budgetLine?->disponible($this->id);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'objet' => $this->objet,
            'contexte' => $this->contexte,
            'justification' => $this->justification,
            'urgence' => $this->urgence,
            'priorite' => $this->priorite,
            'resultats_attendus' => $this->resultats_attendus,
            'nature' => $this->nature?->value,
            'nature_libelle' => $this->nature?->label(),
            'statut' => $this->status?->value,
            'statut_libelle' => $this->status?->label(),
            'etape' => $this->workflow_step,
            'acteur_attendu' => $this->expected_actor_label,
            'anomalie_acteur' => $workflow->anomalieActeur($this->resource),
            'echeance' => $this->due_on?->toDateString(),
            'delai_libelle' => $workflow->deadlineLabel($this->resource),
            'en_retard' => $workflow->isLate($this->resource),
            'montant' => $this->montant,
            'exercice' => $this->exercice?->annee,
            'structure' => $this->organizationUnit?->structureLabel(),
            'structure_id' => $this->organization_unit_id,
            'initiateur' => $this->initiator?->name,
            'ligne' => [
                'id' => $this->budgetLine?->id,
                'code' => $this->budgetLine?->code,
                'libelle' => $this->budgetLine?->label,
                'montant_vote' => $this->budgetLine?->montant_vote,
                'ajustements' => $this->budgetLine?->ajustements,
                'actualise' => $this->budgetLine?->montantActualise(),
                'engage' => $this->when($this->relationLoaded('lines'), fn () => $this->budgetLine?->montantEngage()),
                'disponible' => $disponible,
                'solde_previsionnel' => $disponible !== null ? $disponible - (int) $this->montant : null,
                'chapitre' => $this->budgetLine?->chapitre,
                'article' => $this->budgetLine?->article,
                'paragraphe' => $this->budgetLine?->paragraphe,
                'nature_depense' => $this->budgetLine?->nature_depense,
            ],
            'completude' => $completeness['score'] ?? null,
            'completude_elements' => $completeness['elements'] ?? [],
            'referentiel' => $this->when($this->relationLoaded('lines'), fn () => $enrichment ? [
                'statut' => $enrichment->status,
                'pilier' => $enrichment->pilier,
                'axe' => $enrichment->axe,
                'produit' => $enrichment->produit,
                'activite' => $enrichment->activite,
                'resultats_attendus' => $enrichment->resultats_attendus,
                'indicateur' => $enrichment->indicateur,
                'cible' => $enrichment->cible,
                'periode' => $enrichment->periode,
                'beneficiaires' => $enrichment->beneficiaires,
                'unite_responsable' => $enrichment->unite_responsable,
                'taches' => $enrichment->tasks->map(fn ($task) => [
                    'id' => $task->id,
                    'libelle' => $task->label,
                    'proposee' => $task->proposed,
                    'validee' => $task->validated,
                ]),
            ] : null),
            'lignes' => EbLineResource::collection($this->whenLoaded('lines')),
            'imputations' => $this->whenLoaded('imputations', fn () => $this->imputations->map(fn ($row) => [
                'budget_line_id' => $row->budget_line_id,
                'code' => $row->budgetLine?->code,
                'libelle' => $row->budgetLine?->label,
                'montant' => $row->montant,
                'disponible' => $row->budgetLine?->disponible($this->id),
            ])),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'nom' => $document->original_name,
                'taille' => $document->size,
                'confidentiel' => $document->confidentialite,
            ])),
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'action' => $event->action,
                'de' => $event->from_status,
                'vers' => $event->to_status,
                'motif' => $event->motif,
                'observations' => $event->observations,
                'champs' => $event->fields,
                'acteur' => $event->actor?->name,
                'fonction' => $event->actor?->function_title,
                'date' => $event->created_at?->format('d/m/Y H:i'),
            ])),
            'engagement' => $this->engagement?->reference,
            'circuit' => $this->when($this->relationLoaded('events'), fn () => $this->circuit($workflow)),
            'actions' => $this->when(
                $request->user() && $this->relationLoaded('events'),
                fn () => $workflow->actionsFor($request->user(), $this->resource),
            ),
            'retour_motif' => $this->return_motif,
            'rejet_motif' => $this->rejection_motif,
            'types_pieces' => app(ReferentialReader::class)->documentLabels('expression_besoin'),
        ];
    }

    /**
     * @return list<array{etape: string, libelle: string, etat: string}>
     */
    private function circuit(ExpressionBesoinWorkflow $workflow): array
    {
        $steps = $workflow->steps($this->resource);
        $current = array_search($this->workflow_step, $steps, true);
        $closed = in_array($this->status->value, ['transformee_engagement', 'approuvee', 'rejetee', 'annulee'], true);

        return collect($steps)->values()->map(function (string $step, int $index) use ($workflow, $current, $closed) {
            $state = 'a_venir';
            if ($closed && $this->status->value !== 'rejetee' && $this->status->value !== 'annulee') {
                $state = 'fait';
            } elseif ($current === false) {
                $state = 'a_venir';
            } elseif ($index < $current) {
                $state = 'fait';
            } elseif ($index === $current) {
                $state = 'en_cours';
            }

            return [
                'etape' => $step,
                'libelle' => $workflow->stepLabel($step, $this->organizationUnit),
                'etat' => $state,
                'echeance' => $state === 'en_cours' ? $this->due_on?->format('d/m/Y') : null,
            ];
        })->all();
    }
}
