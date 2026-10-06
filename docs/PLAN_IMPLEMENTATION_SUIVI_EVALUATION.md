# Plan de mise en œuvre — Suivi-Évaluation

## P0 — intégrité, modèle, sécurité

Fait dans ce chantier :

- tables de suivi rattachées au PAP et à la ligne budgétaire ;
- interdiction de ressaisir les montants ;
- formules dans `IndicatorCalculationService` ;
- historisation des mesures validées ;
- périmètre par unité, sauf rôles transverses ;
- preuves hashées exigées avant validation.

## P1 — processus

Fait : périodes, indicateurs, cibles, réalisations, écarts, mesures correctives, risques, recommandations, évaluations, tâches de saisie et de validation, notifications d’alerte.

Reste : campagnes de collecte par structure, circuit contrôlé / consolidé / clôturé semé dans `se_transitions`, référentiels administrables des causes, types d’indicateurs et critères d’évaluation.

## P2 — reporting et tableaux de bord

Fait : tableau de bord, drill-down pilier → activité, fiche 360, synthèse, exports CSV, Excel et PDF.

Reste : score composite paramétré, agrégation réelle des indicateurs selon `aggregation`, rapports officiels immuables dans la GED des actes, Gantt calendaire avec dépendances.

## P3 — ergonomie

Reste : rapprocher la fiche activité et la synthèse de la densité visuelle de `docs/maquette-SE/`, déposer la preuve dans le formulaire de saisie, courbes cible / réalisé.
