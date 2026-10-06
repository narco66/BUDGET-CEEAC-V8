# Audit global — BUDGET-CEEAC / GESBUDEP

Date : 4 octobre 2026. Environnement lu : `APP_ENV=local`, PostgreSQL, base `budget_ceeac_v8`. Les tests PHPUnit utilisent SQLite en mémoire et ne modifient pas cette base.

Cet audit ne conclut pas que l’application est opérationnelle de bout en bout. Il distingue ce qui a été exécuté, ce qui est déduit du code, et ce qui n’a pas été rejoué.

## Sources et contradictions

Il n’existe pas de dossier `/Docs` distinct de `/docs`. Le cahier des charges PDF et les fichiers Figma ne sont pas dans le dépôt. La référence fonctionnelle utilisée ici est l’ensemble des audits et procédures déjà écrits dans `/docs`, en particulier `AUDIT_CONFORMITE_CDC_V5.md`. La comparaison visuelle pixel à pixel avec Figma n’est donc pas vérifiable dans ce dépôt.

Versions installées, lues dans les manifeste et `composer show` :

| Composant | Version constatée |
|---|---|
| PHP | 8.3.10 |
| Laravel | 13.34.0 |
| React | 19.3 |
| Vite | 8 |
| Tailwind | 4 |
| Font Awesome (paquets free) | 7.3 |
| PostgreSQL | pilote `pgsql`, base `budget_ceeac_v8` |

Le dépôt n’est pas un dépôt Git à la racine ni dans `backend/`. Il n’y a donc pas d’historique de commits pour revenir en arrière. Aucune migration, aucun `migrate:fresh` et aucune clôture n’ont été lancés. L’exercice 2026 reste exécutoire. L’exercice 2027 reste en préparation. Aucune campagne n’a été créée ni adoptée.

Contradictions relevées :

- `docs/audits/AUDIT-GLOBAL-ZERO-MOCK-BUDGET-CEEAC.md` (2 octobre) dit encore que la préparation, les marchés et les recettes sont absents. Ces modules existent désormais (routes, tables, tests).
- `AUDIT_CONFORMITE_CDC_V5.md` affirmait encore que la preuve de paiement et le verrou de numérotation manquaient. Le code les contient. Ces deux lignes du document ont été corrigées.
- Deux chemins rendent un exercice exécutoire : le report des lignes (`POST /preparation/{exercice}/adopter`) et la transmission d’une version de campagne. Ils ne sont pas le même acte. Adopter le report de 2027 créerait les lignes votées. Cette action n’a pas été lancée.
- Les montants métier sont des entiers FCFA. Aucun montant décimal n’a été introduit.
- L’initiation Hors PAP n’est pas réservée au Service des Moyens Généraux : aucun document du dépôt ne nomme cette unité comme seul initiateur.

## Méthode et couverture

Vérifié par exécution le 4 octobre 2026 :

- `php artisan test --compact` depuis `backend/` : **162 tests, 1507 assertions, tous réussis** (environ 55 s). Base de test isolée.
- `npx tsc --noEmit` dans `frontend/` : succès.
- Lecture seule de `budget_ceeac_v8` (comptages et statuts). Le script temporaire a été supprimé.
- Navigateur, session Directeur du Budget sur `http://127.0.0.1:5173` : liste des engagements (13 dossiers, 560 600 000 FCFA), export Excel qui reste sur la page et ne quitte pas l’application ; menu Gouvernance sans Administration pour ce rôle (contrôle déjà en place).

Déduit du code, sans rejeu bouton par bouton de chaque transition :

- formules de soldes dans `BudgetBalanceService` ;
- autorisation serveur (`policies`, `AdminGate`, `authorize`) ;
- empreinte et vérification publique des actes (`DocumentsOfficielsTest`, inclus dans les 162 tests) ;
- MFA seulement si `mfa_required` est actif sur le compte.

Non vérifié dans cette passe :

- comparaison visuelle avec Figma ;
- décodage manuel d’un QR sur un PDF nouvellement généré ;
- parcours complet de création EB jusqu’au paiement sur la base vivante ;
- clôture réelle (volontairement non exécutée) ;
- revue de dépendances CVE ;
- temps de réponse et bundle de production (`npm run build` non lancé) ;
- sauvegarde et restauration PostgreSQL.

## Inventaire persistant

| Objet | Nombre |
|---|---|
| Exercices | 2026 exécutoire, 2027 préparation |
| Lignes budgétaires | 370, dont 363 officielles |
| Vote officiel | 40 309 295 803 FCFA |
| Vote toutes lignes | 42 519 295 803 FCFA |
| Campagnes / dossiers de préparation | 0 / 0 |
| Expressions de besoin | 25 |
| Engagements | 13 |
| Liquidations | 9 |
| Ordonnancements | 4 (3 transmis, 1 à signer) |
| Paiements | 3 (2 clôturés, 1 généré) |
| Marchés | 2 |
| Tiers | 12 |
| Prévisions de recettes | 18 |
| Titres de recettes | 2 |
| Versions GAR | 2 |
| Structures | 113 |
| Utilisateurs | 29 |
| Tâches | 77, dont 24 ouvertes |
| Notifications | 127 |
| Actes dans `generated_documents` | 1 |

Expressions de besoin : 13 transformées en engagement, 3 brouillons, 2 soumises, 2 retournées, 2 rejetées, 1 en validation, 1 en approbation, 1 approuvée.

Le changement d’acteur par `X-Actor-Id` est actif (`demo_impersonation` vrai) parce que l’environnement n’est pas `production`. La configuration l’ignore dès que `APP_ENV=production`.

## Soldes

Source unique : `BudgetBalanceService`.

- révisé = voté + ajustements + mouvements ;
- engagé = engagements ni rejetés ni annulés, nets des dégagements ;
- liquidé = liquidations visées et compléments visés ;
- ordonnancé = ordres signés, en erreur de transmission ou transformés en paiement ;
- payé = exécutions bancaires exécutées ;
- disponible = révisé − gelé − engagé − réservé (EB soumise, en validation, validée ou en approbation).

Une EB déjà transformée en engagement n’est plus réservée, pour ne pas compter deux fois le même crédit.

## Matrice

| Module / parcours | Référence métier | Anomalie et preuve | Criticité | Correction | Fichiers | Test et résultat | État final |
|---|---|---|---|---|---|---|---|
| Accès administration | Rôles lecteurs : administrateur des habilitations, administrateur fonctionnel, auditeur | Le menu montrait Administration au Directeur ; l’API répondait 403 et l’écran disait « Une erreur est survenue » | Majeure | Menu filtré par rôle ; message « Accès refusé » | `navigation.ts`, `AppShell.tsx`, `AdminGate.php`, `format.ts`, `Feedback.tsx` | `AdministrationTest` dans les 162 tests ; navigateur Directeur : entrées masquées | Corrigé |
| Exports EB, ENG, LIQ, ORD, PAY | Export du périmètre visible par l’acteur connecté | Les boutons étaient des liens `/api/v1/.../export` : pas d’en-tête `X-Actor-Id`, la page quittait l’application | Modérée | Téléchargement via le client HTTP authentifié | `utils/download.ts`, listes EB, ENG, LIQ, ORD, PAY | TypeScript vert ; clic navigateur sur Exporter des engagements : bouton occupé puis retour à l’état normal, URL inchangée, pas de message d’erreur | Corrigé |
| Preuve de paiement | PAI-005 | L’ancien audit disait qu’aucune pièce n’était exigée. `PaiementWorkflow::executer` refuse une exécution sans avis ou acquit | Majeure si le document restait faux | Ligne du CDC mise à jour. Le contrôle de code était déjà là | `PaiementWorkflow.php`, `AUDIT_CONFORMITE_CDC_V5.md` | Couvert par `PaiementTest` / `IntegriteChaineTest` dans la suite verte | Déjà en place |
| Numérotation | §62 | L’ancien audit parlait d’un « dernier + 1 » sans verrou. `NumberingService` verrouille `number_sequences` | Majeure si le document restait faux | Ligne du CDC mise à jour | `NumberingService.php` | Suite verte, dont le test de séquence qui refuse un recul | Déjà en place |
| Chaîne EB → paiement | Procédure de dépense, seuil 5 000 000 XAF | Le seuil est la délégation d’ordonnancement, pas un seuil d’EB. Un ordre reste `a_signer`. Un paiement est `genere` et n’a pas été exécuté pendant l’audit | — | Aucune transition lancée sur la base vivante | Workflows et seeders | 162 tests, dont la chaîne et l’intégrité | Parcours testé en base isolée ; non rejoué en entier sur `budget_ceeac_v8` |
| Préparation | Campagne, cadrage, adoption, transmission | Zéro campagne en base. Le report 2027 reste un second chemin d’adoption, libellé comme tel | Majeure si le report était cliqué | Bouton distinct, confirmation déjà présente. Non utilisé | `PreparationPage.tsx`, `PreparationModuleService.php` | `PreparationBudgetaireTest` et `CycleBudgetaireTest` dans la suite verte | Opérationnel en test ; 2027 non adopté |
| Budget et soldes | Budget unique, disponible | 363 lignes officielles, vote 40 309 295 803. Les 7 lignes de démonstration portent l’écart jusqu’à 42 519 295 803 | Modérée | Aucune réécriture des lignes officielles | `BudgetBalanceService.php` | `SoldesEtDegagementTest` vert | Cohérent avec la formule documentée |
| Recettes | Prévision, titre, encaissement | 18 prévisions, 2 titres. `DON-AUTRES` peut afficher un montant négatif issu de l’import 2026. Il n’a pas été réécrit | Modérée | Non modifié : donnée d’import officielle | Module recettes | `RecettesTest` vert | Partiel sur le signe de `DON-AUTRES` |
| Planification GAR | Pilier → tâche | 2 versions GAR. Pas de second référentiel stratégique | — | Aucune | `PlanificationGarTest` | Inclus dans la suite verte | Présent |
| Suivi-évaluation et Gantt | Réalisations, écarts, rapports | L’export s’appelle Excel et passe par le client authentifié. Les taux viennent de l’API | Mineure (ancien libellé Power BI) | Déjà renommé | `SuiviDashboardPage.tsx` | `SuiviEvaluationTest`, `SePagesTest` verts | Présent |
| Marchés et tiers | Marchés, comptes validés | 2 marchés, 12 tiers. Pas de fusion de doublons ni de pièces de conformité à échéance | Modérée | Non inventé | `TiersTest` | Vert | Partiel |
| Organisation | Structures, fonctions, dates d’effet | 113 unités. Versions et dates d’affectation existent dans le service. Les workflows de dépense résolvent encore un rôle applicatif unique, pas un intérim | Majeure pour la production multi-rôles | Non branché : cela changerait les acteurs des dossiers existants | `OrganizationService.php`, `users.role` | `OrganisationTest` vert | Partiel |
| Tâches et notifications | Cible cliquable, pas de validation à l’ouverture | 24 tâches ouvertes, 127 notifications. Deux messages identiques peuvent coexister | Modérée | Pas de dédoublonnage global : il casserait une relance légitime | Catalogue et projecteur | `MesTachesTest`, `NotificationsCiblesTest` verts | Opérationnel avec doublons possibles |
| GED et PDF | Modèles, QR, empreinte hors du fichier | Les actes officiels sont archivés et vérifiables. Il n’y a pas de GED transverse. Une seule ligne dans `generated_documents` au moment de la lecture | Majeure comme limite de périmètre | Non créée : aucun référentiel documentaire central n’est défini dans le dépôt | `DocumentsOfficielsTest` | Vert dans la suite | Partiel |
| Clôture | Verrouillage, reports | Écran et API présents. Non exécutés sur 2026 | Critique si lancée par erreur sur la base vivante | Aucune exécution | `CloturePage` | `CycleBudgetaireTest` en base isolée | Non recetté sur les données officielles |
| Projets, décisionnel, scénarios | Absents des documents exploitables du dépôt | Pas d’écran, pas d’API, pas de table | — | Non inventés | — | — | Hors périmètre documenté |
| Sécurité | Session, CSRF Sanctum, autorisation serveur | L’usurpation d’acteur est limitée au mode démonstration hors production. MFA et SSO existent mais ne sont pas imposés à tous les comptes. Pas d’audit CVE dans cette passe | Majeure avant une mise en production | Aucun secret modifié ni affiché | `ResolveActor.php`, `config/gesbudep.php` | `AuthenticationTest` vert | Démonstration locale seulement |

## Corrections de cette passe

1. Les cinq exports de la chaîne de dépense téléchargent le fichier avec la session et l’en-tête d’acteur, sans navigation hors de l’écran.
2. Le document de conformité ne présente plus la preuve de paiement ni la numérotation verrouillée comme des manques.

Aucune migration. Aucune donnée de `budget_ceeac_v8` modifiée par cet audit.

## Anomalies restantes

- Habilitations par périmètre et intérims non appliqués aux workflows EB, ENG, LIQ, ORD et PAY.
- Engagements partiels, avenants, et avoirs qui ne réécrivent pas un ordonnancement ou un paiement déjà émis.
- GED non transverse.
- Deux chemins d’adoption du budget.
- Montant négatif importé sur `DON-AUTRES`.
- Notifications dupliquées possibles.
- Pas de dépôt Git, donc pas de retour arrière par commit.
- Le mode démonstration est actif en local, ce qui est voulu pour les jeux d’essai et interdit en production par la configuration.

## Pour terminer la recette

1. Rejouer sur une copie de base, pas sur `budget_ceeac_v8`, un EB PAP et un EB Hors PAP jusqu’au rapprochement du paiement.
2. Rejouer une campagne 2027 jusqu’à la transmission, sans utiliser le report des lignes sur l’exercice officiel.
3. Décoder le QR d’un PDF nouvellement archivé et comparer l’empreinte stockée au fichier.
4. Comparer les écrans aux maquettes Figma lorsqu’elles seront dans le dépôt ou accessibles.
5. Couper `GESBUDEP_DEMO_IMPERSONATION` et imposer le MFA sur les comptes réels avant toute ouverture hors développement.
6. Versionner le dépôt pour pouvoir annuler une correction.
