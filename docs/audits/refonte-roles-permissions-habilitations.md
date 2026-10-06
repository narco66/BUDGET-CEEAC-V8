# Refonte rôles, permissions et habilitations

## 1. État initial

Le module reposait sur `users.role`, `user_roles`, `role_permission`, `sod_rules`, `access_scopes` et cinq permissions réellement contrôlées (`eb.creer`, `engagement.viser`, `liquidation.viser`, `paiement.signer`, `paiement.executer`). L’écran `/administration/habilitations` regroupait la matrice descriptive, les incompatibilités, les intérims et les délégations administratives. La fiche utilisateur était un tiroir, sans droits effectifs calculés.

## 2. Anomalies détectées

- Le rôle seul ouvrait le droit, sans niveau global / périmètre / non accordé.
- Une habilitation ajoutée devenait active immédiatement, sans validation.
- Le plafond d’une habilitation n’existait pas. Le seul plafond d’ordonnancement est le seuil de délégation.
- La fiche utilisateur n’affichait pas la provenance des droits.
- La maquette utilise des codes d’exemple (ADM, ORD, ODL). Les rôles réels ont d’autres codes (`administrateur_fonctionnel`, `ordonnateur`, `controleur_financier`…). Les remplacer aurait cassé les circuits.
- Plusieurs cases de la maquette (consulter le budget, exporter le reporting, etc.) n’ont pas de contrôle Laravel dédié. Les afficher comme des verrous aurait été faux.

## 3. Éléments supprimés

Aucun écran existant n’a été retiré. L’ancien écran est déplacé vers `/administration/suivi-acces`, parce que les intérims, les délégations administratives et la revue des accès y sont encore utilisés.

## 4. Éléments modifiés

- `backend/app/Models/User.php` — `porte()` lit le niveau et, s’il est limité, le périmètre ; `plafondActif()`.
- `backend/app/Domains/Administration/Models/Role.php` — pivot `level`.
- `backend/app/Domains/Commitments/Services/OrdonnancementWorkflow.php` — refus si le montant dépasse le plafond d’habilitation.
- `backend/routes/api/v1/admin.php`, `backend/routes/console.php`.
- `frontend/src/app/navigation.ts`, `frontend/src/app/router/AppRouter.tsx`.
- `frontend/src/features/administration/pages/AdminUsersPage.tsx`, `AdminHomePage.tsx`.
- `frontend/src/styles/layout.css`.
- `backend/tests/Feature/AdministrationTest.php`.

## 5. Éléments créés

- `backend/database/migrations/2026_10_04_214500_add_matrix_levels_and_grant_limits.php`
- `backend/app/Domains/Administration/Services/GestionHabilitations.php`
- `backend/app/Domains/Administration/Http/Controllers/HabilitationBoardController.php`
- `frontend/src/features/administration/pages/RolesPermissionsPage.tsx`
- `frontend/src/features/administration/pages/HabilitationsPage.tsx`
- `frontend/src/features/administration/pages/AuditJournalPage.tsx`
- `docs/administration/roles-permissions-habilitations.md`

## 6. Base de données

Migration appliquée sur `budget_ceeac_v8` :

- `role_permission.level` (`scoped` par défaut, donc les liaisons existantes restent accordées dans le périmètre) ;
- `user_roles.plafond_fcfa`, `origine`, `scope_unit_id`, `derogation` ;
- index `(status, ends_on)`.

Aucune ligne budgétaire, aucun utilisateur et aucune règle de séparation n’a été supprimé.

## 7. Sécurité

- Refus par défaut : une case absente n’accorde rien.
- L’enregistrement de la matrice et la soumission d’une habilitation sont réservés à l’administrateur des habilitations.
- Un administrateur ne s’habilite pas lui-même.
- Le conflit de séparation est recalculé côté serveur. La case à cocher du formulaire ne suffit pas.
- Une habilitation en attente ne compte pas dans `holds()`.
- La signature d’ordonnancement n’est pas une case de matrice.
- Le journal d’audit n’est pas modifiable depuis la page.

## 8. Tests

`php artisan test --compact --filter=test_la_matrice_le_conflit_et_la_validation_d_une_habilitation` : 1 test, 20 assertions, succès.

`php artisan test --compact --filter=test_la_matrice_accorde_et_retire_un_droit_de_chaine` : 1 test, 19 assertions, succès.

Couvert : retrait d’un droit contrôlé, niveau limité puis global, refus SoD, dérogation en attente, validation, droits effectifs, expiration. Non couvert par un test de dossier : le refus de signature pour dépassement de plafond (le contrôle est dans `OrdonnancementWorkflow::sign`).

## 9. Conformité PDF

| Élément PDF | Implémenté | Fichier / route | Observation |
|---|---|---|---|
| Liste des rôles, recherche, agents | Oui | `/administration/roles-permissions` | Codes réels de la base, pas les sigles d’exemple |
| Matrice à trois niveaux | Oui | `role_permission.level` | Seules les cases « contrôlé » ferment un circuit |
| Séparation des tâches sur la fiche rôle | Oui | `sod_rules` | Les deux incompatibilités déjà en base |
| Indicateurs d’habilitations | Oui | `GET /admin/habilitations` | Calculés, pas figés |
| Filtres et liste paginée | Oui | même route | Pas d’export Excel |
| Formulaire et conflit immédiat | Oui | `POST /admin/habilitations` | Pas de dépôt de PDF dans la GED |
| Validation ordonnateur | Partiel | tâche `ordonnateur` | L’administrateur des habilitations peut aussi décider, sinon l’écran serait bloqué |
| Fiche utilisateur et droits effectifs | Oui | `/administration/utilisateurs/{id}` | Provenance et plafond affichés |
| Journal d’audit | Partiel | `/administration/audit` | Les 100 derniers événements, filtre dans la page |

## 10. Dette résiduelle

- Les cases non contrôlées ne sont pas encore appelées par les politiques des modules budget, reporting, suivi, recettes et documents.
- La pièce justificative et l’export Excel ne sont pas branchés.
- Le journal n’a pas de filtre serveur par adresse IP ni de fiche d’événement.
- La délégation financière reste le seuil d’ordonnancement. Elle n’est pas recopiée dans `user_roles`.
- Aucune habilitation de démonstration n’a été créée dans la base de travail.
