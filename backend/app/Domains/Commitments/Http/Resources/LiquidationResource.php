<?php

namespace App\Domains\Commitments\Http\Resources;

use App\Domains\Administration\Services\ReferentialReader;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\LiquidationWorkflow;
use App\Domains\Needs\Models\ExpressionBesoin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Liquidation */
class LiquidationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $engagement = $this->engagement;
        $eb = $engagement?->expressionBesoin;
        $actor = $request->user();
        $workflow = app(LiquidationWorkflow::class);
        $cumul = $engagement ? $workflow->liquidatedOn($engagement) : 0;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'engagement_id' => $this->engagement_id,
            'engagement' => $engagement?->reference,
            'eb_reference' => $eb?->reference,
            'expression_besoin_id' => $eb?->id,
            'objet' => $eb?->objet,
            'structure' => $eb?->organizationUnit?->structureLabel(),
            'nature' => $eb?->nature?->value,
            'nature_libelle' => $eb?->nature?->label(),
            'ligne' => $engagement?->budgetLine?->code,
            'fournisseur' => $this->fournisseur,
            'montant_engage' => $engagement?->montant,
            'montant_accepte' => $this->montant_accepte,
            'montant_ht' => $this->montant_ht,
            'taxes' => $this->taxes,
            'montant_ttc' => $this->montant_ttc,
            'montant_brut' => $this->montant_brut,
            'retenue_garantie' => $this->retenue_garantie,
            'penalite' => $this->penalite,
            'montant_net' => $this->montant_net,
            'non_accepte' => max(0, (int) $this->montant_ttc - (int) $this->montant_brut),
            'cumul_liquide' => $cumul,
            'reliquat' => max(0, (int) $engagement?->montant - $cumul),
            'facture' => $this->invoice_number,
            'date_facture' => $this->invoice_date?->toDateString(),
            'doublon' => $this->doublon,
            'doublon_de' => $this->when((bool) $this->doublon, fn () => $this->duplicateOf()),
            'service_fait' => $this->serviceFaitLabel(),
            'service_fait_le' => $this->service_fait_at?->toDateTimeString(),
            'service_fait_par' => $this->certifiedBy?->name,
            'reserves' => $this->service_fait_reserves,
            'statut' => $this->status?->value,
            'statut_libelle' => $this->status?->label(),
            'etape' => $this->workflow_step,
            'acteur_attendu' => $this->expected_actor_label,
            'derniere_action' => $this->last_action,
            'echeance' => $this->due_on?->toDateString(),
            'en_retard' => $this->due_on !== null && $this->due_on->isPast() && $this->status?->isOpen(),
            'visa' => $this->visa_reference,
            'ordonnancement' => $this->ordonnancement_reference,
            'paiement' => $this->ordonnancement?->paiement?->reference,
            'paiement_id' => $this->ordonnancement?->paiement?->id,
            'natures_prestation' => app(ReferentialReader::class)->valueLabels('nature_prestation'),
            'verrouille' => $this->isLocked(),
            'bon_livraison' => $this->bon_livraison,
            'nature_prestation' => $this->nature_prestation,
            'lieu_reception' => $this->lieu_reception,
            'date_service' => $this->service_fait_at?->toDateString(),
            'echeance_facture' => $this->invoice_due?->toDateString(),
            'sous_lignes' => $this->sousLignes($eb),
            'imputations' => $this->when($eb?->relationLoaded('imputations'), fn () => $eb->imputations->map(fn ($row) => [
                'ligne' => $row->budgetLine?->code,
                'libelle' => $row->budgetLine?->label,
                'montant' => $row->montant,
            ])),
            'pieces' => $this->when($eb?->relationLoaded('documents'), fn () => $eb->documents->map(fn ($document) => [
                'nom' => $document->original_name,
                'type' => $document->type,
            ])),
            'successives' => $this->when($engagement?->relationLoaded('liquidations'), fn () => $engagement->liquidations->map(fn ($row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'montant_brut' => $row->montant_brut,
                'statut' => $row->status?->label(),
                'courante' => $row->id === $this->id,
            ])),
            'signataire' => [
                'nom' => $this->certifiedBy?->name ?? $actor?->name,
                'fonction' => $this->certifiedBy?->function_title ?? $actor?->function_title,
                'structure' => $this->certifiedBy?->organizationUnit?->structureLabel()
                    ?? $actor?->organizationUnit?->structureLabel()
                    ?? $eb?->organizationUnit?->structureLabel(),
            ],
            'rectifications' => $this->relationLoaded('rectifications') ? $this->rectifications->map(fn ($row) => [
                'reference' => $row->reference,
                'kind' => $row->kind,
                'montant' => $row->amount,
                'motif' => $row->motif,
            ]) : [],
            'actes' => app(ChainDocumentPublisher::class)->index($this->resource),
            'actes_a_emettre' => app(ChainDocumentPublisher::class)->emissibles($this->resource),
            'actions' => [
                'certifier' => $actor && $actor->can('certify', $this->resource),
                'facture' => $actor && $actor->can('invoice', $this->resource),
                'soumettre' => $actor && $actor->can('submit', $this->resource),
                'retourner' => $actor && $actor->can('sendBack', $this->resource),
                'complement' => $actor && $actor->can('complement', $this->resource),
                'rejeter' => $actor && $actor->can('reject', $this->resource),
                'viser' => $actor && $actor->can('vise', $this->resource),
                'rectifier' => $actor && $actor->can('rectify', $this->resource),
                'pdf' => $this->visa_reference !== null,
            ],
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'action' => $event->action,
                'motif' => $event->motif,
                'observations' => $event->observations,
                'acteur' => $event->actor?->name,
                'le' => $event->created_at?->toDateTimeString(),
            ])),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sousLignes(?ExpressionBesoin $eb): array
    {
        $saved = collect($this->service_lignes ?? []);
        $lines = $eb?->relationLoaded('lines') ? $eb->lines : collect();
        if ($lines->isNotEmpty()) {
            return $lines->map(function ($line) use ($saved) {
                $match = $saved->firstWhere('designation', $line->designation) ?? [];

                return [
                    'tache' => $line->task ? 'T'.$line->task->position : ($line->position ? 'T'.$line->position : null),
                    'designation' => $line->designation,
                    'quantite_commandee' => (float) $line->quantite,
                    'quantite_livree' => (float) ($match['quantite_livree'] ?? $line->quantite),
                    'quantite_acceptee' => (float) ($match['quantite_acceptee'] ?? $line->quantite),
                    'prix_unitaire' => (int) $line->prix_unitaire,
                    'observation' => $match['observation'] ?? $line->observation,
                ];
            })->all();
        }

        if ($saved->isNotEmpty()) {
            return $saved->map(fn ($line) => [
                'tache' => $line['tache'] ?? null,
                'designation' => $line['designation'],
                'quantite_commandee' => (float) $line['quantite_commandee'],
                'quantite_livree' => (float) $line['quantite_livree'],
                'quantite_acceptee' => (float) $line['quantite_acceptee'],
                'prix_unitaire' => (int) $line['prix_unitaire'],
                'observation' => $line['observation'] ?? null,
            ])->all();
        }

        return [[
            'tache' => null,
            'designation' => $eb?->objet ?? 'Forfait',
            'quantite_commandee' => 1,
            'quantite_livree' => 1,
            'quantite_acceptee' => 1,
            'prix_unitaire' => (int) ($this->engagement?->montant ?? 0),
            'observation' => null,
        ]];
    }

    /**
     * @return array{id: int, reference: string, statut: string|null}|null
     */
    private function duplicateOf(): ?array
    {
        $other = Liquidation::query()
            ->whereKeyNot($this->id)
            ->where('invoice_number', $this->invoice_number)
            ->where('fournisseur', $this->fournisseur)
            ->whereNotIn('status', ['rejetee', 'annulee'])
            ->first();

        if ($other === null) {
            return null;
        }

        return [
            'id' => $other->id,
            'reference' => $other->reference,
            'statut' => $other->status?->label(),
        ];
    }
}
