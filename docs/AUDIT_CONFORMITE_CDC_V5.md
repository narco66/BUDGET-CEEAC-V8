# Audit de conformité au CDC v5.0 — BUDGET-CEEAC / GESBUDEP

Date : 1er octobre 2026. Référence : *Cahier des charges fonctionnel détaillé v5.0 du 26/09/2026*. Périmètre audité : `backend/` (Laravel 13, PHP 8.3) et `frontend/` (React 19, Vite 8), base `budget_ceeac_v8` (PostgreSQL).

Ce document complète `AUDIT_ARCHITECTURE_EXISTANTE.md`, qui traitait de la structure du code. Il porte ici sur la conformité fonctionnelle et sur la sûreté des opérations financières.

## 1. Synthèse

La chaîne de dépense EB → ENG → LIQ → ORD → PAI est implémentée de bout en bout. Les circuits de validation, les seuils d’ordonnancement et les contrôles de cumul sont présents, et le tout est couvert par des tests Feature. L’audit a relevé trois défauts **bloquants pour une mise en production**. Ils sont corrigés et testés dans cette livraison :

1. **Aucune authentification réelle.** L’en-tête HTTP `X-Actor-Id` suffisait pour agir sous l’identité de n’importe quel utilisateur, y compris l’Agent Comptable ou le Président. Sans en-tête, l’API servait un utilisateur par défaut. Cela contrevenait aux §25, §32 (« même en appel direct API ») et §55.
2. **Aucune protection contre la concurrence ni le double clic** sur les transitions financières : visa, signature, exécution de paiement. Deux exécutions simultanées d’un même paiement pouvaient décaisser deux fois le net ordonnancé tout en n’en comptabilisant qu’une (§12.3, §13.3, §62, PAI-002).
3. **Rejet bancaire inopérant après exécution.** Le reste à payer n’était pas rétabli et aucune réémission n’était possible (PAI-004).

Hors de la chaîne de dépense, plusieurs modules du périmètre maître (§2) ont été livrés depuis cet audit du 1er octobre 2026 : planification GAR/RBM, préparation budgétaire, marchés et GANTT. Restent notamment la GED transverse, le décisionnel et les projets. Le tableau du §4 a été mis à jour en conséquence.

## 2. Corrections livrées

| # | Constat | Gravité | Correction | Preuve |
|---|---|---|---|---|
| C1 | Usurpation d’identité par `X-Actor-Id` ; utilisateur par défaut sans en-tête | Critique | Authentification Sanctum : session cookie pour le SPA, jeton révocable pour les clients API. Toutes les routes sous `auth:sanctum`. `X-Actor-Id` n’est honoré qu’en mode démonstration (`GESBUDEP_DEMO_IMPERSONATION`), après authentification, et jamais en production. | `AuthenticationTest` (8 tests) |
| C2 | Pas de limitation des tentatives de connexion ni de trace | Élevée | Limitation par couple email + IP, verrouillage temporaire, audit `auth.connexion`, `auth.echec`, `auth.compte_inactif`, `auth.deconnexion` | `AuthenticationTest` |
| C3 | Révocation de session sans effet réel (reposait sur un en-tête fourni par le client) | Élevée | Chaque connexion crée une `user_session` ; une session ou un jeton révoqué est refusé à la requête suivante | `AdministrationTest`, `AuthenticationTest` |
| C4 | Transitions non verrouillées : double visa, double signature, double génération d’acte aval | Critique | `TransitionLock` : transaction plus `SELECT … FOR UPDATE` et relecture de l’état sous verrou, appliqué à toutes les transitions EB, ENG, LIQ, ORD et PAI | `IntegriteChaineTest` |
| C5 | Soumissions EB concurrentes sur une même ligne : surréservation possible | Élevée | Verrou des lignes budgétaires imputées pendant le contrôle de disponibilité | Revue de code |
| C6 | Cumul des liquidations contrôlé sans verrou de l’engagement | Élevée | Toute opération consommant le reliquat d’un engagement verrouille d’abord l’engagement ; le cumul est recontrôlé au visa | `IntegriteChaineTest` |
| C7 | Exécution de paiement sans transaction : payé écrasé, pas de registre des décaissements | Critique | Table `paiement_executions` (rang, montant, référence, statut). Le payé est recalculé depuis ce registre et non incrémenté. | `IntegriteChaineTest` |
| C8 | Même référence bancaire réutilisable sur deux paiements | Élevée | Refus, sauf pour les paiements d’un même lot bancaire | `IntegriteChaineTest` |
| C9 | Rejet bancaire impossible après exécution ; aucune réémission | Élevée | Neutralisation de l’exécution rejetée (jamais effacée), reste à payer rétabli, réémission obligeant à repasser contrôle et autorisation | `IntegriteChaineTest` |
| C10 | Suspension de paiement sans issue | Moyenne | Levée de suspension par l’Agent Comptable, avec retour à l’étape d’origine | `IntegriteChaineTest` |
| C11 | Une EB déjà transformée en engagement pouvait être annulée par l’ordonnateur (§8.13) | Élevée | Annulation refusée : passer par l’annulation ou le dégagement de l’engagement | `IntegriteChaineTest` |
| C12 | Écritures financières possibles sur un exercice clos (§23, §63) | Élevée | `ExerciceGuard` sur le visa ENG, l’ouverture et le visa LIQ, et la signature ORD | `IntegriteChaineTest` |
| C13 | Délégation du SG expirée entre la génération et la signature de l’ordre | Élevée | La compétence est réévaluée à la date de signature ; l’ordre est réorienté vers l’ordonnateur compétent | `IntegriteChaineTest` |
| C14 | Ordre représenté après correction de la liquidation avec l’**ancien** montant | Élevée | `present()` reprend le nouveau net visé et recalcule l’ordonnateur | Revue de code |
| C15 | Rejet LIQ ou ORD : montants de la liquidation remis à zéro (suppression silencieuse, §Principe 5) | Moyenne | Montants conservés ; la neutralisation se fait par le statut (tous les agrégats filtrent déjà sur le statut) | Suite existante |
| C16 | Rectification « complémentaire » sans plafond | Moyenne | Cumul liquidé plus compléments ≤ engagement | Revue de code |
| C17 | `SANCTUM_STATEFUL_DOMAINS` sans `127.0.0.1:5173` (l’URL indiquée par le README) | Faible | Ajouté | Parcours réel via le proxy Vite |

Résultat : **59 tests, 0 échec** (41 avant l’audit). Build frontend TypeScript OK. Parcours réel vérifié sur PostgreSQL via le proxy Vite : anonyme 401, en-tête seul 401, mauvais mot de passe refusé, connexion, accès, déconnexion, puis 401.

### Seconde livraison (P0 restants)

| # | Constat | Correction | Preuve |
|---|---|---|---|
| C18 | Journaux modifiables ; suppression en cascade de la chaîne | Trait `AppendOnly` sur `audit_events`, `*_events`, dégagements et actes ; triggers PostgreSQL refusant UPDATE/DELETE ; clés étrangères de la chaîne en `restrictOnDelete` ; exécutions de paiement non supprimables | Trigger vérifié sur la base locale ; `DocumentsOfficielsTest` |
| C19 | Code de signature non vérifié (§40) | Signature ORD et autorisation PAI par ressaisie du mot de passe du signataire (`SignatureVerifier`) ; tentatives limitées ; échecs audités `signature.echec` | `IntegriteChaineTest` |
| C20 | Soldes par ligne incomplets (PREP-006, §27) | `BudgetBalanceService` : initial, révisé, gelé, réservé, engagé net, liquidé, ordonnancé, payé, disponible, quatre restes, taux ; requêtes groupées ; exposé sur `/lignes-budgetaires` | `SoldesEtDegagementTest` (disponible identique au contrôle de crédit) |
| C21 | Ni dégagement ni annulation d’engagement (§9.12, §9.13) | Dégagement par le Directeur du Budget, plafonné à la part non liquidée, sans modifier l’acte visé ; annulation avant visa, ou après visa si aucune liquidation visée ou en contrôle ; engagé net utilisé par les plafonds de liquidation | `SoldesEtDegagementTest` |
| C22 | PDF régénérés à chaque consultation (§8.9, §26) | Actes archivés à l’événement sur disque privé, versionnés, SHA-256, snapshot, code de vérification en pied de page ; fichier servi tel qu’archivé avec contrôle d’intégrité (409 si altéré) ; `GET /documents` (versions) et `GET /documents/verifier/{code}` | `DocumentsOfficielsTest` |
| C23 | Coordonnées bancaires saisies librement (§12.11, §39) | Référentiel tiers (M12) : fiche unique (doublons par nom normalisé et NIF), comptes validés par un second acteur, statut actif/suspendu/bloqué ; paiement par virement ou chèque uniquement vers un compte validé du tiers attendu, coordonnées figées ; l’auteur ou le valideur du compte ne peut pas autoriser le paiement ; période de vigilance de 30 jours | `TiersTest` |

Résultat de la seconde livraison : **77 tests, 0 échec**.

## 3. Écarts restants sur la chaîne de dépense

| Réf. CDC | Écart | Gravité | Recommandation |
|---|---|---|---|
| PAI-005 | La preuve de paiement est exigée à l’exécution (`PaiementWorkflow::executer` refuse une exécution sans avis ou acquit) | Corrigé | Conservé ; ne pas retirer le contrôle |
| §9.8 | Un seul engagement par EB (unicité `expression_besoin_id`) ; pas d’avenant | Moyenne | Engagements partiels et avenants |
| §11.5 | Un seul ordonnancement par liquidation (pas d’ORD partiel) | Faible | À ouvrir si la procédure l’autorise |
| §10.3, LIQ-007 | Les avoirs et compléments ne se répercutent pas sur l’ORD et le PAI déjà émis | Moyenne | Définir la règle (ORD rectificatif) avec l’Agence Comptable |
| §39 | Le rattachement d’un engagement historique à son tiers se fait par nom normalisé ; un engagement en cours ne peut pas encore choisir son tiers à l’instruction | Moyenne | Sélection du tiers dans la fiche engagement (instruction Budget) |
| §62 | Les séquences d’actes passent par `number_sequences` verrouillée (`NumberingService`, `lockForUpdate`) | Corrigé | Conservé |
| §53 | Rôle unique par utilisateur (`users.role`) : pas de périmètre organisationnel, pas d’intérim appliqué aux workflows EB/ENG/LIQ/PAI | Moyenne | Brancher `user_roles`, `access_scopes` et `substitutions` (déjà en base) dans les contrôles d’acteur |
| §29 | Une page renvoie un bundle JS de 506 Ko | Faible | Chargement différé des pages par module |

## 4. Couverture des 21 domaines (§2)

| Code | Domaine | État |
|---|---|---|
| M01 | Administration, sécurité, paramétrage | **Partiel** : utilisateurs, rôles, SoD à l’attribution, workflows versionnés, seuils, sessions, audit ; authentification ajoutée. Absents : MFA, revue périodique des accès. |
| M02 | Référentiel organisationnel | **Partiel** : unités hiérarchiques, sans versionnement par date d’effet (§34.2). |
| M03 | Planification GAR/RBM | **Présent** : arborescence pilier → tâche, versions publiées, rattachement PAP. |
| M04 | Préparation budgétaire | **Présent** : campagnes, cadrage, plafonds, dossiers, arbitrages, versions et transmission. Le report de lignes reste un second chemin d’adoption, distinct de la campagne. |
| M05 | Budget unique et PAP | **Partiel** : lignes, nature PAP/Hors PAP, mouvements (ouverture, transfert, gel, report), soldes complets par étape (C20). |
| M06–M10 | EB, ENG, LIQ, ORD, PAI | **Implémentés** et durcis par cette livraison ; écarts en §3. |
| M11 | Marchés, contrats | **Présent** : marchés notifiés et contrats rattachés à la chaîne de dépense. |
| M12 | Tiers, fournisseurs | **Partiel** : fiche unique, comptes validés, statuts, paiement sécurisé (C23). Absents : pièces de conformité avec expiration, historique d’incidents, fusion de doublons. |
| M13 | Suivi-évaluation | **Partiel avancé** : indicateurs, cibles, réalisations et mesures validées par un second acteur, écarts, risques, mesures correctives, recommandations suivies, rapports publiés et archivés, courbes. Voir `RAPPORT_IMPLEMENTATION_SUIVI_EVALUATION.md` §16 pour les écarts restants. |
| M14 | Contrôle interne | **Partiel** : contrôles embarqués dans les workflows ; pas de moteur de règles ni de registre d’anomalies. |
| M15 | GED | **Partiel** : pièces EB en stockage privé ; actes officiels archivés et vérifiables (C22). Absents : GED transverse, recherche, conservation légale. |
| M16 | Tâches, notifications | **Implémenté** : Mes tâches, compteur, notifications en base. |
| M17 | Reporting | **Partiel** : tableaux par module, exports Excel. |
| M18 | Import, interopérabilité | **Partiel** : outbox d’intégration idempotente ; pas d’import avec staging. |
| M19 | GANTT | **Présent** : Gantt d’exécution du suivi-évaluation. |
| M20 | Tableau de bord exécutif | **Partiel** : tableau de chaîne. |
| M21 | Clôture, rapprochement, archivage | **Partiel** : statut d’exercice, réouverture auditée, blocage des écritures (C12), rapprochement unitaire de paiement. |

## 5. Feuille de route proposée (alignée sur §73)

**P0 :** les six points identifiés au premier audit sont livrés (C18 à C23). La preuve de paiement, le choix du tiers à l’instruction et les séquences verrouillées sont en place. Restent les engagements partiels, les avoirs sur un ordre déjà émis et les habilitations par périmètre.

**P1 :** suivi-évaluation étendu (M13), tableau exécutif (M20), clôture complète (M21), habilitations par périmètre et intérims (§53). Planification, préparation et marchés sont livrés.

**P2 :** import avec staging, moteur de contrôle continu, reporting planifié. Le GANTT d’exécution est livré.

## 6. Notes d’exploitation

- **Connexion :** `http://127.0.0.1:5173/connexion`. Les comptes de démonstration créés par les seeders ont le mot de passe `password`. Il faut le changer hors développement.
- **Mode démonstration :** `GESBUDEP_DEMO_IMPERSONATION=true` dans `backend/.env` affiche le sélecteur d’acteur après connexion. La valeur est `false` dans `.env.example` et le mode est ignoré lorsque `APP_ENV=production`.
- **Clients API :** `POST /api/v1/auth/login` sans cookie de session renvoie un jeton Bearer, révoqué à la déconnexion ou par l’administration.
- **Migration :** `2026_10_01_000608_create_paiement_executions_table` reprend les paiements déjà exécutés sous forme d’une exécution de rang 1.
- **Signature :** la signature d’un ordre et l’autorisation d’un paiement demandent le mot de passe du signataire.
- **Tiers :** `php artisan db:seed --class=TiersSeeder` crée une fiche et un compte validé par bénéficiaire présent dans la chaîne, et rattache les engagements. Sans compte validé, un virement ne peut pas être préparé.
- **Actes officiels :** stockés dans `storage/app/private/actes/` ; ce dossier doit être inclus dans les sauvegardes.
