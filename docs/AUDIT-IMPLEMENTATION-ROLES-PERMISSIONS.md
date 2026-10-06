# Audit et implémentation des rôles, permissions et habilitations

Date : 4 octobre 2026. Le module complète l’autorisation déjà en place. Il ne crée pas un second moteur. Le contrôle serveur reste : rôle détenu, permission appliquée lorsqu’elle existe, périmètre de structure, étape du dossier, seuil de signature et séparation des fonctions.

## Ce qui est administrable

| Objet | CRUD disponible | Autorisation | Périmètre | Règles métier | Tests | Point restant |
|---|---|---|---|---|---|---|
| Rôle | Consultation, recherche, modification du libellé et du statut, suppression refusée pour un rôle système | Administrateur des habilitations | Le rôle lui-même | Un rôle inactif ne compte plus. Le rôle d’administrateur des habilitations ne se désactive pas. Le code ne change pas. | Désactivation refusée, suppression d’un rôle système refusée | Pas de duplication d’un rôle vers un nouveau code : un code nouveau ne crée pas une étape de workflow |
| Permission de chaîne | Accord et retrait sur un rôle, avec motif | Administrateur des habilitations | Le rôle qui porte la permission | Cinq permissions sont appliquées : `eb.creer`, `engagement.viser`, `liquidation.viser`, `paiement.signer`, `paiement.executer`. Une permission inconnue ou non appliquée est refusée. | L’auditeur reçoit puis perd `eb.creer` ; la création d’expression suit. `ordonnancement.signer` est refusé dans la matrice | Les autres codes du catalogue décrivent des contrôles qui restent liés au rôle, au périmètre ou au seuil |
| Habilitation utilisateur | Attribution et révocation d’un rôle supplémentaire | Administrateur des habilitations, pas sur son propre compte | Structures du compte | La révocation conserve la ligne au statut `revoquee`. Le rôle principal ne se retire pas ici. L’auto-élévation est refusée. | Attribution puis retrait d’`expert_budget` | Les dates d’habilitation sont stockées ; l’écran ne saisit pas encore une période future distincte de l’intérim |
| Périmètre | Remplacement des structures autorisées | Administrateur des habilitations | Unités organisationnelles | Aucune structure sélectionnée signifie un périmètre non limité. Les permissions d’un rôle ne s’élargissent pas au périmètre d’un autre. | Périmètre posé puis retiré | Exercice, projet et financement ne sont pas une seconde dimension de périmètre |
| Délégation administrative | Création et révocation | Administrateur des habilitations | Fonction et période saisies | Une délégation révoquée ou échue n’est plus effective. Elle ne signe pas un ordonnancement et ne réécrit pas l’auteur d’une décision. | Création puis révocation | Le plafond financier reste la délégation d’ordonnancement, écran « Délégations et seuil » |
| Intérim | Ouverture et clôture | Administrateur des habilitations | Structure du titulaire | L’intérim donne le rôle du titulaire pendant la période. Il ne copie pas les rôles supplémentaires du titulaire. | Intérim existant, déjà couvert | L’acte GED n’est pas exigé par une procédure déjà formalisée dans l’application |
| Incompatibilité | Création et désactivation | Administrateur des habilitations | Les deux rôles nommés | Deux rôles incompatibles et actifs ne peuvent pas être cumulés. Les règles déjà en base restent : ordonnateur/comptable, initiateur/contrôleur financier. | Création, blocage de l’attribution, désactivation, attribution ensuite possible | Pas d’incompatibilité d’actions sur un même dossier au-delà de ces couples |
| Droits effectifs | Diagnostic sans exécution | Administrateur des habilitations | Structure et montant demandés | Le diagnostic lit la même règle que l’action. Il ne connecte pas le compte. | Diagnostic d’un visa et d’une consultation hors périmètre | — |

## Permissions appliquées

| Code | Rôle posé au catalogue | Effet serveur |
|---|---|---|
| `eb.creer` | Initiateur | Création d’une expression de besoin |
| `engagement.viser` | Contrôleur financier | Visa, dossier à l’étape du contrôleur |
| `liquidation.viser` | Contrôleur financier | Visa de liquidation, même étape |
| `paiement.signer` | Agent comptable | Autorisation d’un paiement à signer |
| `paiement.executer` | Comptable | Exécution d’un paiement autorisé |

Le catalogue initial est posé une fois (`habilitations.catalogue_initial`). Un retrait ultérieur n’est pas réécrit par le démarrage. Tant que ce catalogue n’est pas posé, le rôle historique reste la référence, pour ne pas bloquer les jeux de tests qui n’ont pas encore les liens.

La signature d’ordonnancement n’est pas une case de matrice. Elle suit le rôle compétent et le plafond de la délégation en XAF. L’administration des habilitations et le paramétrage lisent le rôle principal : un rôle ajouté ou un intérim ne les ouvre pas.

## Sécurité conservée

- Refus par défaut d’une action sans règle publiée.
- Un administrateur n’ajoute ni ne retire un rôle sur son propre compte.
- Le dernier administrateur des habilitations actif ne se désactive pas.
- Le rôle d’administrateur des habilitations ne se désactive pas.
- Un initiateur ne peut pas modifier la matrice.
- Les décisions historiques et les mots de passe ne sont pas réécrits. L’audit enregistre l’auteur, l’objet, l’avant, l’après et le motif, sans secret.

## Migration

La migration `2026_10_04_193330_add_habilitation_administration_columns` ajoute les métadonnées des permissions, le caractère système des rôles, le statut et les dates des habilitations, et l’activation des incompatibilités. Elle ne supprime aucun compte, aucune ligne budgétaire et aucune structure. Les liens initiaux reproduisent les rôles déjà compétents. Ils n’ouvrent pas un accès global.

## Pages et API

Écran : `/administration/habilitations`.

API ajoutées, toutes réservées à l’administrateur des habilitations :

- `PUT /api/v1/admin/roles/{code}`
- `DELETE /api/v1/admin/roles/{code}`
- `POST /api/v1/admin/roles/{code}/permissions`
- `POST /api/v1/admin/incompatibilites`
- `POST /api/v1/admin/incompatibilites/{id}/desactiver`
- `POST /api/v1/admin/delegations/{id}/revoquer`

`GET /api/v1/admin/matrice` accepte `q` et renvoie aussi les permissions appliquées.

## Tests exécutés

- `test_la_matrice_accorde_et_retire_un_droit_de_chaine` : 19 assertions, réussi.
- `test_la_verification_explique_le_droit_sans_executer_l_action` et `test_un_interim_accorde_le_role_du_titulaire_puis_se_cloture` : 28 assertions, réussis.
- `test_le_visa_verrouille_l_engagement_et_cree_la_liquidation` : 5 assertions, réussi.
- `test_le_circuit_execute_un_paiement_partiel_puis_le_rapproche` : 19 assertions, réussi.

La suite complète n’a pas été relancée.

## Écran vérifié

Sous le compte Amina OKO, l’écran Habilitations affiche les quatorze rôles, les cinq permissions appliquées, les deux incompatibilités actives et la délégation Directeur du Budget vers Blaise ESSONO. Aucune permission, incompatibilité ou délégation réelle n’a été modifiée pendant cette vérification. La session a été rendue au Directeur du Budget.
