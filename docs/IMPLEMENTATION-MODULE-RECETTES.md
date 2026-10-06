# Module Recettes — BUDGET-CEEAC / GESBUDEP

Le module Recettes est le pendant de la chaîne de dépense : prévision, titre, contribution, créance, encaissement partiel, trop-perçu, rapprochement, relance, régularisation, pièces, PDF et Excel. Les montants sont des entiers en FCFA. Les droits sont contrôlés côté serveur.

## Architecture

Domaine Laravel `App\Domains\Revenues`. Les transitions financières passent par des services transactionnels. Le journal de dossier (`revenue_events`, ajout seul) et l’audit financier existant enregistrent l’acteur, l’action, l’avant, l’après et l’adresse IP. Les tâches ouvertes sont projetées dans **Mes tâches** par le projecteur déjà en place (`revenue_order`, `revenue_receipt`). Les alertes d’échéance partent de la commande `recettes:alertes`, planifiée à 7 h 20 et déclenchée une fois par jour avec le lot de suivi.

Le circuit du titre est : brouillon, soumis, vérifié, validé, pris en charge, partiellement encaissé, soldé. Rejet, suspension, reprise et annulation motivée sont possibles. L’auteur ne vérifie pas son titre. Le validateur est distinct de l’auteur et du vérificateur. Le comptable qui prend en charge ou encaisse n’est ni l’auteur ni le validateur. Celui qui rapproche n’est pas celui qui a saisi l’encaissement.

## Migrations

`2026_10_03_202145_create_revenue_tables.php`

Tables : `member_states`, `revenue_categories`, `revenue_payment_modes`, `revenue_settings`, `revenue_forecasts`, `revenue_orders`, `revenue_contributions`, `revenue_receipts`, `revenue_allocations`, `revenue_reminders`, `revenue_adjustments`, `revenue_events`, `revenue_documents`.

Le référentiel initial (onze États membres de la CEEAC, dix catégories, six modes, seuils de 7 et 90 jours) est inséré par la migration et reste modifiable par le Directeur du Budget.

## Modèles

`MemberState`, `RevenueCategory`, `RevenuePaymentMode`, `RevenueSetting`, `RevenueForecast`, `RevenueOrder`, `RevenueContribution`, `RevenueReceipt`, `RevenueAllocation`, `RevenueReminder`, `RevenueAdjustment`, `RevenueEvent`, `RevenueDocument`.

## Services

- `RevenueAccess` — voir, éditer, vérifier, décider, encaisser, rapprocher, relancer, configurer.
- `RevenueCycleService` — prévisions, titres, contributions, relances, pièces, catégories, modes, seuils.
- `RevenueCollectionService` — encaissement, affectation, trop-perçu (avance ou remboursement), rapprochement.
- `RevenuePortraitService` — tableau de bord, listes, fiche, échéancier, export.
- `RevenueAlertService` — notifications d’échéance.
- `RevenueJournal` — événement de dossier et audit financier.

Numérotation verrouillée : `PRV-AAAA-000001`, `REC-AAAA-000001`, `ENC-AAAA-000001`.

## API

Préfixe `/api/v1/recettes`, session Sanctum.

Tableau, référentiel, prévisions, titres et transitions, pièces, document PDF, contributions, créances, échéancier, encaissements, affectation, rapprochements, relances, états Excel ou PDF, catégories, modes, seuils.

## Pages

Menu **Recettes** : tableau de bord, prévisions, titres, contributions, créances, encaissements, rapprochements, relances, états, paramétrage. Fiche `/recettes/titres/:id` avec bannière d’étape, encaissement, avoir, relance, pièces et historique. Composants déjà utilisés : cartes, tableaux, badges, modales, pagination, jauges, chronologie.

## Règles métier

Montant strictement positif. Exercice ouvert pour un titre et un encaissement. Prévision possible aussi en préparation. La somme des affectations et du trop-perçu égale le montant encaissé. Une affectation ne dépasse pas le solde : l’excédent est une avance ou un remboursement, jamais un solde fictif. Un titre n’est soldé que lorsque l’encaissé égale le montant dû. Un avoir ne descend pas sous l’encaissé. Un titre encaissé ne s’annule pas. Une prévision validée n’est pas supprimée.

## Notifications et documents

Notification à l’auteur lors d’un encaissement, tâche pour l’étape suivante, alerte quotidienne avant l’échéance. PDF archivés (titre, appel de fonds, situation) avec empreinte SHA-256. Pièces jointes stockées avec empreinte. États Excel et PDF.

## Tests

`tests/Feature/RecettesTest.php` : cycle partiel puis solde, refus d’un dépassement, trop-perçu en avance, séparation des acteurs, interdiction pour un initiateur, appel de contribution, export et PDF.

## Vérification

Migration appliquée sur `budget_ceeac_v8`. Le tableau de bord 2026 affiche des indicateurs à zéro tant qu’aucun titre n’est constaté. La prévision `PRV-2026-000001` (25 000 000 FCFA, financements partenaires) a été créée et soumise par l’expert budget. Le bouton Valider n’apparaît pas pour cet acteur. Le paramétrage liste les catégories sans formulaire de modification pour l’expert.

## Points encore hors de ce module

Les écritures du grand livre comptable ne sont pas générées : l’application n’a pas de journal comptable général distinct du journal d’audit. L’écran d’encaissement affecte un titre à la fois ; l’API accepte plusieurs affectations. Le tableau de bord exécutif S&E ne reprend pas encore ces indicateurs : ils sont exposés par `GET /recettes/tableau`.
