# Audit et correction du module Recettes

Date : 4 octobre 2026. Base : PostgreSQL `budget_ceeac_v8`. La recette constatée est le titre (`revenue_orders`). Aucune table `revenues`, `revenue_types` ou `revenue_sources` n’a été créée.

## Tableau

| Élément | Existe avant audit | Problème constaté | Correction réalisée | Fichier concerné | Test |
|---|---|---|---|---|---|
| Liste des prévisions | Oui, modal de création seulement | Pas de recherche, filtre, pagination, Voir, Modifier, Supprimer | Liste paginée, filtre statut, recherche, Voir et Supprimer d’un brouillon | `frontend/src/features/revenues/pages/PrevisionsPage.tsx` | `test_une_prevision_se_consulte_se_modifie_puis_s_annule_selon_son_statut` |
| Fiche prévision | Non | Aucun écran de consultation, d’historique ni de recettes liées | Fiche identification, finances, historique, recettes liées | `frontend/src/features/revenues/pages/PrevisionFichePage.tsx` | GET `/api/v1/recettes/previsions/{id}` |
| Modification prévision | API partielle | Écran absent ; structure non enregistrée | Formulaire brouillon uniquement ; prévision soumise ou validée refusée | `RevenueCycleService::modifierPrevision` | PATCH montant puis 422 après soumission |
| Suppression prévision | API | Bouton absent ; journal mal rattaché | Suppression limitée au brouillon sans recette liée | `RevenueCycleService::supprimerPrevision` | DELETE brouillon OK, soumis 422 |
| Annulation prévision | Non | Une prévision soumise ne pouvait ni être corrigée ni être retirée | Annulation motivée si statut `soumis` et aucune recette liée | `POST /recettes/previsions/{id}/annuler` | Annulation OK, validation déjà faite 422 |
| Validation prévision | API | Pas de tâche « Mes tâches » | Tâche `revenue_forecast` pour le Directeur du Budget | `TaskProjector::revenueForecasts` | Tâche ouverte après soumission, fermée après annulation |
| Journal prévision | Partiel | Sujet d’audit `revenue` ou `revenue_receipt` | Sujet `revenue_forecast` quand `forecast_id` est présent | `RevenueJournal.php` | Historique non vide sur la fiche |
| Liste des recettes | Oui (`/recettes/titres`) | Libellé menu « Titres » ; création sans prévision | Menu « Recettes », sélection de prévision validée, héritage des champs | `TitresPage.tsx`, `navigation.ts` | Création refusée si prévision non validée |
| Fiche et édition recette | Fiche workflow | Pas de formulaire d’édition malgré le PATCH | Édition brouillon ou rejeté, tant qu’aucun encaissement | `TitreFichePage.tsx` | PATCH 4 000 000 puis 422 après prise en charge |
| Encaissements | Oui | La liste cassait : les titres sont devenus des objets | Liens vers la recette, pagination, modes inactifs exclus | `EncaissementsPage.tsx` | Cycle partiel puis solde déjà couvert |
| Rapprochements | Oui | `.join` sur des objets | Affichage des références | `RapprochementsRecettesPage.tsx` | Rapprochement chef déjà couvert |
| Catégories et modes | Création seulement | Pas d’activation / désactivation | Boutons Activer et Désactiver | `ParametrageRecettesPage.tsx` | PATCH mode `active=false` |
| Encaissement partiel | Oui | — | Inchangé : statut `partiellement_encaisse`, solde = constaté − encaissé | `RevenueCycleService` | 4 000 000 puis refus de 7 000 000, solde 6 000 000 puis `solde` |
| Permissions | `RevenueAccess` | — | Réutilisées : voir, éditer, vérifier, décider, encaisser, rapprocher, configurer | `RevenueAccess.php` | Initiateur 403 sur prévision et titre |

## Pages créées

- `/recettes/previsions/:id` — fiche prévision (`PrevisionFichePage`).

## Pages corrigées

- Prévisions : liste, création, consultation, modification, suppression, soumission, validation, annulation.
- Recettes (`/recettes/titres` et fiche) : création depuis une prévision validée, édition contrôlée.
- Encaissements, rapprochements, paramétrage.

## Routes ajoutées

- Frontend : `/recettes/previsions/:id`.
- API : `GET /api/v1/recettes/previsions/{forecast}`, `POST /api/v1/recettes/previsions/{forecast}/annuler`, `PATCH /api/v1/recettes/modes/{mode}`.

## API déjà présentes et conservées

Prévisions : liste, création, modification, suppression, soumission, validation. Recettes (titres) : liste, création, fiche, modification, workflow, pièces, PDF. Encaissements, créances, contributions, relances, états, référentiel.

## Migrations ajoutées

Aucune. Les tables `revenue_forecasts`, `revenue_orders`, `revenue_receipts`, `revenue_categories`, `revenue_payment_modes`, `revenue_events` suffisent. L’historique de prévision est lu dans le JSON `before` / `after`.

## Modèles modifiés

Aucun nouveau modèle. `RevenueOrder.forecast_id` relie déjà la recette à la prévision, et les allocations relient l’encaissement à la recette.

## Permissions ajoutées

Aucune nouvelle permission. Le RBAC existant (`User::holds`) reste la source : expert et directeur du budget éditent, l’expert vérifie, le directeur décide, les comptables encaissent.

## Composants créés

Aucun. Réutilisation de `PageHeader`, `SectionCard`, `DataTable`, `FilterBar`, `Modal`, `StatusBadge`, `Pagination`, `InfoGrid`.

## Tests créés

`backend/tests/Feature/RecettesTest.php` : 5 tests, 79 assertions, tous verts. Couverture ajoutée : consultation, modification, suppression autorisée et interdite, annulation, tâche, héritage de prévision, verrouillage après prise en charge, désactivation d’un mode. Le cycle d’encaissement partiel puis total était déjà testé.

Vérification navigateur (4 octobre 2026) : création de `PRV-2026-000003`, modification à 4 200 000 FCFA, soumission, validation par le Directeur du Budget, création de `REC-2026-000002` préremplie, modification à 4 000 000 FCFA. La liste des encaissements affiche `REC-2026-000001` en lien.

## Anomalies restantes

- La recette constatée est le titre. Il n’y a pas une seconde table `revenues`.
- Les natures sont les catégories administrables. Il n’y a pas un second référentiel `revenue_types`.
- La source est un libellé plus le type de débiteur (État, partenaire, institution, organisme, tiers). Pas de table `revenue_sources`.
- Une prévision validée, y compris les dons officiels `DON-*`, ne se modifie ni ne s’annule. Seul un brouillon se supprime ; seule une prévision soumise s’annule.
- Les statuts « révisée » et « clôturée » ne sont pas des statuts distincts. La révision passe par un nouveau brouillon. « Échue » est calculée sur la date, pas stockée.
- Un encaissement affecté ne se supprime pas. Le retour se fait par avoir, pour ne pas casser le solde.
- La validation Laravel reste dans le contrôleur, comme le reste du module. Pas de Form Request parallèle.
- `DON-AUTRES` affiche un montant négatif issu de l’import du budget 2026. Il n’a pas été réécrit.
- `PRV-2026-000001` reste soumise. Elle n’a pas été validée pendant cet audit.
- La duplication d’une prévision n’est pas proposée.
- L’annulation d’un encaissement non identifié n’a pas d’écran dédié.
- Les modules Projets et BI restent hors périmètre.
