<?php

namespace App\Domains\Commitments\Http\Resources;

use App\Domains\Commitments\Enums\OrdonnancementStatus;
use App\Domains\Commitments\Models\OrdDelegation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Services\ChainDocumentPublisher;
use App\Domains\Procurement\Models\Marche;
use App\Shared\Support\AmountInWords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Ordonnancement */
class OrdonnancementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $liquidation = $this->liquidation;
        $engagement = $liquidation?->engagement;
        $eb = $engagement?->expressionBesoin;
        $actor = $request->user();
        $competent = $actor !== null && $actor->holds((string) $this->ordonnateur_role);
        $retenues = (int) $liquidation?->retenue_garantie + (int) $liquidation?->penalite;
        $seuil = once(fn () => OrdDelegation::query()->courante()->orderBy('seuil_max')->value('seuil_max'));
        $pieces = $eb?->relationLoaded('documents') ? $eb->documents : null;
        $signed = $this->status?->signed() === true;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'liquidation_id' => $liquidation?->id,
            'liquidation' => $liquidation?->reference,
            'engagement_id' => $engagement?->id,
            'engagement' => $engagement?->reference,
            'eb_reference' => $eb?->reference,
            'expression_besoin_id' => $eb?->id,
            'objet' => $eb?->objet,
            'structure' => $eb?->organizationUnit?->structureLabel(),
            'nature' => $eb?->nature?->value,
            'nature_libelle' => $eb?->nature?->label(),
            'ligne' => $engagement?->budgetLine?->code,
            'ligne_libelle' => $engagement?->budgetLine?->label,
            'beneficiaire' => $liquidation?->fournisseur ?: $engagement?->beneficiary_name,
            'rccm' => $engagement?->beneficiary_rccm,
            'nif' => $engagement?->beneficiary_nif,
            'banque' => null,
            'rib' => null,
            'compte' => null,
            'montant_engage' => $engagement?->montant,
            'montant_brut' => $liquidation?->montant_brut,
            'retenue_garantie' => $liquidation?->retenue_garantie,
            'penalite' => $liquidation?->penalite,
            'retenues' => $retenues,
            'montant_net' => $this->montant,
            'lettres' => app(AmountInWords::class)->fcfa((int) $this->montant),
            'seuil' => $seuil !== null ? (int) $seuil : null,
            'au_dessus_du_seuil' => $seuil !== null && (int) $this->montant > (int) $seuil,
            'visa' => $liquidation?->visa_reference,
            'visa_le' => $liquidation?->vised_at?->toDateTimeString(),
            'facture' => $liquidation?->invoice_number,
            'date_facture' => $liquidation?->invoice_date?->toDateString(),
            'montant_ht' => $liquidation?->montant_ht,
            'taxes' => $liquidation?->taxes,
            'service_fait' => $liquidation?->serviceFaitLabel(),
            'reserves' => $liquidation?->service_fait_reserves,
            'ordonnateur' => $this->ordonnateur_label,
            'ordonnateur_role' => $this->ordonnateur_role,
            'fondement' => $this->fondement,
            'statut' => $this->status?->value,
            'statut_libelle' => $this->status?->label(),
            'signe' => $signed,
            'etape' => $this->workflow_step,
            'acteur_attendu' => $this->expected_actor_label,
            'derniere_action' => $this->last_action,
            'echeance' => $this->due_on?->toDateString(),
            'heures_attente' => $this->status === OrdonnancementStatus::ASigner ? (int) abs($this->created_at?->diffInHours(now()) ?? 0) : null,
            'en_retard' => $this->status === OrdonnancementStatus::ASigner && $this->created_at?->lt(now()->subHours(48)),
            'signature' => $this->signature_reference,
            'version_signature' => $this->signature_version,
            'empreinte' => $this->empreinte,
            'signe_le' => $this->signed_at?->toDateTimeString(),
            'signataire' => $this->signataire?->name,
            'fonction_signataire' => $this->signataire?->function_title,
            'transmission_erreur' => $this->transmission_error,
            'tentatives' => $this->transmission_attempts,
            'idempotence' => $this->idempotence_key,
            'journal' => $this->journal(),
            'effets' => $this->effects(),
            'paiement' => $this->paiement_reference,
            'paiement_id' => $this->paiement?->id,
            'retour' => $this->return_motif,
            'rejet' => $this->rejection_motif,
            'controles' => $this->controls($pieces?->count() ?? 0),
            'anomalies' => $this->anomalies($pieces?->count() ?? 0),
            'retenues_detail' => $this->when($request->route('ordonnancement') !== null, fn () => $this->retenuesDetail()),
            'imputations' => $this->when($eb?->relationLoaded('imputations'), fn () => $this->imputations()),
            'successives' => $this->when($engagement?->relationLoaded('liquidations'), fn () => $this->successives()),
            'pap' => $this->when($engagement?->budgetLine?->relationLoaded('enrichment'), fn () => $this->pap()),
            'marche' => $this->when($request->route('ordonnancement') !== null, function () use ($engagement) {
                $marche = $engagement === null ? null : Marche::query()->where('engagement_id', $engagement->id)->first();

                return [
                    'rattache' => $marche !== null,
                    'reference' => $marche?->reference,
                    'objet' => $marche?->objet,
                    'montant' => $marche === null ? null : (int) $marche->montant,
                    'message' => $marche === null ? 'Aucun marché ni contrat n’est rattaché à cet engagement.' : null,
                ];
            }),
            'factures' => $this->when($request->route('ordonnancement') !== null, fn () => $this->factures()),
            'pieces' => $this->when($pieces !== null, fn () => $pieces->map(fn ($document) => [
                'nom' => $document->original_name,
                'type' => $document->type,
                'le' => $document->created_at?->toDateString(),
            ])->values()),
            'ged' => $this->when($request->route('ordonnancement') !== null, fn () => $this->ged($pieces)),
            'cumul_ordonnance' => $this->when($engagement?->relationLoaded('liquidations'), fn () => $this->cumulOrdonnance()),
            'historique' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'action' => $event->action,
                'de' => $event->from_status,
                'vers' => $event->to_status,
                'motif' => $event->motif,
                'observations' => $event->observations,
                'acteur' => $event->actor?->name,
                'le' => $event->created_at?->toDateTimeString(),
            ])),
            'ordre_nature' => $this->nature ?? 'normal',
            'actes' => app(ChainDocumentPublisher::class)->index($this->resource),
            'actes_a_emettre' => app(ChainDocumentPublisher::class)->emissibles($this->resource),
            'actions' => [
                'fractionner' => $actor !== null && ($actor->holds('directeur_budget') || $actor->holds('controleur_financier') || $competent) && $this->status === OrdonnancementStatus::ASigner && ($this->nature ?? 'normal') === 'normal',
                'signer' => $competent && $this->status === OrdonnancementStatus::ASigner,
                'retourner' => $competent && $this->status === OrdonnancementStatus::ASigner,
                'rejeter' => $competent && $this->status === OrdonnancementStatus::ASigner,
                'reprendre' => $competent && $this->status === OrdonnancementStatus::TransmissionErreur,
                'pdf' => $signed,
            ],
        ];
    }

    /**
     * @return list<array{point: string, ok: bool, bloquant: bool, detail: string}>
     */
    private function controls(int $pieceCount): array
    {
        $liquidation = $this->liquidation;
        $engagement = $liquidation?->engagement;
        $net = (int) $this->montant;
        $expected = max(0, (int) $liquidation?->montant_brut - (int) $liquidation?->retenue_garantie - (int) $liquidation?->penalite);
        $banque = filled($this->rib);

        return [
            ['point' => 'Engagement régulièrement visé', 'ok' => filled($engagement?->visa_reference), 'bloquant' => true, 'detail' => $engagement?->visa_reference ?? 'Visa absent'],
            ['point' => 'Liquidation régulièrement visée', 'ok' => filled($liquidation?->visa_reference), 'bloquant' => true, 'detail' => $liquidation?->visa_reference ?? 'Visa absent'],
            ['point' => 'Service fait certifié', 'ok' => $liquidation?->service_fait_at !== null, 'bloquant' => true, 'detail' => $liquidation?->serviceFaitLabel() ?? '—'],
            ['point' => 'Pièces justificatives complètes', 'ok' => $pieceCount > 0, 'bloquant' => false, 'detail' => $pieceCount > 0 ? $pieceCount.' pièce(s)' : 'Aucune pièce héritée de l’expression de besoin'],
            ['point' => 'Montant net déterminé', 'ok' => $net > 0 && $net === $expected, 'bloquant' => true, 'detail' => number_format($net, 0, ',', ' ').' FCFA'],
            ['point' => 'Bénéficiaire identifié', 'ok' => filled($liquidation?->fournisseur ?: $engagement?->beneficiary_name), 'bloquant' => true, 'detail' => $liquidation?->fournisseur ?: '—'],
            ['point' => 'Coordonnées bancaires disponibles', 'ok' => $banque, 'bloquant' => false, 'detail' => $banque ? 'Compte renseigné' : 'Non renseignées dans le référentiel des tiers'],
            ['point' => 'Imputation conforme', 'ok' => filled($engagement?->budgetLine?->code), 'bloquant' => true, 'detail' => $engagement?->budgetLine?->code ?? '—'],
            ['point' => 'Ordonnateur compétent identifié', 'ok' => filled($this->ordonnateur_role), 'bloquant' => true, 'detail' => $this->fondement ?? '—'],
            ['point' => 'Aucun ordre de paiement déjà émis pour cette liquidation', 'ok' => $this->paiement_reference === null || $this->paiement !== null, 'bloquant' => true, 'detail' => $this->paiement_reference ?? 'Aucun paiement'],
        ];
    }

    /**
     * @return list<string>
     */
    private function anomalies(int $pieceCount): array
    {
        $notes = [];
        $blocking = false;
        foreach ($this->controls($pieceCount) as $control) {
            if ($control['ok']) {
                continue;
            }
            if ($control['bloquant']) {
                $blocking = true;
            }
            $notes[] = $control['point'].' · '.$control['detail'];
        }
        if (filled($this->liquidation?->service_fait_reserves)) {
            $notes[] = 'Réserve au service fait : '.$this->liquidation->service_fait_reserves;
        }
        $autres = $this->liquidation?->engagement?->relationLoaded('liquidations')
            ? $this->liquidation->engagement->liquidations->where('id', '!=', $this->liquidation_id)->count()
            : 0;
        if ($autres > 0) {
            $notes[] = 'Liquidation successive : '.$autres.' autre(s) liquidation(s) sur le même engagement.';
        }
        if (! $blocking) {
            array_unshift($notes, 'Aucune anomalie bloquante');
        }

        return $notes;
    }

    /**
     * @return list<array{libelle: string, base: int, taux: float, montant: int}>
     */
    private function retenuesDetail(): array
    {
        $liquidation = $this->liquidation;
        $base = (int) $liquidation?->montant_brut;
        $rows = [
            ['libelle' => 'Retenue de garantie', 'base' => $base, 'montant' => (int) $liquidation?->retenue_garantie],
            ['libelle' => 'Pénalité', 'base' => $base, 'montant' => (int) $liquidation?->penalite],
        ];

        return array_map(function (array $row) use ($base) {
            $row['taux'] = $base > 0 ? round($row['montant'] / $base * 100, 2) : 0;

            return $row;
        }, $rows);
    }

    /**
     * @return list<array{ligne: string|null, libelle: string|null, brut: int, net: int}>
     */
    private function imputations(): array
    {
        $eb = $this->liquidation?->engagement?->expressionBesoin;
        $brut = (int) $this->liquidation?->montant_brut;
        $net = (int) $this->montant;
        $rows = $eb?->imputations ?? collect();
        if ($rows->isEmpty()) {
            return [[
                'ligne' => $this->liquidation?->engagement?->budgetLine?->code,
                'libelle' => $this->liquidation?->engagement?->budgetLine?->label,
                'brut' => $brut,
                'net' => $net,
            ]];
        }
        $total = max(1, (int) $rows->sum('montant'));

        return $rows->map(function ($row) use ($brut, $net, $total) {
            $share = (int) $row->montant / $total;

            return [
                'ligne' => $row->budgetLine?->code,
                'libelle' => $row->budgetLine?->label,
                'brut' => (int) round($brut * $share),
                'net' => (int) round($net * $share),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function successives(): array
    {
        $liquidations = $this->liquidation?->engagement?->liquidations ?? collect();

        return $liquidations->values()->map(function ($liquidation, int $index) {
            return [
                'tranche' => 'T'.($index + 1),
                'liquidation' => $liquidation->reference,
                'ordonnancement' => $liquidation->ordonnancement?->reference,
                'brut' => $liquidation->montant_brut,
                'net' => $liquidation->montant_net,
                'etat' => $liquidation->id === $this->liquidation_id ? 'Présent dossier' : ($liquidation->status?->label() ?? '—'),
                'courante' => $liquidation->id === $this->liquidation_id,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pap(): ?array
    {
        $pap = $this->liquidation?->engagement?->budgetLine?->enrichment;
        if ($pap === null) {
            return null;
        }

        return [
            'pilier' => $pap->pilier,
            'axe' => $pap->axe,
            'produit' => $pap->produit,
            'activite' => $pap->activite,
            'resultats' => $pap->resultats_attendus,
            'indicateur' => $pap->indicateur,
            'cible' => $pap->cible,
            'periode' => $pap->periode,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function factures(): array
    {
        $liquidation = $this->liquidation;
        if (blank($liquidation?->invoice_number)) {
            return [];
        }

        return [[
            'numero' => $liquidation->invoice_number,
            'date' => $liquidation->invoice_date?->toDateString(),
            'brut' => $liquidation->montant_brut,
            'taxes' => $liquidation->taxes,
            'retenues' => (int) $liquidation->retenue_garantie + (int) $liquidation->penalite,
            'net' => $this->montant,
            'statut' => $liquidation->doublon ? 'Doublon' : 'Rattachée',
        ]];
    }

    /**
     * @param  Collection<int, mixed>|null  $pieces
     * @return list<array{nom: string, detail: string}>
     */
    private function ged($pieces): array
    {
        $rows = [[
            'nom' => 'Fiche d’ordonnancement',
            'detail' => $this->signature_reference ? 'Générée · '.$this->signature_reference : 'Disponible après signature',
        ]];
        foreach ($pieces ?? [] as $document) {
            $rows[] = [
                'nom' => $document->original_name,
                'detail' => $document->type.' · héritée de l’expression de besoin',
            ];
        }

        return $rows;
    }

    private function cumulOrdonnance(): int
    {
        $total = 0;
        foreach ($this->liquidation?->engagement?->liquidations ?? [] as $liquidation) {
            $ordre = $liquidation->ordonnancement;
            if ($ordre !== null && $ordre->status?->signed() === true) {
                $total += (int) $ordre->montant;
            }
        }

        return $total;
    }

    /**
     * @return list<array{numero: int, le: string|null, resultat: string, message: string}>
     */
    private function journal(): array
    {
        $journal = $this->transmission_journal ?? [];
        if ($journal !== []) {
            return $journal;
        }
        if ((int) $this->transmission_attempts < 1 && $this->transmission_error === null) {
            return [];
        }

        return [[
            'numero' => 1,
            'le' => $this->updated_at?->toDateTimeString(),
            'resultat' => $this->transmission_error ? 'Échec' : 'Accusé',
            'message' => $this->transmission_error ?? ($this->paiement_reference ?? 'Transmission'),
        ]];
    }

    /**
     * @return list<array{etat: string, evenement: string, detail: string, le: string|null}>
     */
    private function effects(): array
    {
        $signed = $this->status?->signed() === true;
        $erreur = $this->status === OrdonnancementStatus::TransmissionErreur;
        $paye = filled($this->paiement_reference);

        return [
            ['etat' => $signed ? 'fait' : 'attente', 'evenement' => 'Signature de l’ordre de paiement', 'detail' => $this->signature_reference ?? 'En attente', 'le' => $this->signed_at?->toDateTimeString()],
            ['etat' => $signed ? 'fait' : 'attente', 'evenement' => 'PDF officiel généré', 'detail' => $this->empreinte ? $this->signature_version.' · '.substr((string) $this->empreinte, 0, 12) : 'Après signature', 'le' => $this->signed_at?->toDateTimeString()],
            ['etat' => $signed ? 'fait' : 'attente', 'evenement' => 'Archivage GED', 'detail' => 'Exercice › Ordonnancement › '.$this->reference, 'le' => $this->signed_at?->toDateTimeString()],
            ['etat' => $erreur ? 'erreur' : ($paye ? 'fait' : 'attente'), 'evenement' => 'Transmission à l’Agence Comptable', 'detail' => $this->transmission_error ?? ($this->paiement_reference ?? 'En attente d’accusé'), 'le' => null],
            ['etat' => $paye ? 'fait' : 'attente', 'evenement' => 'Dossier de paiement créé', 'detail' => $this->paiement_reference ?? 'Déclenché après accusé de transmission', 'le' => null],
            ['etat' => $paye ? 'fait' : 'attente', 'evenement' => 'Notification des agents concernés', 'detail' => $paye ? 'Ordonnateur et chaîne de dépense' : 'En attente', 'le' => null],
        ];
    }
}
