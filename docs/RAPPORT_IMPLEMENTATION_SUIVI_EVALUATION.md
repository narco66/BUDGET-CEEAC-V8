# Rapport d’implémentation — Suivi-Évaluation

## 1. État initial

Le PAP et le budget existaient. Le suivi physique, les indicateurs, les écarts et les écrans de la maquette n’existaient pas. Aucune donnée fictive de performance n’était codée dans React.

## 2. Sources

Description détaillée du module, cahier des charges (M08 et règles transverses), `docs/maquette-SE/` (six écrans), modèle PAP et chaîne de dépense déjà en place.

## 3. Écarts traités

Le module a été ajouté sur le PAP existant. Les montants sont lus. L’avancement physique est saisi à part. Une valeur validée n’est pas écrasée : une rectification crée une version.

## 4. Corrections

- Migration `2026_10_01_202547_create_monitoring_evaluation_tables`, appliquée sur `budget_ceeac_v8`.
- Poids et avancement ajoutés à `pap_tasks`.
- Transitions semées : brouillon → soumis → valide, avec rejet et retour en correction.
- Période `2026-T1` semée.

## 5. Architecture

`App\Domains\Monitoring` : modèles, `IndicatorCalculationService`, `FinancialExecutionService`, `MonitoringService`, contrôleur, Form Requests, policy, export Excel. Le contrôleur valide, autorise et délègue. Les taux ne sont pas recalculés dans React.

## 6. Modèle

`monitoring_periods`, `se_transitions`, `indicators`, `indicator_targets`, `indicator_measurements`, `physical_achievements`, `performance_variances`, `corrective_actions`, `se_risks`, `se_recommendations`, `se_evaluations`, `se_proofs`. Les pourcentages et valeurs d’indicateurs sont en `decimal`. Les montants restent des entiers FCFA, comme le reste de GESBUDEP.

## 7. API

Préfixe authentifié `/api/v1/suivi` : tableau de bord, activités, indicateurs, mesures, réalisations, écarts, mesures correctives, risques, recommandations, évaluations, consolidation, Gantt, preuves, rapports.

## 8. Frontend

`frontend/src/features/monitoring/pages` : tableau de bord, fiche activité, Gantt, saisie, écarts, synthèse. Navigation ajoutée dans le bandeau. Aucun KPI n’est écrit en dur.

## 9. Workflow

Le passage de statut lit `se_transitions`. La validation d’une mesure exige une preuve et un rôle directeur, directeur du budget ou contrôleur financier.

## 10. Calculs

- Croissant : réalisé / cible × 100.
- Décroissant : cible / réalisé × 100. Une baisse améliore le taux.
- Binaire : 100 si le réalisé atteint la cible, sinon 0.
- Qualitatif : pas de taux.
- Physique pondéré : somme (avancement × poids) / somme des poids.
- Exécution financière comparée au physique : payé / budget révisé × 100. Les taux d’engagement, de liquidation, d’ordonnancement et de paiement sont aussi exposés.
- Le taux validé est stocké avec `formula_version`. Changer la version ensuite ne réécrit pas le taux officiel.
- Seuil d’alerte : règle `seuil_ecart_se` si elle est active, sinon 30 points.

## 11. GED

Chaque preuve reçoit un chemin, un SHA-256, une catégorie, un auteur et une unité. Le PDF de rapport est archivé de la même façon. Les actes officiels de la chaîne de dépense ne sont pas réutilisés comme pièces de suivi.

## 12. Sécurité

`MonitoringPolicy` et le service refusent une activité hors unité. Les rôles directeur du budget, contrôleur financier, auditeur, administrateurs, secrétaire général et ordonnateur voient l’ensemble.

## 13. Reporting

Le tableau de bord, la consolidation et les exports lisent les mêmes cartes d’activité. Le drill-down va du pilier à l’activité. Le payé du forum régional (228 000 000 FCFA, 25,33 %) reste sur cette seule ligne quand on descend au niveau activité.

## 14. Tests

- Unitaires : croissant, décroissant, binaire, qualitatif, pondération.
- `SuiviEvaluationTest` : création, soumission, blocage sans preuve, validation, rectification versionnée, indicateur décroissant, tâches pondérées, paiement repris sans ressaisie, refus d’un montant saisi, écart critique, mesure sans responsable, accès hors périmètre, consolidation, risque critique, recommandation échue.
- 9 tests S&E et 45 tests de la chaîne, de l’administration et de Mes tâches : tous verts.
- `npx tsc --noEmit` : vert.
- Navigateur : connexion du directeur du budget, tableau de bord, drill-down, fiche budget (50 000 000 révisés, 4 600 000 engagés, 4 125 100 liquidés), Gantt, saisie d’une réalisation 30/100, synthèse et écran d’écart. Cette saisie de vérification est restée en base, au statut brouillon, sur « Mise en place du système de suivi régional ». L’écran d’écart affiche 30 % physique contre 0 % payé.

## 15. Écarts encore ouverts

La fiche 360, le Gantt calendaire, l’agrégation des indicateurs, le score composite et les référentiels de causes et de critères sont en place. Restent les courbes cible / réalisé, les campagnes de collecte multi-structures et le circuit jusqu’à la clôture au-delà de brouillon → soumis → validé.

## 16. Contre-audit du 1er octobre 2026 (soir)

Le module livré plus tôt dans la journée a été réaudité contre la description détaillée et le prompt d’implémentation. Les écarts suivants étaient présents dans le code alors que la matrice les déclarait conformes. Ils sont corrigés et couverts par `SuiviEvaluationIntegriteTest` (11 tests).

| # | Constat | Gravité | Correction |
|---|---|---|---|
| SE-C1 | `FinancialExecutionService` recalculait les montants avec ses propres règles : une liquidation non visée comptait comme liquidée, le payé était lu sur le paiement au lieu des exécutions bancaires. Le S&E et la page Budget divergeaient (exemple réel : 4 125 100 FCFA « liquidés » sur la ligne 203232, contre 0 en réalité). | Élevée | Délégation intégrale à `BudgetBalanceService`, source unique. Chargement groupé des soldes (fin du N+1). |
| SE-C2 | Un brouillon de réalisation écrivait immédiatement l’avancement de la tâche, et le taux physique reprenait la dernière réalisation quel que soit son statut. Une saisie non validée devenait officielle (exemple réel : 30 % affichés sur la base de développement). | Élevée | Circuit soumettre → valider / rejeter / retourner pour les réalisations, preuve obligatoire. Seules les réalisations validées alimentent tâches et taux. Les avancements de tâches issus de brouillons sont remis à zéro par migration. |
| SE-C3 | L’auteur d’une mesure pouvait la valider lui-même ; un rejet ne portait pas de motif. | Élevée | Séparation saisie / validation (description §81), motif obligatoire, verrou de transition. |
| SE-C4 | Plusieurs valeurs actives possibles pour un même indicateur et une même période ; agrégations et score lisaient brouillons et valeurs rejetées. | Élevée | Une seule valeur active par indicateur et période ; agrégation, score et courbes ne lisent que les valeurs validées. |
| SE-C5 | La rectification marquait la valeur validée comme remplacée avant validation de la nouvelle version ; une valeur validée restait modifiable et supprimable. | Élevée | La valeur validée reste la référence jusqu’à validation de la rectification. Trait `ProtectsValidatedValues` et trigger PostgreSQL `gesbudep_protect_validated_se`. Clés étrangères en `restrict`. |
| SE-C6 | Listes d’indicateurs, écarts, risques, recommandations et compteurs du tableau de bord non filtrés par périmètre ; création d’une mesure corrective sans contrôle de périmètre ni audit. | Élevée | Filtrage systématique par activités visibles ; contrôle et audit à la création. |
| SE-C7 | Mesures correctives, risques et recommandations : création seulement, aucun suivi. Une recommandation « réalisée » restait « en retard ». | Moyenne | `FollowUpService` : avancement, statut, revue de risque, clôture avec preuve, historique depuis le journal d’audit. Relance quotidienne `suivi:relances` (07 h 30) avec escalade au directeur. |
| SE-C8 | Le PDF de rapport était régénéré et archivé comme preuve orpheline à chaque téléchargement, sans version ni validation. | Moyenne | Rapports de performance : snapshot figé, revue, validation par un autre acteur, publication avec PDF officiel archivé (version, SHA-256, code de vérification), nouvelle version pour corriger. Le PDF à la volée reste un document de travail non archivé. |
| SE-C9 | Pas d’écran de saisie des mesures, ni d’écran de validation. | Moyenne | Page « Saisie et validation » avec file de travail (`GET /suivi/saisies`) ; pages « Suivi des actions » et « Rapports ». |
| SE-C10 | Courbes cible / réalisé et statut de performance §20 absents. | Moyenne | `GET /suivi/indicateurs/{id}/evolution`, statut paramétrable (`se_seuil_*`), courbe accessible dans la fiche activité. |

### Nouvelles routes

`GET /suivi/saisies` · `POST /suivi/mesures/{id}/{rejeter|corriger}` · `POST /suivi/realisations/{id}/{soumettre|valider|rejeter|corriger}` · `GET|PATCH /suivi/mesures-correctives` · `PATCH /suivi/risques/{id}` · `PATCH /suivi/recommandations/{id}` · `GET /suivi/{type}/{id}/historique` · `GET /suivi/indicateurs/{id}/evolution` · `GET|POST /suivi/rapports-performance` et transitions, nouvelle version, PDF.

### Écarts restant ouverts

- Campagnes de collecte multi-structures et clôture de période : le statut « consolidé » n’est pas encore atteignable.
- Rappels J-7 / J-3 sur les indicateurs à renseigner (les relances couvrent les mesures correctives et recommandations échues).
- Responsables désignés par rôle, non par personne ni par affectation datée (prompt §34).
- Décisions de revue de performance (§88), heatmaps (§84), datasets Power BI (§74), imports (§72).
- Impact des retards sur les tâches dépendantes du Gantt (§30).
- Conformité visuelle à `docs/maquette-SE/` et rendu de la courbe d’évolution non vérifiés dans un navigateur lors de ce contre-audit.

## 17. Mise en conformité stricte avec docs/maquette-SE (2 octobre 2026)

Les six écrans ont été réécrits d’après les maquettes, avec des données lues dans PostgreSQL par l’API. Le détail, écran par écran, figure dans `MATRICE_CONFORMITE_MAQUETTE_SE.md`.

Compléments backend :
- `MeasurementEntryService` : `controles` (6 contrôles de qualité, seuil d’évolution `se_evolution_alerte`, 20 par défaut) et `historique` (dernière version par période, avec la situation figée qui l’a capturée).
- `VarianceDossierService` : `finances` (budget révisé, engagé, liquidé, payé), `notifications` (alertes réellement envoyées) et `prochain_rapport`.
- `GET /suivi/ecarts` renvoie le code et le libellé de l’activité.

Nouvelles routes frontend : `/suivi/activites/:id/gantt`, `/suivi/indicateurs/:id/saisie`, `/suivi/ecarts/:id`.
