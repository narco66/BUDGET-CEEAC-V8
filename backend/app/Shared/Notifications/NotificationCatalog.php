<?php

namespace App\Shared\Notifications;

/**
 * Types de cibles et chemins internes autorisés.
 * Aucune URL externe ni chemin hors de cette liste n’est suivi.
 */
final class NotificationCatalog
{
    /**
     * @var array<string, array{module: string, libelle: string, chemin: string, liste: string, indices: list<string>}>
     */
    public const TYPES = [
        'expression_besoin' => [
            'module' => 'depense',
            'libelle' => 'Expression de besoin',
            'chemin' => '/expressions-besoin/{id}',
            'liste' => '/expressions-besoin',
            'indices' => ['expression_besoin_id', '"type":"expression_besoin"', '/expressions-besoin/'],
        ],
        'engagement' => [
            'module' => 'depense',
            'libelle' => 'Engagement',
            'chemin' => '/engagements/{id}',
            'liste' => '/engagements',
            'indices' => ['engagement_id', '"type":"engagement"', '/engagements/'],
        ],
        'liquidation' => [
            'module' => 'depense',
            'libelle' => 'Liquidation',
            'chemin' => '/liquidations/{id}',
            'liste' => '/liquidations',
            'indices' => ['liquidation_id', '"type":"liquidation"', '/liquidations/'],
        ],
        'ordonnancement' => [
            'module' => 'depense',
            'libelle' => 'Ordonnancement',
            'chemin' => '/ordonnancements/{id}',
            'liste' => '/ordonnancements',
            'indices' => ['ordonnancement_id', '"type":"ordonnancement"', '/ordonnancements/'],
        ],
        'paiement' => [
            'module' => 'depense',
            'libelle' => 'Paiement',
            'chemin' => '/paiements/{id}',
            'liste' => '/paiements',
            'indices' => ['paiement_id', '"type":"paiement"', '/paiements/'],
        ],
        'tache' => [
            'module' => 'taches',
            'libelle' => 'Tâche',
            'chemin' => '/taches/{id}',
            'liste' => '/taches',
            'indices' => ['tache_id', 'workflow_task_id', '"type":"tache"', '/taches/'],
        ],
        'prevision' => [
            'module' => 'recettes',
            'libelle' => 'Prévision de recette',
            'chemin' => '/recettes/previsions/{id}',
            'liste' => '/recettes/previsions',
            'indices' => ['prevision_id', 'forecast_id', '"type":"prevision"', '/recettes/previsions/'],
        ],
        'titre' => [
            'module' => 'recettes',
            'libelle' => 'Titre de recette',
            'chemin' => '/recettes/titres/{id}',
            'liste' => '/recettes/titres',
            'indices' => ['titre_id', 'revenue_order_id', '"type":"titre"', '/recettes/titres/'],
        ],
        'ecart' => [
            'module' => 'suivi',
            'libelle' => 'Écart',
            'chemin' => '/suivi/ecarts/{id}',
            'liste' => '/suivi/ecarts',
            'indices' => ['ecart_id', 'variance_id', '"type":"ecart"', '/suivi/ecarts/', '\\/suivi\\/ecarts\\/'],
        ],
        'activite' => [
            'module' => 'suivi',
            'libelle' => 'Activité',
            'chemin' => '/suivi/activites/{id}',
            'liste' => '/suivi',
            'indices' => ['activite_id', 'pap_enrichment_id', '"type":"activite"'],
        ],
        'activite_gantt' => [
            'module' => 'suivi',
            'libelle' => 'Gantt de l’activité',
            'chemin' => '/suivi/activites/{id}/gantt',
            'liste' => '/suivi/gantt',
            'indices' => ['"type":"activite_gantt"'],
        ],
        'indicateur' => [
            'module' => 'suivi',
            'libelle' => 'Indicateur',
            'chemin' => '/suivi/indicateurs/{id}/saisie',
            'liste' => '/suivi/saisie',
            'indices' => ['indicator_id', '"type":"indicateur"', '/suivi/indicateurs/'],
        ],
        'saisie' => [
            'module' => 'suivi',
            'libelle' => 'Saisie et validation',
            'chemin' => '/suivi/saisie',
            'liste' => '/suivi/saisie',
            'indices' => ['"type":"saisie"', '/suivi/saisie'],
        ],
        'synthese' => [
            'module' => 'suivi',
            'libelle' => 'Synthèse',
            'chemin' => '/suivi/synthese',
            'liste' => '/suivi/synthese',
            'indices' => ['"type":"synthese"', '/suivi/synthese'],
        ],
        'rapports' => [
            'module' => 'suivi',
            'libelle' => 'Rapports',
            'chemin' => '/suivi/rapports',
            'liste' => '/suivi/rapports',
            'indices' => ['"type":"rapports"', '/suivi/rapports'],
        ],
        'ecarts' => [
            'module' => 'suivi',
            'libelle' => 'Écarts',
            'chemin' => '/suivi/ecarts',
            'liste' => '/suivi/ecarts',
            'indices' => ['"type":"ecarts"', '/suivi/ecarts"', '\\/suivi\\/ecarts"'],
        ],
        'rapprochements' => [
            'module' => 'recettes',
            'libelle' => 'Rapprochements des recettes',
            'chemin' => '/recettes/rapprochements',
            'liste' => '/recettes/rapprochements',
            'indices' => ['"type":"rapprochements"', '/recettes/rapprochements'],
        ],
        'campagne' => [
            'module' => 'budget',
            'libelle' => 'Campagne budgétaire',
            'chemin' => '/preparation/campagnes/{id}',
            'liste' => '/preparation/campagnes',
            'indices' => ['"type":"campagne"', '/preparation/campagnes/'],
        ],
        'dossier_budget' => [
            'module' => 'budget',
            'libelle' => 'Proposition budgétaire',
            'chemin' => '/preparation/dossiers/{id}',
            'liste' => '/preparation/dossiers',
            'indices' => ['"type":"dossier_budget"', '/preparation/dossiers/'],
        ],
        'ligne' => [
            'module' => 'budget',
            'libelle' => 'Ligne budgétaire',
            'chemin' => '',
            'liste' => '/lignes-budgetaires',
            'indices' => ['budget_line_id', '"type":"ligne"'],
        ],
    ];

    /**
     * Anciens chemins stockés dans `lien`. Le premier motif qui correspond l’emporte.
     *
     * @var array<string, string>
     */
    public const ANCIENS_CHEMINS = [
        '#^/expressions-besoin/(\d+)$#' => 'expression_besoin',
        '#^/engagements/(\d+)$#' => 'engagement',
        '#^/liquidations/(\d+)$#' => 'liquidation',
        '#^/ordonnancements/(\d+)$#' => 'ordonnancement',
        '#^/paiements/(\d+)$#' => 'paiement',
        '#^/taches/(\d+)$#' => 'tache',
        '#^/recettes/previsions/(\d+)$#' => 'prevision',
        '#^/recettes/titres/(\d+)$#' => 'titre',
        '#^/suivi/ecarts/(\d+)$#' => 'ecart',
        '#^/suivi/activites/(\d+)/gantt$#' => 'activite_gantt',
        '#^/suivi/activites/(\d+)$#' => 'activite',
        '#^/suivi/indicateurs/(\d+)/saisie$#' => 'indicateur',
        '#^/suivi/saisie$#' => 'saisie',
        '#^/suivi/synthese$#' => 'synthese',
        '#^/suivi/rapports$#' => 'rapports',
        '#^/suivi/ecarts$#' => 'ecarts',
        '#^/recettes/rapprochements$#' => 'rapprochements',
        '#^/preparation/campagnes/(\d+)$#' => 'campagne',
        '#^/preparation/dossiers/(\d+)$#' => 'dossier_budget',
        '#^/lignes-budgetaires$#' => 'ligne',
    ];

    /**
     * Clés d’identifiant déjà enregistrées, sans déduire quoi que ce soit du texte.
     *
     * @var array<string, string>
     */
    public const CLES_IDENTIFIANT = [
        'expression_besoin_id' => 'expression_besoin',
        'engagement_id' => 'engagement',
        'liquidation_id' => 'liquidation',
        'ordonnancement_id' => 'ordonnancement',
        'paiement_id' => 'paiement',
        'workflow_task_id' => 'tache',
        'tache_id' => 'tache',
        'forecast_id' => 'prevision',
        'prevision_id' => 'prevision',
        'revenue_order_id' => 'titre',
        'titre_id' => 'titre',
        'variance_id' => 'ecart',
        'ecart_id' => 'ecart',
        'pap_enrichment_id' => 'activite',
        'activite_id' => 'activite',
        'indicator_id' => 'indicateur',
        'budget_line_id' => 'ligne',
    ];

    public static function connait(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * @return list<string>
     */
    public static function typesDuModule(string $module): array
    {
        return array_keys(array_filter(
            self::TYPES,
            fn (array $type): bool => $type['module'] === $module,
        ));
    }

    public static function exigeIdentifiant(string $type): bool
    {
        return str_contains(self::TYPES[$type]['chemin'], '{id}');
    }

    public static function chemin(string $type, ?int $id): ?string
    {
        $modele = self::TYPES[$type]['chemin'];
        if ($modele === '') {
            return null;
        }
        if (! str_contains($modele, '{id}')) {
            return $modele;
        }
        if ($id === null || $id < 1) {
            return null;
        }

        return str_replace('{id}', (string) $id, $modele);
    }
}
