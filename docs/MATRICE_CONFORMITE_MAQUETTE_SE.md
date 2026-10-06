# Matrice de conformité aux maquettes Suivi-Évaluation

Référence : `docs/maquette-SE` (6 écrans). Situation au 2 octobre 2026.

Règle appliquée : aucune donnée statique. Chaque chiffre affiché vient de PostgreSQL par l’API `/api/v1/suivi/*`. Les seuils (écart, statut de performance, appréciation, chaleur, évolution) viennent des règles métier `se_*`.

| Maquette | Page de l’application | Écart constaté avant reprise | Correction | Statut |
|---|---|---|---|---|
| 1-tableau-de-bord-se | `/suivi` · `SuiviDashboardPage` | Indicateurs isolés, sans filtres, heatmap ni liste d’attention | Endpoint `GET /suivi/pilotage`. La page comprend : 8 filtres, bandeau de 7 tuiles, physique/financier (donuts + écart), répartition des indicateurs, évolution, heatmap des structures, « Mes tâches S&E » et liste d’attention | Conforme |
| 2-fiche-activite-360 | `/suivi/activites/:id` · `Activite360Page` | Pas de code activité, de responsable, de pondération des tâches ni de jalons | Endpoint `GET /suivi/activites/{id}/fiche`. La page comprend : en-tête (code, statut, PAP, Vue 360°), bandeau de 6 cellules, tâches pondérées avec contribution, indicateurs (référence, cible, taux, courbe), jalons (ajout, franchissement avec preuve). La colonne droite regroupe risques, chaîne de dépense, GED et historique | Conforme (colonne droite renseignée, vide dans la maquette) |
| 3-gantt | `/suivi/activites/:id/gantt` (+ sélecteur sur `/suivi/gantt`) · `GanttPage` | Gantt de portefeuille sans planning initial, projection ni révision | Endpoint `GET …/gantt?echelle=semaines\|mois\|trimestres`. Barres planning initial / réel colorées par statut, projection hachurée, ligne « aujourd’hui », jalons, légende. Cartes : impact des dépendances, retards constatés, modification du planning (proposition, validation hiérarchique, historique des versions) | Conforme |
| 4-saisie-realisation | `/suivi/indicateurs/:id/saisie` · `SaisieIndicateurPage` ; file de travail sur `/suivi/saisie` | Formulaire générique, validation à un seul niveau | Circuit à 4 niveaux (saisie → responsable → hiérarchie → consolidation) avec séparation des fonctions. Numérateur/dénominateur et valeur calculée côté serveur (`/apercu`). Taux, évolution, statut, preuve versée à la GED. **Contrôles de qualité (6)** et **historique des valeurs avec snapshot** ajoutés au contexte API | Conforme |
| 5-ecart-action-corrective | `/suivi/ecarts/:id` (+ liste `/suivi/ecarts`) · `EcartPage` | Formulaire de création manuelle d’écart | Dossier d’écart : en-tête, relancer/escalader, carte d’écart, chronologie en 7 étapes, explication (interprétations selon le sens, catégories de causes), problème (lien risque → problème), action corrective. **Colonne droite ajoutée** : finances lues dans la chaîne, notifications réellement envoyées (table `notifications`), prochain rapport | Conforme |
| 6-synthese-executive | `/suivi/synthese` · `SynthesePage` | Résumé textuel et exports | Bascule données du jour / situation figée (snapshot d’un rapport publié), lien PDF signé, navigation en 7 étapes, indice global, 8 KPI, principaux écarts, matrice des risques 4×4, recommandations, décisions (inscrire, ajourner, décider) | Conforme |
| Navigation (toutes) | `AppShell` | Rubrique « Suivi-Évaluation » sans entrée « Gantt d’exécution » | Catégorie « Projets & performance » avec « Gantt d’exécution » et « Suivi-Évaluation », état actif par sous-page | Conforme |

## Écarts résiduels assumés

- **Filtre « Programme » (écran 1)** : le PAP n’a pas de champ programme. Le filtre s’appuie sur l’`axe`.
- **Dépendances multiples (écran 3)** : la maquette montre « T3, T5 · FD ». Le modèle ne gère qu’un prédécesseur (`depends_on_id`), avec le type FD (fin → début).
- **Rubrique « Projets & Investissements »** : elle figure dans la navigation de la maquette mais ne relève pas du module S&E. Elle n’est pas créée.
- **Fiche d’évaluation** : absente des maquettes. Elle est déplacée de la synthèse exécutive vers la page Rapports pour ne pas perdre la fonction.
- **Données de démonstration** : sans saisie réelle (indicateurs, écarts, risques), les écrans affichent leurs états vides. Aucune donnée historique n’est inventée.

## Vérifications

- `php artisan test` : 112/112 (997 assertions), dont `SePagesTest` étendu (contrôles de qualité, historique, finances et notifications de l’écart).
- `vendor/bin/pint` : appliqué.
- `npm run build` et `tsc --noEmit` : sans erreur.
- Appels sur PostgreSQL (`budget_ceeac_v8`) : `pilotage`, `fiche`, `gantt` (mois, trimestres), `synthese-executive`, `ecarts` et dossier d’activité répondent tous 200.
