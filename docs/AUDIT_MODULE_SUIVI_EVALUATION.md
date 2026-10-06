# Audit du module Suivi-Évaluation

Date : 1er octobre 2026. Périmètre : `backend/`, `frontend/`, PostgreSQL `budget_ceeac_v8`, `docs/maquette-SE/`, description détaillée du module et cahier des charges (module M08).

## Constat initial

Aucun domaine Suivi-Évaluation n’existait. Le PAP était déjà porté par `pap_enrichments` et `pap_tasks` (pilier, axe, produit, sous-produit, activité, tâches). Les montants venaient de `budget_lines` et de la chaîne engagement → liquidation → ordonnancement → paiement. Les pages de production ne contenaient pas de KPI figés pour ce module, parce que le module n’existait pas.

## Matrice

| Domaine | Existant | Attendu | Écart | Priorité | Action |
|---|---|---|---|---|---|
| Chaîne GAR | Une seule chaîne sur `pap_enrichments` | Réutiliser cette chaîne | Aucun doublon à créer | P0 | Conservé |
| Budget / PAP | PAP rattaché à la ligne budgétaire | Pas de budget PAP séparé | Conforme | P0 | Conservé |
| Financier | `creditAutorise`, engagé, liquidé, ordonnancé, payé | Calcul automatique, saisie interdite | Absent de l’écran S&E | P0 | `FinancialExecutionService` |
| Physique | Tâches PAP sans avancement | Réalisations, méthodes, pondération | Absent | P0 | `physical_achievements`, poids sur `pap_tasks` |
| Indicateurs | Texte libre sur le PAP | Fiche, sens, cible, historisation | Absent | P0 | Tables indicateurs, cibles, mesures |
| Workflow | Circuits codés ailleurs | Transitions paramétrées | Absent | P0 | Table `se_transitions` |
| Écarts, risques, mesures, recommandations, évaluations | Textes libres PAP | Registres suivis | Absent | P1 | Tables dédiées liées au PAP |
| Dashboard et maquette | 6 écrans HTML statiques | Écrans branchés sur l’API | Absent | P1 | Pages React `/suivi` |
| Preuves | GED des actes de la chaîne | Preuve hashée rattachée à la mesure | Absent | P1 | `se_proofs` |
| Mes tâches / notifications | Projecteur EB à PAY | Tâches et alertes S&E | Absent | P1 | Projecteur + `MonitoringAlert` |
| Formules | Aucune | Un seul service | Absent | P0 | `IndicatorCalculationService` |

## Maquette

| Page maquette | Page application | Écart | Correction | Statut |
|---|---|---|---|---|
| `1-tableau-de-bord-se.html` | `/suivi` | Les cartes de démonstration de la maquette ne sont pas reprises | KPI, drill-down et liste d’activités lus dans PostgreSQL | Branché |
| `2-fiche-activite-360.html` | `/suivi/activites/:id` | Onglets planification, livrables, documents et historique non séparés | Résumé, tâches, budget, réalisation, indicateurs, risques, écarts, mesures | Partiel |
| `3-gantt.html` | `/suivi/gantt` | Pas de calendrier ni de dépendances ; les dates de tâches sont vides dans le PAP actuel | Barres d’avancement des tâches PAP | Partiel |
| `4-saisie-realisation.html` | `/suivi/saisie` | Pas de dépôt de pièce dans ce formulaire | Saisie physique, justification au-delà de 100 %, refus des montants | Branché |
| `5-ecart-action-corrective.html` | `/suivi/ecarts` | Le fil de traitement illustré n’est pas un workflow graphique | Écart calculé, cause, mesure corrective | Branché |
| `6-synthese-executive.html` | `/suivi/synthese` | La mise en page exécutive de la maquette est simplifiée | Écarts, risques, recommandations, évaluation, exports | Branché |
