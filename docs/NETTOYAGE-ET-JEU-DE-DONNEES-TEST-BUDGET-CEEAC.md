# Nettoyage et jeu de données de test — BUDGET-CEEAC / GESBUDEP

Lot `JEU-2026-10-04`. Base locale `budget_ceeac_v8` uniquement. Le budget voté, l’organigramme et les prévisions `DON-` n’ont pas été modifiés.

## Environnement traité

| Élément | Valeur |
|---|---|
| Application | `APP_ENV=local` |
| Moteur | PostgreSQL 18.4 |
| Hôte | `127.0.0.1:5433` |
| Base | `budget_ceeac_v8` |
| Exercice 2026 | `executoire` |
| Exercice 2027 | `preparation` |

La commande refuse la production, toute base autre que `budget_ceeac_v8`, et la base historique `budget_ceeac`. Aucun `migrate:fresh`, `db:wipe`, troncature générale ni désactivation de contrainte ou de déclencheur.

Les envois de courriel passent par le pilote `array` pendant la génération. Aucune instruction bancaire réelle n’est émise. Les tiers créés utilisent des adresses `@example.test`. Les pièces portent la mention `DOCUMENT DE TEST`.

## Sauvegarde et restauration

Sauvegardes `pg_dump -Fc` prises avant suppression, mot de passe non journalisé :

- `backend/storage/app/backups/budget_ceeac_v8-avant-lot-20261004-105244.dump` (585 747 octets) — état d’avant le nettoyage ;
- `backend/storage/app/backups/budget_ceeac_v8-avant-lot-20261004-105748.dump` (même taille) — second exemplaire du même état ;
- `backend/storage/app/backups/budget_ceeac_v8-avant-lot-20261004-105808.dump` (580 799 octets) — après retrait des recettes de test, avant régénération.

Restauration vers une base locale vide, avec le même utilisateur PostgreSQL, sans afficher le mot de passe :

```text
pg_restore -h 127.0.0.1 -p 5433 -U postgres -d budget_ceeac_v8 --no-owner --no-acl backend/storage/app/backups/budget_ceeac_v8-avant-lot-20261004-105748.dump
```

Arrêter l’application avant une restauration. Le fichier à utiliser pour revenir à l’état initial est `105748` (ou `105244`).

## Méthode d’identification

Un enregistrement n’est pas fictif parce que son libellé contient « test » ou que son montant paraît inhabituel.

Confirmés, d’après le code source :

- objets et justifications écrits par `ExpressionBesoinSeeder`, `EngagementSeeder` et `LiquidationSeeder` ;
- objets `Jeu d'essai%` produits par `demo:jeu-essai` ;
- prévisions dont le code commence par `PRV-` (l’import officiel n’écrit que des `DON-`) ;
- titres dont le motif est `Jeu d'essai%` ou `Audit recettes%`, et encaissements `VIR-REC-JEU-%` ;
- tiers `NIF-DEMO-%` et `JEU-NIF-%` ;
- marché dont l’objet commence par `Jeu d'essai` ;
- gel `JEU-ESSAI-GEL-2026`.

Origine incertaine, conservés :

- marché n° 1, « Fourniture de consommables informatiques DATI » (absent des seeders et du jeu d’essai) ;
- versions et nœuds GAR déjà publiés ;
- propositions 2027 rattachées aux lignes officielles ;
- utilisateurs `@ceeac.int`, nécessaires aux circuits.

## Données protégées

Empreinte identique avant et après génération (`officiel_conserve: true` dans `backend/storage/app/jeu-essai/lot-JEU-2026-10-04.json`) :

| Contrôle | Avant | Après |
|---|---:|---:|
| Lignes `officiel = true` | 363 | 363 |
| Somme `montant_vote` | 40 309 295 803 | 40 309 295 803 |
| Ajustements officiels | 0 | 0 |
| Empreinte SHA-256 des lignes | `41f3d1a0…a76e` | identique |
| Unités organisationnelles | 113 | 113 |
| Empreinte des sigles | `c89cd282…30a3` | identique |
| Prévisions `DON-` | 15 | 15 |
| Empreinte des dons | `df54cb51…0776` | identique |
| Exercices | 2026 exécutoire, 2027 en préparation | identique |

Conservés aussi : contributions des États, nomenclature officielle, `audit_events`, `generated_documents` (déclencheur d’ajout seul), séquences de numérotation (non rembobinées).

Les sept lignes `officiel = false` (`220345`, `310101`, `310456`, `410189`, `410234`, `420156`, `430234`) restent. Leurs dossiers ont des journaux qui interdisent la suppression du parent. Elles ne font pas partie du vote officiel.

## Règle qui a limité les suppressions

PostgreSQL interdit `DELETE` et `UPDATE` sur `eb_events`, `eng_events`, `liq_events`, `ord_events`, `pay_events`, `audit_events` et `generated_documents`. Il interdit aussi `DELETE` sur `paiement_executions` : une exécution se rejette, elle ne s’efface pas. Ces journaux référencent les dossiers avec `ON DELETE RESTRICT`.

Conséquence : les 25 expressions de besoin, 13 engagements, 9 liquidations, 4 ordonnancements et 3 paiements présents au départ sont conservés, y compris leurs exécutions. Les désactiver pour pouvoir les effacer aurait contourné la règle de conservation. Ils restent identifiés comme données de démonstration par leurs objets et leurs références de seeder.

Cinq expressions n’avaient pas de journal, mais un engagement ou un événement les retenait encore. Aucune expression n’a donc été supprimée.

Les mesures et réalisations déjà validées (`valide`, `consolide`) sont également protégées par déclencheur. L’indicateur `IND-JEU-203232` et la réalisation « Premier atelier tenu. » sont restés en place. La relance du jeu d’essai ne les duplique pas.

## Volumes

Retirés lors du passage, puis reconstitués par les workflows lorsque le scénario existe encore :

| Élément | Retirés | Reconstitués ou ajoutés |
|---|---:|---:|
| Prévisions `PRV-` | 3 | 1 (`Jeu d'essai — produits de documentation`, 12 000 000) |
| Titres de recette | 2 | 2 |
| Encaissements | 2 | 3 |
| Tiers non référencés par un dossier conservé | 2 | 2 (`Imprimerie du Golfe`, `Cabinet Conseil Ogooué`) |
| Marché de jeu d’essai | 1 | 1 (nouvel identifiant 3) |

Ajoutés par-dessus la chaîne conservée, via les services métier :

| Scénario | Résultat |
|---|---|
| Engagement annulé avant liquidation | `ENG-2026-003894` annulé, crédit de la ligne `66101` libéré |
| Règlement de caisse, 200 000 | rapproché, sous le plafond de caisse |
| Règlement par chèque, 300 000 | rapproché |
| Acompte, ligne `66105` | `PAY-2026-000006`, 800 000 payés sur 2 000 000, statut `paye_partiel` |
| Campagne `JEU-PREP-2027` | brouillon, non ouverte, 2027 non adopté |
| Délégation temporaire | visa budgétaire, motif de jeu d’essai, jusqu’au 31 décembre 2026 |
| Titre soldé | 1 500 000 encaissés et rapprochés |
| Titre partiel | 8 000 000, acompte 3 000 000, relance vers `facturation@example.test` |
| Encaissement non identifié | `VIR-REC-JEU-002`, 500 000 |
| Gel | 1 000 000 sur la ligne officielle `21323`, acte `JEU-ESSAI-GEL-2026` |

État lu après traitement : 30 expressions de besoin, 17 engagements, campagne en brouillon, 363 lignes officielles, vote inchangé. L’écran Engagements affiche 17 dossiers. La fiche `ENG-2026-003897` ouvre la chaîne EB `EB/2026/DSG-DRHMG/000468` → liquidation → `ORD-2026-000095` → `PAY-2026-000006` sur la ligne officielle `66105`. La liste des campagnes affiche `JEU-PREP-2027`.

Le brouillon `EB/2026/DSG-DRHMG/000467` (« paiement partiel ») est resté : la ligne `66104` a un vote nul, et son événement de création ne peut pas être effacé. Le scénario d’acompte a été rejoué sur `66105`.

## Données métier figées

Aucun tableau de démonstration, faux utilisateur ou fausse API n’a été trouvé dans `frontend/src` ni comme source d’affichage à la place de l’API. Les listes et fiches lisent PostgreSQL par les API Laravel. Les constantes de statut, d’urgence et de libellés d’interface n’ont pas été transformées en tables. Les seeders appelés par PHPUnit (`ExpressionBesoinSeeder` et la chaîne associée) n’ont pas été modifiés : ils restent les fixtures sqlite.

## Scénarios couverts

Le générateur est `php artisan demo:jeu-essai`. Il est idempotent et refuse de créer un exercice ou de clôturer 2026.

- Utilisateurs : acteurs `@ceeac.int` déjà affectés aux structures officielles, plus une délégation temporaire.
- Préparation : campagne `JEU-PREP-2027` en brouillon sur l’exercice 2027. Les 8 propositions existantes sont conservées. Aucune adoption.
- Chaîne de la dépense : brouillon, soumis, retourné, rejeté, ordonnancement à signer, virement rapproché, caisse, chèque, acompte partiel, annulation avant visa.
- Recettes : prévision, titre partiellement encaissé, titre soldé, encaissement non rattaché, relance.
- Marchés et tiers : trois fournisseurs de jeu d’essai et le contrat de licences. Le marché DATI est inchangé.
- Suivi : indicateur `IND-JEU-203232`, réalisation, écart, risque, recommandation, évaluation, rapport.
- Planification : versions GAR existantes conservées. Tâches de jeu d’essai sur l’activité de la ligne `203232`.
- Notifications et tâches : produites par les workflows. Le journal des événements de circuit n’est pas purgé.

Les cas invalides restent dans les tests PHPUnit, pas dans ce lot.

## Commandes

Depuis `backend/` :

```text
php artisan demo:lot
php artisan demo:lot --executer
php artisan demo:jeu-essai
```

`demo:lot` sans option affiche l’aperçu et ne supprime rien. `--executer` sauvegarde, retire seulement le lot autorisé par les clés étrangères et les déclencheurs, compare l’empreinte officielle dans la transaction, puis appelle `demo:jeu-essai`. Une empreinte différente annule la transaction de suppression. Le journal est `backend/storage/app/jeu-essai/lot-JEU-2026-10-04.json`.

Relancer `demo:jeu-essai` ne réimporte pas le budget 2026 et ne duplique pas les dossiers déjà créés. `JeuEssaiTest` : 1 test, 11 assertions, réussi.

## Contrôles

- Empreintes officielles identiques avant et après.
- `PAY-2026-000006` : 800 000 sur 2 000 000, virement, `paye_partiel`.
- Caisse et chèque rapprochés. Engagement `ENG-2026-003894` annulé.
- Campagne `JEU-PREP-2027` en brouillon. Exercice 2027 toujours en préparation. Exercice 2026 toujours exécutoire.
- Écran Engagements : 17 dossiers, fiche de l’acompte ouverte jusqu’au paiement.
- Écran Campagnes : `JEU-PREP-2027` visible. Le bouton d’adoption n’a pas été utilisé.
- Sept lignes non officielles toujours présentes, hors du vote de 40 309 295 803.

## Limites

- La chaîne de dépense historique (seeders et premier jeu d’essai) ne peut pas être effacée sans supprimer des journaux en ajout seul. Elle reste dans les listes et dans les agrégats d’exécution. Le vote officiel n’en fait pas partie.
- Les sept lignes de nomenclature de démonstration restent pour la même raison.
- Le brouillon sur la ligne `66104` (vote nul) reste, avec son événement de création.
- Le marché « Fourniture de consommables informatiques DATI » est d’origine incertaine et n’a pas été modifié, hormis les clés facultatives que la base met à nul si un parent de démonstration disparaît. Son rattachement n’a pas été le parent supprimé.
- `generated_documents` et `audit_events` n’ont pas été purgés.
- Les pièces obligatoires d’engagement (bon de commande, devis, etc.) ne sont pas toutes générées : la note `DOCUMENT DE TEST` est jointe à l’expression de besoin. La fiche de l’acompte signale 0/7 pièces d’engagement.
- Aucune clôture d’exercice et aucun fichier d’import de recette supplémentaire n’ont été produits. Les imports officiels restent `ceeac:importer-2026`, qui n’a pas été relancé.
- Les notifications internes ont augmenté (22 non lues pour le Directeur du Budget au moment du contrôle). Elles n’ont pas été marquées lues en masse.

## Matrice

| Domaine | Anciennes données supprimées | Nouvelles données créées | Scénarios couverts | Vérification |
|---|---:|---:|---|---|
| Utilisateurs et délégations | 0 | 1 délégation | Intérim de visa jusqu’au 31/12/2026 | Motif de jeu d’essai, acteurs existants |
| Préparation | 0 | 1 campagne | Brouillon 2027, sans ouverture ni adoption | Écran Campagnes, statut `brouillon` |
| Lignes et nomenclature | 0 | 0 | Réutilisation des lignes officielles | 363 lignes, vote 40 309 295 803 |
| Organigramme | 0 | 0 | Structures officielles réutilisées | 113 unités, empreinte inchangée |
| Expressions de besoin | 0 | 5 | Brouillon, soumis, retourné, rejeté, transformés | 30 dossiers, journaux conservés |
| Engagements | 0 | 4 | Annulé, visés, transformés | 17 dossiers à l’écran |
| Liquidations et ordonnancements | 0 | 3 liquidations, 3 ordonnancements | Service fait puis ordre | Chaîne visible sur `ENG-2026-003897` |
| Paiements | 0 | 3 | Caisse, chèque, acompte 800 000 / 2 000 000 | `PAY-2026-000006` en `paye_partiel` |
| Recettes | 3 prévisions, 2 titres, 2 encaissements | 1 prévision, 2 titres, 3 encaissements | Prévision, acompte, solde, non identifié, relance | Titre 8 000 000 conservé par le test |
| Marchés et tiers | 1 marché, 2 tiers libres | 1 marché, 2 tiers | Contrat de licences, adresses `@example.test` | Marché DATI n° 1 conservé |
| Suivi-évaluation et GANTT | 0 | écart, risque, recommandation, rapport | Activité officielle `203232` | Indicateur validé non dupliqué |
| Notifications et tâches | notifications du lot retiré, selon le texte | issues des workflows | Non lues, lien vers un dossier réel | 22 non lues, tâches présentes |
| GED et PDF | fichiers devenus orphelins seulement | notes `DOCUMENT DE TEST` | Pièce d’EB | Journal `generated_documents` intact |
| Budget officiel et dons | 0 | 0 | Aucune réimportation | Empreintes SHA-256 identiques |
