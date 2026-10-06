# Audit et implémentation des rôles et habilitations

Date : 4 octobre 2026. Application BUDGET-CEEAC / GESBUDEP. L’autorisation déjà en service est conservée. Aucun second moteur de droits n’a été introduit, et aucun droit supplémentaire n’a été accordé par défaut.

## Architecture retenue

L’accès effectif reste la conjonction déjà codée :

**compte actif + rôle détenu (`User::holds`) + périmètre de structures + policy du dossier + étape du workflow + seuil d’ordonnancement lorsqu’il s’applique.**

| Notion | Réalisation actuelle |
|---|---|
| Utilisateur | `users`, authentification Sanctum, `ResolveActor` refuse un compte suspendu, désactivé ou archivé |
| Fonction organisationnelle | `function_title` et le référentiel d’organisation. Elle ne donne pas, à elle seule, un droit applicatif |
| Affectation | `organization_unit_id` du compte |
| Rôle applicatif | `users.role` (rôle principal) et rôles ajoutés dans `user_roles` |
| Permission atomique | Table `permissions`, rattachée à quelques rôles d’administration. Elle documente l’administration. Elle n’autorise pas les actions de la chaîne |
| Périmètre | `access_scopes` de type `organization_unit`. Liste vide = pas de restriction. Une liste limite la consultation des dossiers de ces structures |
| Habilitation | Rôle principal attribué à la création, rôle ajouté, périmètre, intérim |
| Délégation administrative | `admin_delegations`. Elle ne fait pas tenir le rôle du délégant |
| Intérim | `substitutions`. Pendant la période active, `holds()` voit le rôle du titulaire. La clôture retire cet effet |
| Acteur de workflow | Rôle attendu par l’étape, plus la policy (initiateur du dossier, contrôleur, ordonnateur compétent, agent ou comptable) |
| Administration des habilitations | `AdminGate` : le **rôle principal** `administrateur_habilitations`. Un rôle ajouté ou un intérim n’ouvre pas cet écran |
| Paramétrage | Rôle principal `administrateur_fonctionnel` |
| Audit en lecture | Rôles principaux `administrateur_habilitations`, `administrateur_fonctionnel`, `auditeur` |

Le refus est le défaut : une action absente du catalogue de vérification est refusée, et `AdminGate` refuse toute capacité inconnue.

## Catalogue des actions vérifiables

Ces actions reprennent les contrôles déjà présents dans les policies et workflows. Le catalogue n’est pas éditable dans l’interface.

| Module / action | Permission effective | Périmètre | Conditions métier | Acteur compétent | Contrôle backend | Test |
|---|---|---|---|---|---|---|
| EB · créer | rôle `initiateur` | — | Dossier encore éditable par son initiateur pour la suite | Initiateur | `NeedPolicy::create` | Vérification : règle publiée |
| EB · consulter | tout rôle détenu | Structures du compte, si une liste existe | — | Tout compte actif habilité | `NeedPolicy::view` et `seesOrganization` | Test : structure hors périmètre refusée |
| Engagement · viser | `controleur_financier` | Structure du dossier | Étape contrôleur, dossier non verrouillé | Contrôleur financier | `EngagementPolicy::vise` | Test : contrôleur autorisé, initiateur refusé |
| Engagement · consulter | tout rôle détenu | Structure du dossier | — | Compte habilité | `EngagementPolicy::view` | Même règle de périmètre que la consultation |
| Liquidation · viser | `controleur_financier` | Structure du dossier | Étape contrôleur | Contrôleur financier | `LiquidationPolicy::vise` | Règle publiée, non rejouée sur un dossier |
| Liquidation · certifier le service fait | identité de l’initiateur du dossier | — | Étape initiateur | Initiateur du dossier, pas un rôle générique | `LiquidationWorkflow::isInitiator` | Non simulé : l’identité du dossier est requise |
| Ordonnancement · signer | rôle calculé par le montant | — | Statut à signer, en plus du rôle | SG si une délégation courante couvre le net ; sinon ordonnateur principal | `OrdonnancementWorkflow::authority` puis `OrdonnancementPolicy::sign` | Test : explication sur 1 000 XAF, aucun ordre créé |
| Paiement · autoriser | `agent_comptable` | — | Statut à signer et confirmation | Agent comptable | `PaiementWorkflow::signer` | Règle publiée |
| Paiement · exécuter | `comptable` | — | Statut autorisé ou partiel, montant dans le solde, preuve | Comptable | `PaiementWorkflow::executer` | Règle publiée |
| Administration · consulter | rôle principal lecteur | — | — | Habilitations, fonctionnel, auditeur | `AdminGate::consulter` | Test : initiateur reçoit 403 |
| Administration · habilitations | rôle principal `administrateur_habilitations` | — | — | Amina OKO dans le jeu d’essai | `AdminGate::habilitations` | Test : initiateur 403, invité 401 |
| Administration · paramétrer | rôle principal `administrateur_fonctionnel` | — | — | Administrateur fonctionnel | `AdminGate::parametrage` | Déjà couvert par les tests de paramétrage |

Les autres modules (préparation, recettes, suivi-évaluation, GED, imports) continuent d’appliquer leurs policies et leurs `holds()` existants. Leurs règles n’ont pas été recopiées dans un catalogue éditable.

## Périmètres

- Aucune ligne dans `access_scopes` : le compte consulte toutes les structures.
- Une ou plusieurs structures : `restrictOrganization` filtre les listes, et `seesOrganization` refuse un dossier hors liste, y compris par identifiant.
- Le périmètre limite la consultation. Il ne donne pas le visa, la signature ni le paiement.
- Le rattachement hiérarchique du compte n’élargit pas le périmètre.
- Les descendants d’une structure, le projet et le financement ne sont pas des périmètres : aucune procédure du code ne les définit. Ils ne sont pas accordés.

## Incompatibilités

Règles bloquantes déjà en base :

- `ordonnateur` avec `comptable` ;
- `initiateur` avec `controleur_financier`.

Elles s’appliquent à l’ajout d’un rôle et à l’ouverture d’un intérim. Elles ne déduisent pas d’autres couples. Un cumul non listé reste possible : ce n’est pas une interdiction implicite.

Sur un même dossier, les policies séparent déjà l’initiateur, le contrôleur, l’ordonnateur et les comptables. L’initiateur d’une liquidation certifie son service fait ; il ne vise pas la liquidation.

## Délégations, intérims et seuils

- Le seuil d’ordonnancement est lu dans `ord_delegations` courantes (`seuil_max`, entier XAF). Il n’est pas recopié dans les écrans.
- Une délégation administrative (`admin_delegations`) trace un mandat. Elle ne fait pas `holds()` du rôle délégué.
- L’intérim, lui, fait tenir le rôle du titulaire entre les deux dates, puis plus rien après clôture. Les décisions déjà prises gardent leur auteur.
- Une délégation échue n’est pas effective. Une sous-délégation et un cycle ne sont pas des fonctions offertes.
- L’habilitation d’administration ne se transmet pas par intérim : `AdminGate` lit le rôle principal.

## Garde-fous ajoutés

- Un administrateur des habilitations ne peut pas ajouter un rôle à son propre compte. La tentative est journalisée (`role.autoelevation_refusee`).
- Il ne retire pas non plus un rôle de son propre compte.
- Le rôle principal ne se retire pas par l’action de retrait. Seul un rôle ajouté dans `user_roles` peut l’être.
- Le dernier compte actif dont le rôle principal est `administrateur_habilitations` ne peut pas être désactivé. La désactivation de son propre compte est aussi refusée.
- La désactivation supprime les jetons Sanctum du compte. `ResolveActor` refuse ensuite le compte. Les dossiers et l’historique restent.
- « Vérifier les droits » explique le résultat pour un compte, une action, une structure et un montant. Elle ne change pas de session et n’écrit pas le dossier.

## Écrans et API

- `GET /api/v1/admin/habilitations/catalogue`
- `POST /api/v1/admin/habilitations/verifier`
- `POST /api/v1/admin/utilisateurs/{user}/roles/retirer`
- Écran Habilitations : formulaire « Vérifier les droits ».
- Fiche utilisateur : rôles ajoutés, avec retrait.

Les écrans déjà en place restent : matrice des rôles, séparation des fonctions, délégations, intérims, revue périodique, périmètre, journal d’administration.

La création libre d’une permission dans l’interface n’est pas offerte : une permission sans contrôle serveur ne doit pas exister.

## Migrations

Aucune. Les tables `roles`, `permissions`, `user_roles`, `access_scopes`, `substitutions`, `sod_rules` et `admin_delegations` suffisent.

## Tests exécutés

`php artisan test --compact tests/Feature/AdministrationTest.php` avec les filtres suivants, tous réussis :

- `test_un_administrateur_ne_s_eleve_pas_et_le_dernier_reste_actif` : invité 401, initiateur 403, auto-attribution 422, dernier administrateur toujours actif.
- `test_la_verification_explique_le_droit_sans_executer_l_action` : visa autorisé pour un contrôleur et refusé pour un initiateur, action inconnue refusée, structure hors périmètre refusée, rôle ajouté puis retiré, rôle principal non retiré, signature expliquée sans création d’ordonnancement. 29 assertions sur ces deux tests.
- `test_deux_roles_incompatibles_sont_refuses`, `test_un_compte_desactive_ne_se_connecte_plus_mais_garde_son_historique`, `test_un_interim_accorde_le_role_du_titulaire_puis_se_cloture` : toujours réussis après les garde-fous.

La suite complète de l’application n’a pas été relancée.

## Anomalies et limites restantes

- Le tableau `permissions` ne couvre pas toutes les actions de la chaîne. L’étendre sans brancher chaque policy créerait un catalogue mensonger. Il n’a donc pas été élargi.
- Un rôle ajouté à un autre compte continue de donner ce rôle via `holds()`. C’est le comportement d’habilitation déjà en place. L’administrateur des habilitations peut l’attribuer, sauf à lui-même, et sauf incompatibilité.
- L’initiation Hors PAP n’est pas réservée au Service des Moyens Généraux : aucune procédure de `/docs` ne nomme cette unité comme filtre, et les dossiers existants restent valides.
- La certification du service fait dépend de l’initiateur du dossier. Elle n’est pas une permission générale « certifier ».
- Les périmètres « descendants », « projet » et « financement » ne sont pas définis dans le code. Ils ne sont pas créés.
- Une interdiction de cumul qui n’est pas dans `sod_rules` n’est pas inventée.
- Les PDF et décisions déjà archivés gardent leur auteur. La vérification des droits ne les réécrit pas.
- Le menu masque les entrées selon le rôle, mais le refus serveur reste celui de `AdminGate` et des policies.
