<?php

namespace App\Domains\Commitments\Http\Resources;

use App\Domains\Administration\Services\ReferentialReader;
use App\Domains\Commitments\Enums\EngagementStatus;
use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\EngagementWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Engagement */
class EngagementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workflow = app(EngagementWorkflow::class);
        $eb = $this->expressionBesoin;
        $line = $this->budgetLine;
        $actor = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'eb_reference' => $eb?->reference,
            'expression_besoin_id' => $this->expression_besoin_id,
            'objet' => $eb?->objet,
            'justification' => $eb?->justification,
            'structure' => $eb?->organizationUnit?->structureLabel(),
            'nature' => $eb?->nature?->value,
            'nature_libelle' => $eb?->nature?->label(),
            'ligne' => $line?->code,
            'ligne_libelle' => $line?->label,
            'tiers_id' => $this->tiers_id,
            'gar_version_id' => $this->gar_version_id,
            'beneficiaire' => $this->beneficiary_name,
            'beneficiaire_rccm' => $this->beneficiary_rccm,
            'beneficiaire_nif' => $this->beneficiary_nif,
            'montant' => $this->montant,
            'montant_degage' => (int) $this->montant_degage,
            'engage_net' => $this->montantNet(),
            'annule_le' => $this->cancelled_at?->toDateTimeString(),
            'motif_annulation' => $this->cancellation_motif,
            'degagements' => $this->whenLoaded('degagements', fn () => $this->degagements->map(fn ($row) => [
                'reference' => $row->reference,
                'montant' => $row->montant,
                'engage_net_avant' => $row->engage_net_avant,
                'motif' => $row->motif,
                'acte' => $row->acte,
                'acteur' => $row->actor?->name,
                'le' => $row->created_at?->toDateTimeString(),
            ])->values()),
            'montant_eb' => $eb?->montant,
            'engagement_nature' => $this->nature ?? 'initial',
            'parent_engagement_id' => $this->parent_engagement_id,
            'avenant_motif' => $this->avenant_motif,
            'statut' => $this->status?->value,
            'statut_libelle' => $this->status?->label(),
            'etape' => $this->workflow_step,
            'acteur_attendu' => $this->expected_actor_label,
            'derniere_action' => $this->last_action,
            'echeance' => $this->due_on?->toDateString(),
            'visa' => $this->visa_reference,
            'vise_le' => $this->vised_at?->toDateTimeString(),
            'liquidation' => $this->liquidation_reference,
            'liquidations' => $this->whenLoaded('liquidations', fn () => $this->liquidations->map(fn ($row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'statut' => $row->status?->value,
                'statut_libelle' => $row->status?->label(),
                'montant' => (int) ($row->montant_brut ?? $row->montant),
                'ordonnancement_id' => $row->ordonnancement?->id,
                'ordonnancement' => $row->ordonnancement?->reference,
                'paiement_id' => $row->ordonnancement?->paiement?->id,
                'paiement' => $row->ordonnancement?->paiement?->reference,
            ])->values()),
            'verrouille' => $this->isLocked(),
            'credit' => $this->when($line !== null, fn () => $workflow->credit($this->resource)),
            'actions' => [
                'transmettre' => $actor && $actor->can('transmit', $this->resource),
                'retourner' => $actor && $actor->can('sendBack', $this->resource),
                'rejeter' => $actor && $actor->can('reject', $this->resource),
                'viser' => $actor && $actor->can('vise', $this->resource),
                'completer' => $actor && $actor->can('update', $this->resource),
                'degager' => $actor !== null && $workflow->canDegage($actor, $this->resource),
                'annuler' => $actor !== null && $workflow->canCancel($actor, $this->resource),
                'partiel' => $actor !== null && $actor->holds('expert_budget') && in_array($this->status, [EngagementStatus::EnInstruction, EngagementStatus::Retourne], true) && ($this->nature ?? 'initial') === 'initial' && $this->parent_engagement_id === null,
                'avenant' => $actor !== null && ($actor->holds('expert_budget') || $actor->holds('directeur_budget')) && $this->visa_reference !== null,
                'pdf' => $this->visa_reference !== null,
                'joindre' => $actor !== null && $actor->holds('expert_budget') && in_array($this->status, [EngagementStatus::EnInstruction, EngagementStatus::Retourne], true) && $this->visa_reference === null,
            ],
            'actes' => app(ChainDocumentPublisher::class)->index($this->resource),
            'actes_a_emettre' => app(ChainDocumentPublisher::class)->emissibles($this->resource),
            'lignes' => $this->when($eb?->relationLoaded('lines'), fn () => $eb->lines->map(fn ($line) => [
                'designation' => $line->designation,
                'quantite' => $line->quantite,
                'prix_unitaire' => $line->prix_unitaire,
                'montant' => $line->montant,
            ])),
            'imputations' => $this->when($eb?->relationLoaded('imputations'), fn () => $eb->imputations->map(fn ($row) => [
                'ligne' => $row->budgetLine?->code,
                'libelle' => $row->budgetLine?->label,
                'montant' => $row->montant,
            ])),
            'documents' => $this->when($eb?->relationLoaded('documents'), fn () => $eb->documents->map(fn ($document) => [
                'nom' => $document->original_name,
                'type' => $document->type,
            ])),
            'pieces_attendues' => app(ReferentialReader::class)->documentLabels('engagement'),
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'action' => $event->action,
                'de' => $event->from_status,
                'vers' => $event->to_status,
                'motif' => $event->motif,
                'observations' => $event->observations,
                'acteur' => $event->actor?->name,
                'le' => $event->created_at?->toDateTimeString(),
            ])),
            'enrichissement' => $line?->enrichment ? [
                'pilier' => $line->enrichment->pilier,
                'objectif' => $line->enrichment->objectif,
                'activite' => $line->enrichment->activite,
            ] : null,
        ];
    }
}
