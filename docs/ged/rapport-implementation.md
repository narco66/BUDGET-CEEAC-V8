# Rapport d’implémentation — module GED

Date de constat : 5 octobre 2026. Base : `budget_ceeac_v8`. Aucune réinitialisation.

## Ce qui fonctionne

- Dépôt d’une pièce sur une expression de besoin modifiable par son initiateur, avec référence `DOC-…`, empreinte SHA-256, stockage privé et liaison métier.
- Refus des extensions interdites et quarantaine lorsque le MIME réel ne correspond pas à l’extension. Le téléchargement d’un fichier en quarantaine répond `423`.
- Nouvelle version, une seule version courante, gel qui interdit une version suivante.
- Confidentialité : un autre initiateur ne voit pas le document confidentiel, ne le télécharge pas, et un export demandé avec son identifiant ne contient que le bordereau.
- Dossier de la chaîne : la pièce de l’EB est visible depuis l’engagement comme pièce héritée, sans second fichier.
- Versement des actes officiels après l’archivage PDF, idempotent sur `generated_document_id`. Un échec de versement n’annule pas l’acte.
- Complétude lue dans `document_types`. Le blocage de l’ouverture d’engagement n’est actif que si `system_settings.ged.bloquer_pieces` vaut `1`.
- Journal dans `audit_events`. Tâche « Vérifier le document » pour le contrôleur financier.
- Reprise de `eb_documents` : 90 pièces liées, 19 sans fichier exploitable laissées en anomalie, fichiers sources conservés. Seconde exécution : 0 reprise. `ged:integrite` : 0 anomalie.
- Écrans `/ged` et `/ged/{id}`, et panneau « Dossier documentaire » sur les fiches EB, ENG, LIQ, ORD et PAY.
- Contrôle navigateur du 5 octobre 2026, session Directeur du Budget : liste paginée réelle, fiche `DOC-2026-000090` (version, empreinte, liaison à l’expression de besoin 9), dossier de l’EB « aucune exigence applicable », et la même pièce affichée **héritée** sur `ENG-2026-000455`, avec complétude 0 % parce que la reprise n’a pas attribué le type `document_types` de l’engagement.

## Tests exécutés

`php artisan test --compact tests/Feature/GedTest.php` : 2 tests, 30 assertions, réussis.

La suite complète du dépôt n’a pas été relancée.

## Arbitrages

- Les tables s’appellent `ged_*` pour ne pas entrer en collision avec `document_types` (référentiel de pièces) ni avec `generated_documents` (actes append-only, qu’on ne met pas à jour).
- Les permissions `ged.*` de la description ne sont pas insérées dans le catalogue déjà figé. L’autorisation est : rôle tenu + politique du dossier + périmètre + confidentialité.
- Le blocage des pièces obligatoires est désactivé par défaut, parce que les pièces d’engagement sont déjà marquées obligatoires et qu’un blocage immédiat stopperait la chaîne existante, y compris le Budget 2026.
- Aucune obligation documentaire nouvelle n’a été inventée. Seules les lignes `document_types` déjà présentes comptent.

## Limites

- Pas d’antivirus réel, pas d’OCR, pas de PDF/A, pas d’aperçu intégré, pas d’URL signée, pas de favoris, pas d’écran d’administration des types.
- Marchés, fournisseurs, GAR, suivi-évaluation, recettes et clôture ne déposent pas encore dans cette GED.
- La cascade historique `eb_documents` → expression de besoin n’a pas été changée. La fiche GED survit ; le fichier source peut encore disparaître si l’expression est supprimée physiquement.
- La numérotation `DOC-` est verrouillée par lecture de la dernière référence, pas par une séquence PostgreSQL dédiée.
- Le versement automatique ne s’applique qu’aux prochains appels de `archive()`. Les actes déjà produits ne sont pas repris rétroactivement.

## Dépendance à brancher

Pour rendre l’analyse obligatoire en production : installer un analyseur, implémenter l’appel derrière le drapeau `GED_ANALYSE_OBLIGATOIRE`, et ne sortir un fichier de quarantaine que par une décision autorisée. Aujourd’hui, avec le drapeau à `false`, seuls les fichiers au MIME incohérent sont isolés.
