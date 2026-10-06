<?php

namespace App\Domains\Commitments\Services;

use App\Domains\Commitments\Models\Engagement;
use App\Domains\Commitments\Models\Liquidation;
use App\Domains\Commitments\Models\Ordonnancement;
use App\Domains\Commitments\Models\Paiement;
use App\Domains\Needs\Models\ExpressionBesoin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Données réelles des actes de la chaîne. Les mentions des modèles vierges
 * (« à renseigner », QR du modèle) n’y figurent pas. Les montants restent
 * des entiers XAF, comme le grand livre.
 */
class ChainActePresenter
{
    public function __construct(private readonly EngagementWorkflow $engagements) {}

    /**
     * @return list<string>
     */
    public function kindsFor(Model $model): array
    {
        return array_values(array_filter(
            $this->candidates($model),
            function (string $kind) use ($model): bool {
                try {
                    $this->present($kind, $model);

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(string $kind, Model $model): array
    {
        return match ($kind) {
            'engagement' => $this->engagement($this->asEngagement($model)),
            'controle_budgetaire' => $this->controle($this->asEngagement($model)),
            'attestation_service_fait' => $this->attestation($this->asLiquidation($model)),
            'pv_reception' => $this->pv($this->asLiquidation($model)),
            'liquidation' => $this->liquidation($this->asLiquidation($model)),
            'ordonnancement' => $this->ordonnancement($this->asOrdre($model)),
            'bordereau_transmission' => $this->bordereau($this->asOrdre($model)),
            'paiement' => $this->paiement($this->asPaiement($model)),
            'ordre_virement' => $this->virement($this->asPaiement($model)),
            'bordereau_cheque' => $this->cheque($this->asPaiement($model)),
            'bon_sortie_caisse' => $this->caisse($this->asPaiement($model)),
            'rapprochement' => $this->rapprochement($this->asPaiement($model)),
            default => throw ValidationException::withMessages(['document' => 'Ce type d’acte n’est pas émis sur ce dossier.']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function engagement(Engagement $engagement): array
    {
        $this->require($engagement->visa_reference !== null, 'La fiche d’engagement s’émet après le visa du Contrôleur Financier.');
        $engagement->loadMissing(['expressionBesoin.organizationUnit', 'expressionBesoin.exercice', 'expressionBesoin.lines', 'expressionBesoin.documents', 'budgetLine', 'events.actor']);
        $eb = $engagement->expressionBesoin;
        $credit = $this->engagements->credit($engagement);
        $visa = $engagement->events->firstWhere('action', 'visa');

        return $this->enveloppe('FICHE D’ENGAGEMENT', 'Identification, imputation et visa', $engagement->reference, (string) ($eb?->exercice?->annee ?? ''), (string) $engagement->status?->label(), [
            $this->kv('01  IDENTIFICATION', [
                ['EB source', $eb?->reference ?: 'Non renseignée'],
                ['Structure initiatrice', $eb?->organizationUnit?->structureLabel() ?: 'Non renseignée'],
                ['Créancier', $engagement->beneficiary_name ?: 'Non renseigné'],
                ['Classification', $eb?->nature?->value === 'pap' ? 'PAP' : 'Hors PAP'],
                ['Imputation', $this->ligne($engagement)],
            ]),
            ['titre' => '02  OBJET', 'texte' => $eb?->objet ?: 'Non renseigné'],
            $this->tableau('03  DÉTAIL FINANCIER', ['Désignation', 'Qté', 'Unité', 'P. unitaire XAF', 'Montant XAF'], $this->lignesBesoin($eb), 'MONTANT ENGAGÉ', $this->montant((int) $engagement->montant), (int) $engagement->montant),
            $this->kv('04  CRÉDIT AU MOMENT DU VISA', [
                ['Disponible avant', $this->montant($credit['disponible_avant']).' XAF'],
                ['Montant engagé', $this->montant($credit['montant']).' XAF'],
                ['Disponible après', $this->montant($credit['disponible_apres']).' XAF'],
            ]),
            $this->signatures('05  VISA', [[
                'role' => 'Contrôleur Financier',
                'nom' => $visa?->actor?->name ?: 'Non enregistré',
                'date' => $engagement->vised_at?->format('d/m/Y H:i') ?: 'Non enregistrée',
                'decision' => 'Visa '.$engagement->visa_reference,
            ]]),
        ], 'Le disponible est celui du grand livre au moment de l’émission. Cette fiche ne certifie pas le service fait.');
    }

    /**
     * @return array<string, mixed>
     */
    public function controle(Engagement $engagement): array
    {
        $this->require($engagement->visa_reference !== null, 'Le contrôle budgétaire officiel s’émet avec le visa.');
        $engagement->loadMissing(['expressionBesoin.organizationUnit', 'expressionBesoin.exercice', 'budgetLine']);
        $credit = $this->engagements->credit($engagement);
        $eb = $engagement->expressionBesoin;

        return $this->enveloppe('FICHE DE CONTRÔLE BUDGÉTAIRE', 'Imputation et disponibilité des crédits', $engagement->reference, (string) ($eb?->exercice?->annee ?? ''), 'Visa '.$engagement->visa_reference, [
            $this->kv('01  IDENTIFICATION', [
                ['Dossier', ($eb?->reference ?: '—').' / '.$engagement->reference],
                ['Exercice / imputation', ((string) ($eb?->exercice?->annee ?? '—')).' · '.$this->ligne($engagement)],
                ['Structure', $eb?->organizationUnit?->structureLabel() ?: 'Non renseignée'],
                ['Montant contrôlé', $this->montant((int) $engagement->montant).' XAF'],
            ]),
            $this->kv('02  DISPONIBILITÉ', [
                ['Disponible avant engagement', $this->montant($credit['disponible_avant']).' XAF'],
                ['Engagement retenu', $this->montant($credit['montant']).' XAF'],
                ['Disponible après', $this->montant($credit['disponible_apres']).' XAF'],
                ['Conclusion', $credit['suffisant'] ? 'Disponibilité confirmée' : 'Anomalie : insuffisance de '.$this->montant($credit['insuffisance']).' XAF'],
            ]),
        ], 'Ce contrôle ne constitue pas une certification du service fait ni un paiement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function attestation(Liquidation $liquidation): array
    {
        $this->require($liquidation->service_fait_at !== null, 'L’attestation s’émet lorsque le service fait est certifié.');
        $liquidation->loadMissing(['engagement.expressionBesoin.organizationUnit', 'engagement.expressionBesoin.exercice', 'certifiedBy']);
        $engagement = $liquidation->engagement;
        $eb = $engagement?->expressionBesoin;

        return $this->enveloppe('ATTESTATION DE SERVICE FAIT', 'Certification par le service compétent', $liquidation->reference, (string) ($eb?->exercice?->annee ?? ''), $liquidation->serviceFaitLabel(), [
            $this->kv('01  IDENTIFICATION', [
                ['Références', ($eb?->reference ?: '—').' / '.($engagement?->reference ?: '—').' / '.$liquidation->reference],
                ['Bon de livraison', $liquidation->bon_livraison ?: 'Non enregistré'],
                ['Prestataire', $liquidation->fournisseur ?: $engagement?->beneficiary_name ?: 'Non renseigné'],
                ['Structure', $eb?->organizationUnit?->structureLabel() ?: 'Non renseignée'],
            ]),
            $this->kv('02  CONSTATATION', [
                ['Objet', $eb?->objet ?: 'Non renseigné'],
                ['Date / lieu', ($liquidation->service_fait_at?->format('d/m/Y') ?: '—').' · '.($liquidation->lieu_reception ?: 'Lieu non enregistré')],
                ['Réserves', $liquidation->service_fait_reserves ?: 'Aucune réserve enregistrée'],
                ['Montant accepté', $this->montant((int) $liquidation->montant_accepte).' XAF'],
            ]),
            $this->signatures('03  CERTIFICATION', [[
                'role' => 'Service certificateur',
                'nom' => $liquidation->certifiedBy?->name ?: 'Non enregistré',
                'date' => $liquidation->service_fait_at?->format('d/m/Y H:i') ?: 'Non enregistrée',
                'decision' => filled($liquidation->service_fait_reserves) ? 'Certifié avec réserve' : 'Certifié',
            ]]),
        ], 'La certification atteste les prestations constatées. Le visa financier reste un contrôle distinct.');
    }

    /**
     * @return array<string, mixed>
     */
    public function pv(Liquidation $liquidation): array
    {
        $this->require($liquidation->service_fait_at !== null && (filled($liquidation->lieu_reception) || filled($liquidation->bon_livraison)), 'Le procès-verbal s’émet lorsqu’une réception est constatée (lieu ou bon de livraison).');
        $liquidation->loadMissing(['engagement.expressionBesoin.exercice', 'certifiedBy']);
        $engagement = $liquidation->engagement;
        $lignes = collect($liquidation->service_lignes ?? [])->map(fn (array $row): array => [
            (string) ($row['designation'] ?? ''),
            (string) ($row['quantite_commandee'] ?? ''),
            (string) ($row['quantite_livree'] ?? ''),
            (string) ($row['quantite_acceptee'] ?? ''),
        ])->all();
        $decision = filled($liquidation->rejection_motif)
            ? 'Réception refusée'
            : (filled($liquidation->service_fait_reserves) ? 'Réception admise avec réserves' : 'Réception admise');

        return $this->enveloppe('PROCÈS-VERBAL DE RÉCEPTION', 'Constatation de la réception', $liquidation->reference, (string) ($engagement?->expressionBesoin?->exercice?->annee ?? ''), $decision, [
            $this->kv('01  IDENTIFICATION', [
                ['Date / lieu', ($liquidation->service_fait_at?->format('d/m/Y') ?: '—').' · '.($liquidation->lieu_reception ?: 'Non enregistré')],
                ['Engagement', $engagement?->reference ?: 'Non renseigné'],
                ['Prestataire', $liquidation->fournisseur ?: 'Non renseigné'],
                ['Bon de livraison', $liquidation->bon_livraison ?: 'Non enregistré'],
            ]),
            $this->tableau('02  QUANTITÉS', ['Désignation', 'Commandée', 'Reçue', 'Acceptée'], $lignes, null, null, null),
            $this->signatures('03  DÉCISION', [[
                'role' => 'Responsable de réception',
                'nom' => $liquidation->certifiedBy?->name ?: 'Non enregistré',
                'date' => $liquidation->service_fait_at?->format('d/m/Y H:i') ?: 'Non enregistrée',
                'decision' => $decision.($liquidation->service_fait_reserves ? ' — '.$liquidation->service_fait_reserves : ''),
            ]]),
        ], 'Les réserves restent inscrites sur le dossier. Ce procès-verbal n’exécute pas le paiement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function liquidation(Liquidation $liquidation): array
    {
        $this->require($liquidation->visa_reference !== null, 'La fiche de liquidation s’émet après le visa.');
        $liquidation->loadMissing(['engagement.expressionBesoin.exercice', 'engagement.budgetLine']);
        $engagement = $liquidation->engagement;
        $retenues = (int) $liquidation->retenue_garantie + (int) $liquidation->penalite;

        return $this->enveloppe('FICHE DE LIQUIDATION', 'Décompte visé', $liquidation->reference, (string) ($engagement?->expressionBesoin?->exercice?->annee ?? ''), 'Visa '.$liquidation->visa_reference, [
            $this->kv('01  IDENTIFICATION', [
                ['Engagement', $engagement?->reference ?: 'Non renseigné'],
                ['Facture', ($liquidation->invoice_number ?: 'Non renseignée').' · '.($liquidation->invoice_date?->format('d/m/Y') ?: 'date non renseignée')],
                ['Imputation', $engagement ? $this->ligne($engagement) : 'Non renseignée'],
                ['Prestataire', $liquidation->fournisseur ?: 'Non renseigné'],
            ]),
            $this->tableau('02  DÉCOMPTE', ['Élément', 'Montant XAF'], [
                ['Montant accepté', $this->montant((int) $liquidation->montant_accepte)],
                ['Montant brut', $this->montant((int) $liquidation->montant_brut)],
                ['Retenue de garantie', $this->montant((int) $liquidation->retenue_garantie)],
                ['Pénalité', $this->montant((int) $liquidation->penalite)],
                ['Net visé', $this->montant((int) $liquidation->montant_net)],
            ], 'NET LIQUIDÉ', $this->montant((int) $liquidation->montant_net), (int) $liquidation->montant_net),
            ['titre' => '03  RETENUES', 'texte' => 'Retenues et pénalités imputées une seule fois : '.$this->montant($retenues).' XAF. Elles ne sont pas déduites de nouveau à l’ordonnancement.'],
        ], 'Le modèle vierge de fiche de liquidation n’était pas joint : cette édition reprend le décompte enregistré.');
    }

    /**
     * @return array<string, mixed>
     */
    public function ordonnancement(Ordonnancement $ordre): array
    {
        $this->require($ordre->status?->signed() === true, 'La fiche d’ordonnancement s’émet après la signature.');
        $ordre->loadMissing(['liquidation.engagement.expressionBesoin.exercice', 'liquidation.engagement.budgetLine', 'signataire']);
        $liquidation = $ordre->liquidation;
        $engagement = $liquidation?->engagement;

        return $this->enveloppe('FICHE D’ORDONNANCEMENT', 'Ordre et signature de l’ordonnateur', $ordre->reference, (string) ($engagement?->expressionBesoin?->exercice?->annee ?? ''), (string) ($ordre->signature_reference ?: $ordre->status?->label()), [
            $this->kv('01  IDENTIFICATION', [
                ['Liquidation / engagement', ($liquidation?->reference ?: '—').' / '.($engagement?->reference ?: '—')],
                ['Bénéficiaire', $liquidation?->fournisseur ?: $engagement?->beneficiary_name ?: 'Non renseigné'],
                ['Imputation', $engagement ? $this->ligne($engagement) : 'Non renseignée'],
                ['Ordonnateur compétent', ($ordre->ordonnateur_label ?: $ordre->ordonnateur_role ?: 'Non renseigné')],
            ]),
            ['titre' => '02  OBJET', 'texte' => $engagement?->expressionBesoin?->objet ?: 'Non renseigné'],
            $this->tableau('03  DÉCOMPTE', ['Élément', 'Montant XAF'], [
                ['Net liquidé', $this->montant((int) $liquidation?->montant_net)],
                ['Montant de cet ordre', $this->montant((int) $ordre->montant)],
            ], 'NET À ORDONNANCER', $this->montant((int) $ordre->montant), (int) $ordre->montant),
            $this->signatures('04  SIGNATURE', [[
                'role' => 'Ordonnateur',
                'nom' => $ordre->signataire?->name ?: 'Non enregistré',
                'date' => $ordre->signed_at?->format('d/m/Y H:i') ?: 'Non enregistrée',
                'decision' => 'Signé '.($ordre->signature_reference ?: ''),
            ]]),
        ], 'La signature ne vaut pas transmission à l’Agence Comptable ni paiement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function bordereau(Ordonnancement $ordre): array
    {
        $this->require(filled($ordre->paiement_reference), 'Le bordereau s’émet lorsque la transmission à l’Agence Comptable est accusée.');
        $ordre->loadMissing(['liquidation.engagement.expressionBesoin.exercice', 'signataire']);
        $liquidation = $ordre->liquidation;

        return $this->enveloppe('BORDEREAU DE TRANSMISSION', 'Transmission de l’ordre à l’Agence Comptable', $ordre->reference, (string) ($liquidation?->engagement?->expressionBesoin?->exercice?->annee ?? ''), 'Accusé '.$ordre->paiement_reference, [
            $this->kv('01  TRANSMISSION', [
                ['Émetteur', $ordre->signataire?->name ?: ($ordre->ordonnateur_label ?: 'Ordonnateur')],
                ['Destinataire', 'Agence Comptable'],
                ['Signature de l’ordre', $ordre->signature_reference ?: 'Non renseignée'],
                ['Dossier transmis', $ordre->reference.' / '.($liquidation?->reference ?: '—').' / '.($liquidation?->engagement?->reference ?: '—')],
                ['Bénéficiaire / montant', ($liquidation?->fournisseur ?: '—').' · '.$this->montant((int) $ordre->montant).' XAF'],
                ['Accusé', $ordre->paiement_reference],
            ]),
        ], 'La transmission ne vaut pas exécution du paiement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function paiement(Paiement $paiement): array
    {
        $this->require($paiement->status?->countsAsPaid() === true, 'La fiche de paiement s’émet après une exécution.');
        $paiement->loadMissing(['ordonnancement.liquidation.engagement.expressionBesoin.exercice', 'ordonnancement.liquidation.engagement.budgetLine', 'executions', 'signataire']);
        $ordre = $paiement->ordonnancement;
        $engagement = $ordre?->liquidation?->engagement;
        $anterieurs = max(0, (int) $paiement->montant_paye - (int) $paiement->executions->sortByDesc('rang')->first()?->montant);
        $present = (int) $paiement->executions->sortByDesc('rang')->first()?->montant;

        return $this->enveloppe('FICHE DE PAIEMENT', 'Autorisation, exécution et solde', $paiement->reference, (string) ($engagement?->expressionBesoin?->exercice?->annee ?? ''), (string) $paiement->status?->label(), [
            $this->kv('01  IDENTIFICATION', [
                ['Ordre', $ordre?->reference ?: 'Non renseigné'],
                ['Bénéficiaire', $ordre?->liquidation?->fournisseur ?: 'Non renseigné'],
                ['Mode', $this->mode((string) $paiement->mode)],
                ['Imputation', $engagement ? $this->ligne($engagement) : 'Non renseignée'],
            ]),
            $this->tableau('02  SOLDE', ['Élément', 'Montant XAF'], [
                ['Net de l’ordre', $this->montant((int) $paiement->montant)],
                ['Paiements antérieurs', $this->montant($anterieurs)],
                ['Présent paiement', $this->montant($present)],
                ['Reste à payer', $this->montant($paiement->reste())],
            ], 'MONTANT DU PRÉSENT PAIEMENT', $this->montant($present), $present),
            $this->signatures('03  EXÉCUTION', [[
                'role' => 'Agent comptable',
                'nom' => $paiement->signataire?->name ?: 'Non enregistré',
                'date' => $paiement->signed_at?->format('d/m/Y H:i') ?: 'Non enregistrée',
                'decision' => 'Exécuté '.($paiement->reference_reglement ?: ''),
            ]]),
        ], 'Un paiement partiel ne solde pas l’ordre. Le rapprochement fait l’objet d’un acte distinct.');
    }

    /**
     * @return array<string, mixed>
     */
    public function virement(Paiement $paiement): array
    {
        $this->require($paiement->mode === 'virement', 'Un ordre de virement n’est pas émis pour un autre mode de paiement.');
        $this->require(filled($paiement->compte), 'Le compte bénéficiaire est obligatoire pour émettre l’ordre de virement.');
        $this->require($paiement->status?->countsAsPaid() === true, 'L’ordre de virement s’émet après l’exécution enregistrée.');
        $paiement->loadMissing(['ordonnancement.liquidation', 'bankAccount', 'signataire']);

        return $this->enveloppe('ORDRE DE VIREMENT', 'Instruction bancaire enregistrée', $paiement->reference, $this->annee($paiement), (string) ($paiement->reference_reglement ?: 'Exécuté'), [
            $this->kv('01  INSTRUCTION', [
                ['Ordre de paiement', $paiement->ordonnancement?->reference ?: 'Non renseigné'],
                ['Montant', $this->montant((int) $paiement->montant_paye).' XAF'],
                ['Compte à débiter', $paiement->compte_ceeac ?: 'Non renseigné'],
                ['Bénéficiaire', $paiement->titulaire ?: $paiement->ordonnancement?->liquidation?->fournisseur ?: 'Non renseigné'],
                ['Banque / compte bénéficiaire', trim(($paiement->banque ?: '').' '.$paiement->compte) ?: 'Non renseigné'],
                ['Référence communiquée', $paiement->reference_reglement ?: 'Non renseignée'],
            ]),
            $this->signatures('02  HABILITATION', [[
                'role' => 'Signataire enregistré',
                'nom' => $paiement->signataire?->name ?: 'Non enregistré',
                'date' => $paiement->date_valeur?->format('d/m/Y') ?: 'Non enregistrée',
                'decision' => 'Exécution enregistrée, distincte de cet ordre',
            ]]),
        ], 'Ce document constate l’instruction enregistrée. Il ne déclenche pas lui-même le virement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function cheque(Paiement $paiement): array
    {
        $this->require($paiement->mode === 'cheque', 'Un bordereau de chèque n’est pas émis pour un autre mode de paiement.');
        $this->require(filled($paiement->reference_reglement), 'Le numéro de chèque est obligatoire.');
        $paiement->loadMissing(['ordonnancement.liquidation']);

        return $this->enveloppe('BORDEREAU DE REMISE DE CHÈQUE', 'Remise au bénéficiaire, distincte de l’encaissement', $paiement->reference, $this->annee($paiement), $paiement->reference_reglement, [
            $this->kv('01  CHÈQUE', [
                ['Ordre', $paiement->ordonnancement?->reference ?: 'Non renseigné'],
                ['Banque émettrice', $paiement->banque ?: 'Non renseignée'],
                ['Numéro', $paiement->reference_reglement],
                ['Bénéficiaire / montant', ($paiement->ordonnancement?->liquidation?->fournisseur ?: '—').' · '.$this->montant((int) $paiement->montant_paye).' XAF'],
                ['Date de valeur enregistrée', $paiement->date_valeur?->format('d/m/Y') ?: 'Non enregistrée'],
            ]),
        ], 'La remise du chèque ne constate pas son encaissement.');
    }

    /**
     * @return array<string, mixed>
     */
    public function caisse(Paiement $paiement): array
    {
        $this->require($paiement->mode === 'caisse', 'Un bon de sortie de caisse n’est pas émis pour un autre mode de paiement.');
        $this->require((int) $paiement->montant_paye > 0, 'Le décaissement enregistré est obligatoire.');
        $paiement->loadMissing(['ordonnancement.liquidation', 'signataire']);

        return $this->enveloppe('BON DE SORTIE DE CAISSE', 'Décaissement en espèces enregistré', $paiement->reference, $this->annee($paiement), (string) ($paiement->reference_reglement ?: 'Exécuté'), [
            $this->kv('01  DÉCAISSEMENT', [
                ['Ordre', $paiement->ordonnancement?->reference ?: 'Non renseigné'],
                ['Bénéficiaire', $paiement->ordonnancement?->liquidation?->fournisseur ?: 'Non renseigné'],
                ['Montant sorti', $this->montant((int) $paiement->montant_paye).' XAF'],
                ['Référence', $paiement->reference_reglement ?: 'Non renseignée'],
                ['Signataire', $paiement->signataire?->name ?: 'Non enregistré'],
            ]),
        ], 'Ce bon justifie le décaissement enregistré. Il ne tient pas lieu de rapprochement de caisse.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rapprochement(Paiement $paiement): array
    {
        $this->require(filled($paiement->reconciliation_reference), 'La fiche de rapprochement s’émet lorsque le rapprochement est enregistré.');
        $paiement->loadMissing(['ordonnancement', 'executions']);
        $ecart = (int) $paiement->montant - (int) $paiement->montant_paye;

        return $this->enveloppe('FICHE DE RAPPROCHEMENT', 'Comparaison de l’ordre et des exécutions', $paiement->reference, $this->annee($paiement), $paiement->reconciliation_reference, [
            $this->kv('01  COMPARAISON', [
                ['Ordre', $paiement->ordonnancement?->reference ?: 'Non renseigné'],
                ['Montant ordonnancé', $this->montant((int) $paiement->montant).' XAF'],
                ['Montant exécuté', $this->montant((int) $paiement->montant_paye).' XAF'],
                ['Écart restant', $this->montant($ecart).' XAF'],
                ['Référence de rapprochement', $paiement->reconciliation_reference],
                ['Date', $paiement->reconciled_at?->format('d/m/Y H:i') ?: 'Non enregistrée'],
            ]),
            ['titre' => '02  TRAITEMENT', 'texte' => $ecart === 0 ? 'Aucun écart. Le paiement est soldé.' : 'Écart laissé ouvert : '.$this->montant($ecart).' XAF. Il n’est pas réécrit sur les exécutions déjà archivées.'],
        ], 'Le rapprochement ne modifie pas les fiches de paiement déjà émises.');
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function enveloppe(string $titre, string $soustitre, string $reference, string $exercice, string $statut, array $sections, string $mention): array
    {
        return [
            'titre' => $titre,
            'soustitre' => $soustitre,
            'reference' => $reference,
            'exercice' => $exercice !== '' ? $exercice : 'Non renseigné',
            'statut' => $statut !== '' ? $statut : 'Non renseigné',
            'logo' => $this->logo(),
            'sections' => $sections,
            'mention' => $mention,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lignes
     * @return array<string, mixed>
     */
    private function kv(string $titre, array $lignes): array
    {
        return [
            'titre' => $titre,
            'lignes' => array_map(fn (array $ligne): array => ['libelle' => $ligne[0], 'valeur' => $ligne[1]], $lignes),
        ];
    }

    /**
     * @param  list<string>  $colonnes
     * @param  list<list<string>>  $lignes
     * @return array<string, mixed>
     */
    private function tableau(string $titre, array $colonnes, array $lignes, ?string $totalLibelle, ?string $totalValeur, ?int $lettres): array
    {
        return [
            'titre' => $titre,
            'tableau' => [
                'colonnes' => $colonnes,
                'lignes' => $lignes,
                'total' => $totalLibelle === null ? null : ['libelle' => $totalLibelle, 'valeur' => $totalValeur.' XAF'],
                'lettres' => $lettres === null ? null : $this->lettres($lettres),
            ],
        ];
    }

    /**
     * @param  list<array{role: string, nom: string, date: string, decision: string}>  $signataires
     * @return array<string, mixed>
     */
    private function signatures(string $titre, array $signataires): array
    {
        return ['titre' => $titre, 'signataires' => $signataires];
    }

    /**
     * @return list<list<string>>
     */
    private function lignesBesoin(?ExpressionBesoin $eb): array
    {
        if ($eb === null || $eb->lines->isEmpty()) {
            return [];
        }

        return $eb->lines->map(fn ($row): array => [
            (string) $row->designation,
            rtrim(rtrim(number_format((float) $row->quantite, 2, ',', ' '), '0'), ','),
            (string) ($row->unite ?: '—'),
            $this->montant((int) $row->prix_unitaire),
            $this->montant((int) $row->montant),
        ])->all();
    }

    private function ligne(Engagement $engagement): string
    {
        $line = $engagement->budgetLine;

        return $line ? $line->code.' — '.$line->label : 'Non renseignée';
    }

    private function annee(Paiement $paiement): string
    {
        return (string) ($paiement->ordonnancement?->liquidation?->engagement?->expressionBesoin?->exercice?->annee ?? '');
    }

    private function mode(string $mode): string
    {
        return match ($mode) {
            'virement' => 'Virement',
            'cheque' => 'Chèque',
            'caisse' => 'Caisse',
            default => 'Non renseigné',
        };
    }

    private function montant(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    private function lettres(int $amount): string
    {
        if ($amount === 0) {
            return 'zéro franc CFA';
        }

        return trim($this->groupe(abs($amount))).($amount < 0 ? ' négatifs' : '').' francs CFA';
    }

    private function groupe(int $nombre): string
    {
        $unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
        if ($nombre < 17) {
            return $unites[$nombre];
        }
        if ($nombre < 20) {
            return 'dix-'.$unites[$nombre - 10];
        }
        if ($nombre < 100) {
            $dizaine = intdiv($nombre, 10);
            $reste = $nombre % 10;
            $nom = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'][$dizaine];
            $complement = $dizaine === 7 || $dizaine === 9 ? $nombre - (($dizaine - 1) * 10) : $reste;
            if ($dizaine === 7 || $dizaine === 9) {
                return trim($nom.'-'.$this->groupe($complement));
            }
            if ($reste === 0) {
                return $nom.($dizaine === 8 ? 's' : '');
            }
            if ($reste === 1 && $dizaine < 8) {
                return $nom.' et un';
            }

            return $nom.'-'.$unites[$reste];
        }
        if ($nombre < 1000) {
            $centaines = intdiv($nombre, 100);
            $reste = $nombre % 100;
            $nom = ($centaines > 1 ? $this->groupe($centaines).' ' : '').'cent'.($centaines > 1 && $reste === 0 ? 's' : '');

            return trim($nom.' '.$this->groupe($reste));
        }
        if ($nombre < 1_000_000) {
            return $this->echelle($nombre, 1000, 'mille');
        }
        if ($nombre < 1_000_000_000) {
            return $this->echelle($nombre, 1_000_000, 'million');
        }

        return $this->echelle($nombre, 1_000_000_000, 'milliard');
    }

    private function echelle(int $nombre, int $base, string $mot): string
    {
        $quotien = intdiv($nombre, $base);
        $reste = $nombre % $base;
        $nom = ($mot === 'mille' && $quotien === 1 ? '' : $this->groupe($quotien).' ').$mot.($quotien > 1 && $mot !== 'mille' ? 's' : '');

        return trim($nom.' '.$this->groupe($reste));
    }

    private function logo(): string
    {
        $path = resource_path('images/logo-ceeac.png');
        if (! is_file($path)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }

    /**
     * @return list<string>
     */
    private function candidates(Model $model): array
    {
        return match (true) {
            $model instanceof Engagement => ['engagement', 'controle_budgetaire'],
            $model instanceof Liquidation => ['attestation_service_fait', 'pv_reception', 'liquidation'],
            $model instanceof Ordonnancement => ['ordonnancement', 'bordereau_transmission'],
            $model instanceof Paiement => ['paiement', 'ordre_virement', 'bordereau_cheque', 'bon_sortie_caisse', 'rapprochement'],
            default => [],
        };
    }

    private function asEngagement(Model $model): Engagement
    {
        return $model instanceof Engagement ? $model : throw ValidationException::withMessages(['document' => 'Cet acte se rattache à un engagement.']);
    }

    private function asLiquidation(Model $model): Liquidation
    {
        return $model instanceof Liquidation ? $model : throw ValidationException::withMessages(['document' => 'Cet acte se rattache à une liquidation.']);
    }

    private function asOrdre(Model $model): Ordonnancement
    {
        return $model instanceof Ordonnancement ? $model : throw ValidationException::withMessages(['document' => 'Cet acte se rattache à un ordonnancement.']);
    }

    private function asPaiement(Model $model): Paiement
    {
        return $model instanceof Paiement ? $model : throw ValidationException::withMessages(['document' => 'Cet acte se rattache à un paiement.']);
    }
}
