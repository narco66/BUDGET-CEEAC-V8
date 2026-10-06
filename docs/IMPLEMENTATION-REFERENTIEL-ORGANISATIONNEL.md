# Implémentation du référentiel organisationnel unique

Document source : `Referentiel_organisationnel_Commission_CEEAC_2026.pdf`.
Données structurées déjà extraites et conservées : `backend/database/data/organigramme-ceeac-2026.php`.
Publication : commande `php artisan organisation:referentiel` (ne réécrit pas le budget 2026).

## Architecture retenue

La table existante `organization_units` reste l’unique table de structures. Aucune seconde table de structures n’a été créée.

Le modèle est parent/enfant (`parent_id`). Le code stable est le `sigle`, unique. L’ordre institutionnel est `sort_order`. Le statut courant est `is_active` (désactivation logique). La suppression physique est refusée dès qu’une structure a des enfants ou est référencée.

Les types officiels ne forment pas une table concurrente. Ils sont la classification fermée servie par l’API :

| Code | Libellé |
|---|---|
| communaute | Communauté |
| commission | Commission |
| departement | Département |
| direction | Direction |
| cabinet | Cabinet |
| service | Service |
| bureau | Bureau |

Les fonctions organisationnelles (`organization_positions`) sont distinctes des rôles applicatifs (`users.role`, `user_roles`). Une affectation (`organization_assignments`) relie un utilisateur, une fonction et une structure, avec dates, statut et référence. Clôturer une affectation conserve la ligne (`statut = terminee`).

Le versionnement est un enregistrement de publication (`organization_versions`), pas une copie figée des 113 lignes. Une seule version peut être `publie` ; les autres versions publiées passent à `archive`. Les dossiers historiques conservent leur `organization_unit_id`. Le libellé affiché est celui de la structure courante.

## Structures et fonctions importées

Après `organisation:referentiel` sur `budget_ceeac_v8` :

- 113 structures actives, rattachées à la version `ORG-2026` ;
- version : « Organigramme de la Commission de la CEEAC », document `Referentiel_organisationnel_Commission_CEEAC_2026.pdf`, effet `2026-06-01`, statut `publie` ;
- 10 fonctions : Président, Vice-Président, Secrétaire Général, Commissaire, Chef de Cabinet, Directeur, Chef de Service, Chef de Bureau, Expert, Agent ;
- 13 affectations actives, créées à partir des utilisateurs déjà rattachés à une structure et dont le rôle applicatif correspond à une fonction (`directeur`, `directeur_budget`, `commissaire`, `secretaire_general`, `expert_budget`).

Chaîne vérifiée : `DSG-DSI` → parent `DSG` → parent `COM-CEEAC` → parent `CEEAC`. Enfants de `DSG-DSI` : `DSG-DSI-SED` (Service Études et Développement) et `DSG-DSI-SEM` (Service Exploitation et Maintenance). Responsable courant de `DSG` : Aline MOUSSAVOU, Secrétaire Général. Responsable courant de `DSG-DSI` : Directeur DSI.

Les rôles `initiateur` et `ordonnateur` ne sont pas convertis en fonction, pour ne pas attribuer une direction à un agent qui n’en est pas le responsable.

## Tables et migration

Migration : `backend/database/migrations/2026_10_04_051423_add_organization_referential_columns.php` (appliquée sur `budget_ceeac_v8`).

Créées :

- `organization_versions` (code, label, document_reference, effective_on, statut, comment, published_at, published_by) ;
- `organization_positions` (code unique, label, description, rank, compatible_kind, parent_position_id, is_active) ;
- `organization_assignments` (user_id, organization_unit_id, position_id, starts_on, ends_on, statut, motif, reference).

Colonnes ajoutées à `organization_units` : `version_id`, `description`, `sort_order`, `is_active`, `effective_on`. Index sur `kind` et `is_active`. Contrainte d’unicité sur `sigle`. `parent_id` était déjà indexé par la clé étrangère.

Données migrées sans recréer l’arbre : ordre d’affichage, rattachement à `ORG-2026`, date d’effet, affectations initiales. Les structures déjà présentes et absentes du fichier PHP restent actives, afin de ne pas casser les dossiers historiques ou de test.

## API

Préfixe `/api/v1`, authentification Sanctum puis `ResolveActor`. Lecture ouverte à tout utilisateur authentifié. Écriture réservée à `AdminGate` capacité `parametrage` (rôle `administrateur_fonctionnel`).

- `GET /organisation/arbre`
- `GET /organisation/unites`
- `POST /organisation/unites`
- `GET|PATCH|DELETE /organisation/unites/{unit}`
- `POST /organisation/unites/{unit}/activer`
- `POST /organisation/unites/{unit}/desactiver`
- `GET /organisation/unites/{unit}/enfants`
- `GET /organisation/unites/{unit}/ancetres`
- `GET /organisation/unites/{unit}/responsables`
- `GET|POST /organisation/fonctions`
- `PATCH /organisation/fonctions/{position}`
- `POST /organisation/affectations`
- `POST /organisation/affectations/{assignment}/cloturer`
- `GET /organisation/versions`

Service unique : `App\Domains\Organization\Services\OrganizationService` (arbre, recherche, fiche, enfants, ancêtres, responsable, occupants d’une fonction, création, modification, activation, suppression contrôlée, affectation, publication).

## Intégrité et journal

- une structure ne peut pas être son propre parent ;
- un déplacement qui refermerait un cycle est refusé ;
- un sigle en doublon est refusé ; le sigle n’est pas modifiable après création ;
- un type hors classification officielle est refusé ;
- la suppression physique est refusée si la structure a des enfants ou si elle est référencée (`users`, `budget_lines`, `budget_proposals`, `revenue_orders`, `revenue_forecasts`, `workflow_tasks`, `gar_nodes`, `gar_node_units`, affectations) ;
- la désactivation met `is_active` à faux.

Journal `FinancialAudit` / `AuditEvent` : création, modification, activation, désactivation, suppression, fonction créée ou modifiée, affectation créée ou clôturée, publication d’organigramme. L’événement conserve l’acteur, l’ancienne valeur et la nouvelle valeur.

## Permissions

Aucune nouvelle table de permissions. La lecture du référentiel est large. La modification passe par la capacité existante `parametrage`. L’écran masque « Nouvelle structure » et le formulaire de modification lorsque `droits.gerer` est faux. Vérifié dans le navigateur : le Directeur du Budget consulte l’arbre et le détail, sans bouton de création.

## Frontend

Page `Administration → Organisation` (`/administration/organisation`), mêmes layouts, cartes, boutons et badges que le reste de l’administration.

- arborescence développable, avec code, libellé et type ;
- recherche par code ou libellé ;
- vue organigramme par regroupement de branches, navigation vers le détail ;
- fiche : parent, chemin, responsable, enfants ;
- pour l’administrateur fonctionnel : création, modification (libellé, type, parent, ordre), activation et désactivation.

Vérification navigateur (session Directeur du Budget) : arbre `CEEAC → COM-CEEAC → DPRES, DVPRES, DSG, DAPPS, DMCAEMF, DENRADR, DATI, DPGDHS` ; fiche `DSG` parent `COM-CEEAC`, responsable Aline MOUSSAVOU ; fiche `DSG-DSI` parent `DSG`, enfants `DSG-DSI-SED` et `DSG-DSI-SEM`.

## Intégration des modules

| Module | Ancienne source organisationnelle | Nouvelle source | Migration effectuée | Test |
|---|---|---|---|---|
| Administration / utilisateurs | `organization_units` + `users.organization_unit_id` | même table, affectation officielle en plus | rattachement conservé, 13 affectations créées | consultation navigateur |
| Budget / lignes | `budget_lines.organization_unit_id` | `organization_units` actives de type direction, ordre institutionnel | aucune ligne officielle réécrite | non-régression Recettes + Organisation |
| Préparation budgétaire | `budget_proposals.organization_unit_id` | même clé étrangère | inchangée | non exécuté isolément |
| Expression de besoin | `expressions_besoin.organization_unit_id` | même clé ; notification enrichie par les occupants de la fonction | aucune donnée de dossier modifiée | couvert indirectement (workflow non cassé) |
| Engagement, liquidation, ordonnancement, paiement | unité héritée de la ligne / du dossier | même identifiant | aucune réécriture | non-régression de la chaîne non relancée |
| Recettes | `revenue_forecasts` et `revenue_orders.organization_unit_id` | unités actives triées par `sort_order` | aucune prévision officielle annulée | `RecettesTest` 5 tests |
| GAR / planification | `gar_nodes` et `gar_node_units` | même clé étrangère | inchangée | usage bloquant la suppression |
| Suivi-évaluation | unité de la ligne budgétaire liée | même clé | inchangée | non exécuté isolément |
| Mes tâches / workflows | rôle applicatif + `organization_unit_id` exact | union avec les occupants de la fonction mappée sur la structure | résolution additive, `actorMatches` inchangé | `OrganisationTest` |
| Notifications métier | destinataires par rôle | les occupants de la fonction de la structure sont ajoutés à la notification d’expression de besoin | aucune règle de séparation des tâches modifiée | couvert par le test d’arbre |

Les formulaires métier interrogeaient déjà l’API des structures. Aucune liste statique Direction / Département / Service n’a été trouvée dans les composants React. Les sélections en cascade département → direction → service n’ont pas été ajoutées dans chaque formulaire : le parent se choisit sur la fiche d’administration, et les modules continuent de stocker `organization_unit_id`.

## Anciennes sources

Le fichier PHP de l’organigramme 2026 reste la source d’import réexécutable. La commande `ceeac:importer-2026` n’a pas été relancée, parce qu’elle réécrit aussi le budget. Les seeders de tests qui créent des sigles techniques pour SQLite en mémoire sont conservés : ils n’alimentent pas PostgreSQL.

`users.function_title` reste un libellé libre. La fonction officielle est l’affectation.

## Tests réalisés

- `php artisan test --compact tests/Feature/OrganisationTest.php tests/Feature/RecettesTest.php` : 6 tests, 103 assertions, succès.
- Le test organisationnel publie la source, vérifie le parent de `DSG-DSI`, refuse la création à un non-administrateur, refuse un sigle dupliqué, refuse un cycle, active et désactive, refuse la suppression d’un parent ou d’une structure utilisée, et résout le responsable en remontant l’arbre.
- `vendor/bin/pint --format agent` sur les fichiers PHP du référentiel : conforme.
- `npx tsc --noEmit` : succès.
- Navigateur : arbre, fiche DSG, vue organigramme, fiche DSG-DSI.

## Anomalies restantes

- La version publiée n’est pas une photographie ligne à ligne de l’arbre. L’historique fin d’un libellé est dans le journal d’audit.
- La vue organigramme est une navigation par branches, sans canevas zoom et déplacement.
- La création et la modification des fonctions, ainsi que l’affectation d’un responsable, sont disponibles dans l’API. L’écran d’administration gère les structures, pas encore un formulaire d’affectation.
- Les formulaires de la chaîne de dépense ne proposent pas trois listes dépendantes département / direction / service.
- Les unités absentes du document officiel et déjà présentes en base ne sont pas désactivées automatiquement.
