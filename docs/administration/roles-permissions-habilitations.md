# Rôles, permissions et habilitations

Le rôle dit ce qu’un agent peut faire. L’habilitation dit sur quel périmètre, avec quel plafond et pendant quelle période. Le contrôle d’une opération financière reste dans Laravel.

## Architecture

Les comptes, les rôles et les permissions déjà présents sont la source. Il n’y a pas de second catalogue de rôles.

- `users.role` est le rôle principal. Il est toujours pris en compte tant que le rôle est actif.
- `user_roles` porte les habilitations ajoutées : statut, dates, plafond en FCFA entier, origine, périmètre (unité organisationnelle) et dérogation.
- `role_permission.level` vaut `global`, `scoped` ou l’absence de ligne (`denied`).
- `sod_rules` porte les incompatibilités. Les deux règles actives restent : ordonnateur / comptable, initiateur / contrôleur financier.
- `User::porte($permission, $unite)` lit le niveau. Sans catalogue posé, le rôle historique reste la référence.

Une case de la matrice n’est un contrôle serveur que si elle est marquée « contrôlé ». Ce sont les cinq permissions déjà appliquées :

| Case | Permission | Effet |
|---|---|---|
| Expressions de besoins · Créer | `eb.creer` | création d’un besoin |
| Engagements · Valider | `engagement.viser` | visa du contrôleur |
| Liquidations · Valider | `liquidation.viser` | visa du contrôleur |
| Paiements · Signer | `paiement.signer` | signature du comptable |
| Paiements · Exécuter | `paiement.executer` | exécution du paiement |

Les autres cases sont enregistrées et affichées dans les droits effectifs. Elles ne remplacent pas les circuits de dépense. La signature d’un ordonnancement suit le seuil de délégation (`ord_delegations`) et, s’il existe, le plafond de l’habilitation.

## Niveaux

- **Global** : le droit n’est pas limité par le périmètre de consultation.
- **Périmètre** : si une unité est fournie au contrôle, elle doit appartenir au périmètre du compte. Sans périmètre enregistré, le compte voit toute la chaîne, comme auparavant.
- **Non accordé** : la liaison est retirée. Le droit n’est plus porté.

Deux droits ne se mélangent pas. Consulter toute la Commission n’élargit pas une validation limitée à une direction : ce sont deux cases.

## Habilitation

La soumission crée une ligne `en_attente`. Elle n’ouvre aucun droit. Une tâche « Valider l’habilitation… » est adressée au rôle `ordonnateur`.

La validation ou le rejet est ouvert à l’administrateur des habilitations et à l’ordonnateur. Suspendre ou révoquer une habilitation active retire le droit ajouté. Le rôle principal d’un compte ne se retire pas par cette voie.

Un cumul interdit par `sod_rules` est refusé. Une dérogation exige un motif ; elle reste en attente jusqu’à la décision, qui est journalisée.

La commande `habilitations:expirer`, planifiée chaque jour à 07:45, passe en `expiree` les habilitations actives dont la date de fin est dépassée.

## Plafond

`User::plafondActif()` retient le plus bas plafond des habilitations actives. À la signature d’un ordonnancement, un montant supérieur est refusé et un événement `plafond_depasse` est écrit. L’absence de plafond ne modifie pas le seuil de délégation existant.

## API

- `GET /api/v1/admin/roles-permissions`
- `PUT /api/v1/admin/roles/{code}/matrice`
- `GET /api/v1/admin/habilitations`
- `GET /api/v1/admin/habilitations/conflit`
- `POST /api/v1/admin/habilitations`
- `POST /api/v1/admin/habilitations/{id}/decision`
- `GET /api/v1/admin/utilisateurs/{id}/droits`

L’écran Rôles et permissions est réservé aux administrateurs et à l’auditeur. L’enregistrement de la matrice est réservé à l’administrateur des habilitations. L’ordonnateur peut lire les habilitations et décider.

## Pages

- `/administration/roles-permissions`
- `/administration/habilitations`
- `/administration/utilisateurs/{id}`
- `/administration/audit`
- `/administration/suivi-acces` conserve le suivi déjà en place : droits de la chaîne, incompatibilités, intérims, délégations administratives.

## Hors périmètre de ce chantier

La pièce justificative n’est pas encore versée dans la GED. L’export Excel de la liste n’est pas branché. Les cases non marquées « contrôlé » ne ferment pas les autres modules. Les codes courts de la maquette (ADM, ORD, GC…) ne remplacent pas les codes de rôles déjà en base.
