<?php

namespace App\Domains\Commitments\Http\Resources;

use App\Domains\Commitments\Enums\PaiementStatus;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Commitments\Services\PaiementWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Paiement */
class PaiementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ordre = $this->ordonnancement;
        $liquidation = $ordre?->liquidation;
        $engagement = $liquidation?->engagement;
        $eb = $engagement?->expressionBesoin;
        $role = $request->user()?->role;
        $status = $this->status;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'statut' => $status?->value,
            'statut_libelle' => $status?->label(),
            'etape' => $this->workflow_step,
            'acteur_attendu' => $this->expected_actor_label,
            'derniere_action' => $this->last_action,
            'echeance' => $this->due_on?->toDateString(),
            'mode' => $this->mode,
            'banque' => $this->banque,
            'agence' => $this->agence,
            'compte' => $this->compte,
            'titulaire' => $this->titulaire,
            'compte_bancaire_id' => $this->tiers_bank_account_id,
            'tiers' => $this->bankAccount?->tiers?->code,
            'compte_ceeac' => $this->compte_ceeac,
            'compte_modifie' => $this->compte_modifie,
            'motif_reglement' => $this->motif_reglement,
            'reference_reglement' => $this->reference_reglement,
            'date_valeur' => $this->date_valeur?->toDateString(),
            'montant_ordonnance' => (int) $this->montant,
            'montant_paye' => (int) $this->montant_paye,
            'montant_a_recouvrer' => (int) $this->montant_a_recouvrer,
            'reste' => $this->reste(),
            'pris_en_charge_le' => $this->pris_en_charge_at?->toDateTimeString(),
            'pris_en_charge_par' => $this->chargePar?->name,
            'valide_le' => $this->validated_at?->toDateTimeString(),
            'signe_le' => $this->signed_at?->toDateTimeString(),
            'signataire' => $this->signataire?->name,
            'rapproche_le' => $this->reconciled_at?->toDateTimeString(),
            'rapprochement' => $this->reconciliation_reference,
            'retour' => $this->return_motif,
            'rejet' => $this->rejection_motif,
            'rejet_bancaire' => $this->bank_rejection,
            'lot' => $this->lot?->reference,
            'lot_id' => $this->lot_id,
            'ordonnancement_id' => $ordre?->id,
            'ordonnancement' => $ordre?->reference,
            'signature' => $ordre?->signature_reference,
            'liquidation_id' => $liquidation?->id,
            'liquidation' => $liquidation?->reference,
            'visa' => $liquidation?->visa_reference,
            'engagement_id' => $engagement?->id,
            'engagement' => $engagement?->reference,
            'expression_besoin_id' => $eb?->id,
            'eb_reference' => $eb?->reference,
            'objet' => $eb?->objet,
            'structure' => $eb?->organizationUnit?->structureLabel(),
            'nature' => $eb?->nature?->value,
            'nature_libelle' => $eb?->nature?->label(),
            'beneficiaire' => $liquidation?->fournisseur ?: $engagement?->beneficiary_name,
            'ligne' => $engagement?->budgetLine?->code,
            'ligne_libelle' => $engagement?->budgetLine?->label,
            'montant_engage' => (int) $engagement?->montant,
            'montant_brut' => (int) $liquidation?->montant_brut,
            'retenues' => (int) $liquidation?->retenue_garantie + (int) $liquidation?->penalite,
            'pap' => $this->when($engagement?->budgetLine?->relationLoaded('enrichment'), function () use ($engagement) {
                $pap = $engagement?->budgetLine?->enrichment;

                return $pap === null ? null : [
                    'pilier' => $pap->pilier,
                    'axe' => $pap->axe,
                    'produit' => $pap->produit,
                    'activite' => $pap->activite,
                    'indicateur' => $pap->indicateur,
                ];
            }),
            'pieces' => $eb?->relationLoaded('documents')
                ? $eb->documents->map(fn ($document) => [
                    'type' => $document->type,
                    'nom' => $document->original_name,
                ])->values()
                : [],
            'controles' => app(PaiementWorkflow::class)->controls($this->resource),
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'action' => $event->action,
                'de' => $event->from_status,
                'vers' => $event->to_status,
                'motif' => $event->motif,
                'acteur' => $event->actor?->name,
                'le' => $event->created_at?->toDateTimeString(),
            ])),
            'executions' => $this->whenLoaded('executions', fn () => $this->executions->map(fn ($execution) => [
                'id' => $execution->id,
                'rang' => $execution->rang,
                'montant' => (int) $execution->montant,
                'reference' => $execution->reference_reglement,
                'mode' => $execution->mode,
                'date_valeur' => $execution->date_valeur?->toDateString(),
                'statut' => $execution->status,
                'motif_rejet' => $execution->rejection_motif,
                'preuve' => $execution->preuve_chemin !== null,
                'acteur' => $execution->actor?->name,
                'le' => $execution->created_at?->toDateTimeString(),
            ])->values()),
            'actes' => app(ChainDocumentPublisher::class)->index($this->resource),
            'actes_a_emettre' => app(ChainDocumentPublisher::class)->emissibles($this->resource),
            'actions' => [
                'prendre_en_charge' => $role === 'comptable' && $status === PaiementStatus::Genere,
                'preparer' => $role === 'comptable' && in_array($status, [PaiementStatus::EnPreparation, PaiementStatus::Retourne], true),
                'valider' => $role === 'chef_comptable' && $status === PaiementStatus::AControler,
                'signer' => $role === 'agent_comptable' && $status === PaiementStatus::ASigner,
                'retourner' => ($role === 'chef_comptable' && $status === PaiementStatus::AControler) || ($role === 'agent_comptable' && $status === PaiementStatus::ASigner),
                'rejeter' => $role === 'agent_comptable' && $status === PaiementStatus::ASigner,
                'suspendre' => $role === 'agent_comptable' && in_array($status, [PaiementStatus::ASigner, PaiementStatus::Autorise, PaiementStatus::PayePartiel], true),
                'lever_suspension' => $role === 'agent_comptable' && $status === PaiementStatus::Suspendu,
                'executer' => $role === 'comptable' && in_array($status, [PaiementStatus::Autorise, PaiementStatus::PayePartiel], true),
                'rapprocher' => $role === 'comptable' && $status === PaiementStatus::ARapprocher,
                'rejet_bancaire' => $role === 'comptable' && in_array($status, [PaiementStatus::Autorise, PaiementStatus::PayePartiel, PaiementStatus::ARapprocher], true),
                'reemettre' => $role === 'comptable' && $status === PaiementStatus::RejeteBancaire,
                'pdf' => in_array($status, [PaiementStatus::PayePartiel, PaiementStatus::ARapprocher, PaiementStatus::Cloture], true),
            ],
        ];
    }
}
