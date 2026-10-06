# Audit de conformité — module Suivi-Évaluation

Date : 2 octobre 2026  
Périmètre : maquette `docs/maquette-SE/` comparée à l’implémentation React (`frontend/src/features/monitoring`) et Laravel (`backend/app/Domains/Monitoring`), base PostgreSQL `budget_ceeac_v8`.  
Règle appliquée : la maquette est la vérité visuelle et ergonomique. Les fonctions métier déjà justes (chaîne de dépense, historisation, référentiels, score paramétrable) ont été conservées.

## 1. Écrans de la maquette

`docs/maquette-SE/index.html` recense six écrans. Les fichiers `source-canvas/*.dc.html` reprennent les mêmes compositions.

| Écran maquette | Titre perceptible | Équivalent application |
|---|---|---|
| `1-tableau-de-bord-se.html` | Suivi-Évaluation | `/suivi` — `SuiviDashboardPage.tsx` |
| `2-fiche-activite-360.html` | Fiche activité | `/suivi/activites/:id` — `Activite360Page.tsx` |
| `3-gantt.html` | Gantt · planning initial et planning réel | `/suivi/gantt` et `/suivi/activites/:id/gantt` — `GanttPage.tsx` |
| `4-saisie-realisation.html` | Valeur de la période (nom de l’indicateur) | `/suivi/indicateurs/:id/saisie` — `SaisieIndicateurPage.tsx` ; file d’attente `/suivi/saisie` — `SaisiePage.tsx` |
| `5-ecart-action-corrective.html` | Écart significatif entre exécution physique et financière | `/suivi/ecarts` et `/suivi/ecarts/:id` — `EcartPage.tsx` |
| `6-synthese-executive.html` | Synthèse exécutive | `/suivi/synthese` — `SynthesePage.tsx` |

Écrans complémentaires, absents de la maquette et conservés parce qu’ils portent le métier : `/suivi/actions`, `/suivi/rapports`, `/suivi/referentiels`.

## 2. Chaîne vérifiée

React appelle `/api/v1/suivi/*` (Sanctum, cookie de session). Les pages de la maquette sont servies par `SePagesController` ; le cycle de vie des mesures, réalisations, risques, recommandations et rapports par `MonitoringController`.

Les calculs restent dans les services : `IndicatorCalculationService`, `FinancialExecutionService` (soldes de `BudgetBalanceService`), `PerformanceScoreService`, `IndicatorAggregationService`, `ActivityGanttService`, `VarianceDossierService`, `SyntheseService`, `PerformanceDashboardService`.

Le financier saisi dans un formulaire de suivi est refusé. Le taux affiché « engagé » est engagé / budget révisé. Le taux « payé / révisé » est payé / budget révisé. Une réalisation ou une mesure n’alimente les taux qu’après validation par un acteur autre que l’auteur. Une valeur validée est historisée, pas écrasée.

Workflow des mesures et réalisations, table `se_transitions` : brouillon → soumis → validé responsable → validé → consolidé, avec rejet et retour en correction. Les rapports de performance ajoutent soumission, retour, validation et publication d’une situation figée.

Les preuves sont des enregistrements `se_proofs` (empreinte SHA-256). Les transitions et les relances écrivent un événement d’audit (utilisateur, action, date, commentaire). Les alertes passent par `MonitoringAlert` et les tâches attendues par le projecteur de « Mes tâches ».

## 3. Écarts constatés pendant cet audit et corrections

| Écart | Cause | Correction |
|---|---|---|
| Les tuiles « Risques critiques » et « Recommandations en retard » étaient des boutons sans action | `StatTile` rendait toujours un `<button>`, même sans `onClick` | La tuile devient un lien vers `/suivi/actions?onglet=risques` ou `recommandations`. Sans action ni lien, elle n’est plus un bouton |
| La composante « Exécution financière (payé / révisé) » du score utilisait le taux d’engagement | `PerformanceScoreService` lisait `card.financier`, qui est le taux d’engagement de la maquette | La composante lit `paye_taux` (payé / révisé). Le tableau de bord et l’écart continuent de comparer le physique à l’engagé, comme la maquette |
| La fiche 360 n’isolait pas le bloc « Finances · temps réel » ni le détail du score | Les montants n’apparaissaient que dans la chaîne et le bandeau | Cartes « Finances · temps réel » (initial, révisé, engagé, liquidé, ordonnancé, payé, disponible), « Score de performance » (composantes × poids) et titre « Preuves · GED » |
| La liste des écarts était vide alors que le tableau de bord montrait des écarts critiques | `suivi:alertes` est planifié à 07:00 et n’avait pas été exécuté sur la base de travail | Commande exécutée : 2 alertes créées (`EC-2026-001` forum régional, 0 % / 50,7 % ; `EC-2026-002` formation GAR, 0 % / 33,3 %). Dossier ouvert : relance, escalade, causes du référentiel, explication, problème, action corrective, finances, notifications, prochain rapport |

Aucune migration nouvelle. Aucune donnée de démonstration de la maquette (126 activités, 60 %, ACT-DENER, etc.) n’est codée dans React.

## 4. Fichiers modifiés lors de cette passe

- `frontend/src/features/monitoring/components/se.tsx`
- `frontend/src/features/monitoring/pages/SuiviDashboardPage.tsx`
- `frontend/src/features/monitoring/pages/SuiviActionsPage.tsx`
- `frontend/src/features/monitoring/pages/Activite360Page.tsx`
- `backend/app/Domains/Monitoring/Services/PerformanceScoreService.php`

Fichier créé : `docs/audits/AUDIT-CONFORMITE-SUIVI-EVALUATION.md`.

Migrations déjà en place (non modifiées) :

- `2026_10_01_202547_create_monitoring_evaluation_tables.php`
- `2026_10_01_205232_create_monitoring_referentials_and_task_dependencies.php`
- `2026_10_01_231452_add_follow_up_to_monitoring_items.php`
- `2026_10_01_234137_align_monitoring_with_maquette.php`

API déjà en place et utilisées par les six écrans : `GET /suivi/pilotage`, `GET /suivi/activites/{id}/fiche`, `GET/POST` Gantt et plannings, `GET /suivi/indicateurs/{id}/saisie`, `GET /suivi/ecarts/{id}/dossier`, relance et escalade, `GET /suivi/synthese-executive`, décisions, `GET /suivi/rapports?format=xlsx`.

## 5. Vérifications

Tests PHPUnit (sqlite mémoire) : `SuiviEvaluationTest`, `SuiviEvaluationIntegriteTest`, `SePagesTest`, `IndicatorCalculationTest` — **29 tests, 463 assertions, tous verts** après la correction du score.

Contrôle de types frontend : `npx tsc --noEmit` — succès.  
Style PHP : `vendor/bin/pint` sur `PerformanceScoreService.php` — succès.

Navigateur (`http://localhost:5173`, session Directeur du Budget) :

- Connexion puis tableau de bord : 5 activités PAP, physique 0 % (aucune réalisation validée), engagé 34 %, payé 15 %, filtres exercice, période, département, programme, pilier, type, statut. Le filtre Département = DATI ramène le bandeau à 1 activité.
- Export « Power BI » : `GET /api/v1/suivi/rapports?format=xlsx` répond 200, fichier `suivi-evaluation.xlsx` (6 445 octets). C’est un classeur, pas un jeu de données Power BI hébergé.
- Gantt de l’activité DATI : échelles Semaines / Mois / Trimestres, barres prévu / réel, retard de démarrage calculé, proposition de planning.
- Synthèse : indice 26/100, écarts calculés, matrice de risques, étapes 1 à 7, situation du jour. Aucune situation figée publiée à cet instant.
- Saisie : activités et période chargées depuis l’API. Aucun indicateur dans le périmètre, donc pas de courbe cible / réalisé à afficher.
- Fiche 360 : finances réelles de la ligne 203232 (initial et révisé 50 000 000, engagé 4 600 000, liquidé 0, payé 0, disponible 35 400 000). Le liquidé nul suit la règle « une liquidation non visée n’est pas du liquidé ». Score : la composante financière est bien 0 % × 20.
- Grille de la fiche en largeur mobile : une colonne, pas de débordement horizontal.
- Écarts : les deux dossiers critiques s’ouvrent. Le dossier du forum porte le circuit de traitement de la maquette.
- `/suivi/actions?onglet=risques` ouvre directement l’onglet Risques.

## 6. Anomalies restantes

- L’aide et le compteur de notifications du bandeau de la maquette sont ceux du shell GESBUDEP, pas un second en-tête propre au suivi.
- La courbe d’un indicateur et le circuit à quatre niveaux ne peuvent pas être exercés dans le navigateur tant qu’aucun indicateur n’est rattaché à une activité. Le parcours est couvert par `SePagesTest`. Aucun indicateur n’a été inventé pour combler cet affichage.

Les quatre points ouverts le 2 octobre (responsable, causes de la maquette, pagination, alertes sans planificateur) sont corrigés. Voir la matrice.

## 7. Recommandations

1. Rattacher les indicateurs déjà décrits dans le PAP aux activités, afin que la courbe cible / réalisé ait une série réelle.
2. Conserver `php artisan schedule:work` en production : le middleware quotidien ne remplace pas un planificateur à heure fixe, il garantit un passage par jour au premier appel API.

## 8. Matrice de conformité finale

| Élément maquette | Implémentation | Conforme | Correction | Test |
|---|---|---|---|---|
| Tableau de bord — titre, question, actions | `SuiviDashboardPage.tsx` | Oui | — | OK |
| Filtres exercice, période, département, programme, pilier, type, statut, responsable | `GET /suivi/pilotage` | Oui | Responsable = directeur de la structure si vide | OK |
| Bandeau d’activités, retards, blocages | `PerformanceDashboardService::strip` | Oui | — | OK |
| Tuiles risques et recommandations | Liens vers `/suivi/actions` | Oui | Boutons sans action remplacés par des liens | OK |
| Anneaux physique / engagé / payé et écart | Soldes de la chaîne | Oui | — | OK |
| Répartition des indicateurs | Mesures validées | Oui | — | OK |
| Évolution mensuelle | Réalisations validées et engagements datés | Oui | — | OK |
| Carte de chaleur des structures et tri | Tableau + bouton de classement | Oui | — | OK |
| Mes tâches Suivi-Évaluation | `GET /taches?module=se` | Oui | — | OK |
| Activités nécessitant une attention | Calcul live, paginé | Oui | Pagination `attention_meta` | OK |
| Export | `GET /suivi/rapports?format=xlsx` | Oui | Libellé maquette « Power BI » = classeur xlsx | OK (HTTP 200) |
| Fiche 360 — identité, Gantt, signaler un risque, saisie | `Activite360Page.tsx` | Oui | — | OK |
| Physique et financier, tâches, indicateurs, jalons | `GET /suivi/activites/{id}/fiche` | Oui | — | OK |
| Finances · temps réel | `dossier.finances` | Oui | Carte ajoutée | OK (50 M / 4,6 M engagés) |
| Score de performance | `PerformanceScoreService` | Oui | Composante financière = payé / révisé | OK |
| Risques, chaîne de dépense liée, preuves GED, historique | Dossier activité | Oui | Titre preuves aligné | OK |
| Gantt — échelles, barres prévu/réel, dépendances, retards | `ActivityGanttService` | Oui | — | OK |
| Proposer un planning et historique des versions | `POST /suivi/activites/{id}/plannings` | Oui | — | OK (test tiers) |
| Saisie indicateur — circuit, valeur, contrôles, historique, preuve | `SaisieIndicateurPage.tsx` | Oui | Pas de série live : aucun indicateur | OK (test) |
| Saisie des réalisations physiques | `SaisiePage.tsx` | Oui | Hors maquette, conservée | OK |
| Écart — alerte, relance, escalade, causes, explication, problème, action, finances, notifications, prochain rapport | `EcartPage.tsx` + `VarianceDossierService` | Oui | Alerte à l’ouverture ; causes Contractuelle, RH, Fournisseur, Autre ; liste paginée | OK |
| Synthèse — situation du jour / figée, indice, KPI, écarts, matrice, recommandations, décisions | `SynthesePage.tsx` | Oui | — | OK |
| PDF signé d’une situation figée | Rapport publié | Oui | Aucune publication en base de travail | OK (test) |
| Référentiels causes, critères, poids du score | `ReferentielsPage.tsx` | Oui | Conservé hors maquette | OK |
| Suivi des actions et historique | `SuiviActionsPage.tsx` | Oui | Onglet ouvert par l’URL | OK |
| Interdiction des montants saisis et des mocks | Requêtes `prohibited` + services | Oui | — | OK |
| Permissions et périmètre organisationnel | `MonitoringPolicy`, `visible()` | Oui | — | OK |
| Pagination des listes écarts, saisie et attention | `meta` + composant `Pager` | Oui | 8 lignes par page | OK |
| Alertes et relances sans cron | `EnsureDailyMonitoring` + commandes 07:00 / 07:30 | Oui | Une fois par jour au premier appel API, et à l’ouverture du tableau de bord ou des écarts | OK |
