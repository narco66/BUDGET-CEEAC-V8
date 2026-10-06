<?php

namespace App\Domains\Revenues\Services;

class RevenueLexicon
{
    /** @var list<string> */
    public const DEBTORS = [
        'etat_membre', 'partenaire', 'organisation', 'fournisseur', 'agent',
        'institution', 'organisme', 'tiers', 'autre',
    ];

    /** @var list<string> */
    public const REMINDERS = ['premiere', 'deuxieme', 'mise_en_demeure', 'rappel_institutionnel', 'personnalisee'];

    /** @var list<string> */
    public const ADJUSTMENTS = ['avoir', 'avance', 'remboursement', 'regularisation'];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'brouillon' => 'Brouillon',
            'soumis' => 'Soumis',
            'verifie' => 'Vérifié',
            'valide' => 'Validé',
            'pris_en_charge' => 'Pris en charge',
            'partiellement_encaisse' => 'Partiellement encaissé',
            'solde' => 'Soldé',
            'rejete' => 'Rejeté',
            'annule' => 'Annulé',
            'suspendu' => 'Suspendu',
            'a_appeler' => 'À appeler',
            'appelee' => 'Appelée',
            'partiellement_payee' => 'Partiellement payée',
            'soldee' => 'Soldée',
            'echue' => 'Échue',
            'en_retard' => 'En retard',
            'non_rapproche' => 'Non rapproché',
            'rapproche' => 'Rapproché',
            'anomalie' => 'Anomalie',
            'non_identifie' => 'Non identifié',
            'etat_membre' => 'État membre',
            'partenaire' => 'Partenaire',
            'organisation' => 'Organisation internationale',
            'fournisseur' => 'Fournisseur',
            'agent' => 'Agent',
            'institution' => 'Institution',
            'organisme' => 'Organisme',
            'tiers' => 'Tiers',
            'autre' => 'Autre',
            'premiere' => 'Première relance',
            'deuxieme' => 'Deuxième relance',
            'mise_en_demeure' => 'Mise en demeure',
            'rappel_institutionnel' => 'Rappel institutionnel',
            'personnalisee' => 'Relance personnalisée',
            'avoir' => 'Avoir',
            'avance' => 'Avance',
            'remboursement' => 'Remboursement',
            'regularisation' => 'Régularisation',
            'non_echue' => 'Non échue',
            'j1_30' => '1 à 30 jours',
            'j31_60' => '31 à 60 jours',
            'j61_90' => '61 à 90 jours',
            'j91_180' => '91 à 180 jours',
            'j180' => 'Plus de 180 jours',
        ];
    }

    public static function label(string $code): string
    {
        return self::labels()[$code] ?? $code;
    }
}
