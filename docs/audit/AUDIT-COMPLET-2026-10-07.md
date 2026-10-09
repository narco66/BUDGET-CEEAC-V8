# Audit complet et corrections — BUDGET-CEEAC / GESBUDEP

Date : 7 octobre 2026. Périmètre : `backend/` (Laravel 13.34, PHP 8.3), `frontend/` (React 19, Vite 8, Tailwind 4, Font Awesome 7), base `budget_ceeac_v8` (PostgreSQL 18.4).

Méthode : inventaire, confrontation aux sources de `/docs`, reproduction de chaque anomalie, correction, test, puis second audit indépendant. Chaque fonction a été vérifiée de bout en bout : interface, API, validation, autorisation, service, base, workflow, notification, journal et résultat affiché.

---

## 1. Rapport d’audit initial

### 1.1 Sources de vérité disponibles

| Source attendue | Disponible | Utilisation |
|---|---|---|
| Cahier des charges v5.0 | **Absent du dépôt** | Repris par ses citations dans `AUDIT_CONFORMITE_CDC_V5.md` |
| Budget CEEAC 2026 | `backend/database/data/budget-ceeac-2026.json` | Importé : 363 lignes officielles, 40 309 295 803 XAF votés |
| Référentiel organisationnel 2026 | `backend/database/data/organigramme-ceeac-2026.php` | Publié (`organisation:referentiel`) |
| Règles de workflow | `AUDIT-IMPLEMENTATION-WORKFLOWS-BUDGET-CEEAC.md` | Comparées au cahier d’audit (§ 3) |
| Maquettes EB, ENG, LIQ, ORD, PAY, SE | `docs/maquette-*` | Charte appliquée lors des audits UI précédents |
| Maquettes FIGMA-V6 et Maquette-Budget-Ceeac-New | **Absentes du dépôt** | Non vérifiables |

### 1.2 Architecture constatée

| Exigence | Constat |
|---|---|
| React, Vite, Tailwind, Font Awesome 7 | Conforme (React 19.3, Vite 8, Tailwind 4, FA 7.3) |
| Laravel 13, API REST | Conforme : 427 routes `api/v1`, toutes sous `auth:sanctum` sauf connexion, SSO et vérification publique |
| PostgreSQL 18 | Conforme (18.4) |
| Montants | Conforme : 44 colonnes de montant en entier (`unsignedBigInteger`), XAF sans décimale, aucun flottant |
| Services, policies, transactions, verrous | Présents : `TransitionLock` (transaction + `SELECT … FOR UPDATE`) sur toutes les transitions de la chaîne |
| Journaux | Append-only (trait + triggers PostgreSQL), mais journal central incomplet (A13) |
| Mocks, données factices, `dd()`, `console.log`, TODO | **Aucun** dans le code exécuté |
| Statuts | Codes français par entité, enums sur la chaîne ; aucune variante concurrente dans une même colonne |
| Tests | 233 tests backend au départ ; **aucun test frontend** |

### 1.3 Incidents d’exploitation constatés le 7 octobre

- Le serveur Laravel (port 8001) était arrêté ; Vite n’écoutait qu’en IPv6. L’application s’ouvrait sans compte connecté et sans message.
- La base de démonstration avait été vidée à 10 h 56 (`migrate:fresh` + seed par défaut), avant l’intervention. Le référentiel officiel puis le jeu d’essai ont été rechargés avec l’accord du responsable.
- L’environnement définit `NODE_ENV=production` : `npm install` y omet les dépendances de développement (TypeScript, Vite). Installer avec `npm ci --include=dev`.

---

## 2. Matrice des anomalies (avant / après)

Criticité : BLOQUANT, CRITIQUE, MAJEUR, MOYEN, MINEUR. Statut final : Corrigé et testé, sauf mention contraire.

| ID | Module | Fonction | Constat | Criticité | Cause | Correction | Fichier(s) | Test | Statut |
|---|---|---|---|---|---|---|---|---|---|
| A01 | Socle | Démarrage | Compte connecté absent, aucun message quand l’API ne répond pas | BLOQUANT | Erreur de `/acteurs` ignorée par le shell | Bandeau « Connexion au serveur impossible » avec « Réessayer » ; serveurs relancés | `AppShell.tsx` | Panne simulée dans le navigateur, puis reprise | Testé |
| A02 | Exploitation | Adresse du frontend | Vite seulement sur `[::1]` alors que Laravel renvoie vers `127.0.0.1:5173` | MAJEUR | Hôte Vite non fixé | `host: 127.0.0.1`, `strictPort` | `vite.config.ts` | `127.0.0.1` et `localhost` répondent | Testé |
| A03 | API | Requête sans session | Erreur 500 `Route [login] not defined` sans en-tête JSON | MOYEN | Redirection vers une route nommée absente | `redirectGuestsTo` : 401 JSON pour l’API | `bootstrap/app.php` | curl : 401 | Testé |
| A04 | Tests | Mes tâches | 97 tests en erreur en cascade | MAJEUR | Test dépendant de la date du jour | Échéance calculée distincte de celle du jeu de démonstration | `MesTachesTest.php` | Suite complète | Testé |
| A05 | Jeu d’essai | EB hors PAP | 9 dossiers de démonstration sur 11 refusés | MAJEUR | Initiateur choisi sur la structure de la ligne, alors que le hors PAP part du SMG | Initiateur du Service des Moyens généraux pour le hors PAP | `JeuEssai.php`, `JeuEssaiTest.php` | `JeuEssaiTest` | Testé |
| A06 | Sécurité | Périmètre (IDOR) | Fiche EB ouvrable hors périmètre par l’URL ; listes ENG, LIQ, ORD, PAI sans filtre de périmètre | CRITIQUE | `NeedPolicy::view` ignorait `access_scopes` ; listes non filtrées | Même périmètre pour la fiche et la liste ; initiateur et acteur attendu gardent l’accès | `NeedPolicy.php`, `User.php`, 4 contrôleurs | `PerimetreOrganisationnelTest` (3) | Testé |
| A07 | Sécurité | En-têtes HTTP | Aucun en-tête de sécurité | MAJEUR | Absent | `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP sur le JSON, HSTS en HTTPS | `SecurityHeaders.php` | curl | Testé |
| A08 | Sécurité | Téléversements | Pièces EB, ENG, recettes et S&E de tout type ; GED sans taille maximale à la requête | MAJEUR | Règles `file` sans liste blanche | Extensions et MIME de la GED imposés partout | 5 contrôleurs | Suite complète | Testé |
| A09 | Sécurité | Limitation | Aucune limite générale de l’API (seulement la connexion) | MOYEN | Absent | 600 requêtes/min par utilisateur (paramétrable) | `AppServiceProvider.php`, `config/gesbudep.php` | En-têtes `X-RateLimit` | Testé |
| A10 | API | Erreurs | Messages anglais et noms de classes exposés (`No query results for model [App\…]`) | MOYEN | Rendu par défaut | Format unique `{message, code}` en français pour 400 à 500 ; message métier conservé | `bootstrap/app.php` | curl 401, 404, 405 | Testé |
| A11 | Finances | Agrégats | Payé de l’écran ORD = somme des montants des paiements ; engagé PAI sans dégagements | MAJEUR | Formules recopiées par écran | Source unique `BudgetBalanceService::execution()` | `BudgetBalanceService.php`, 3 contrôleurs | `CoherenceFinanciereTest` | Testé |
| A12 | Contrôle interne | Cohérence | Aucun contrôle automatique ENG > disponible, LIQ > ENG, ORD > LIQ, payé > ordonnancé, montants négatifs | MAJEUR | Non implémenté | Contrôles ajoutés au registre des anomalies (constat, jamais de correction silencieuse) | `ContinuityRegisterService.php` | `CoherenceFinanciereTest` | Testé |
| A13 | Traçabilité | Journal central | Soumissions, validations, approbations EB et signatures ORD absentes du journal central | CRITIQUE | Journaux de module non recopiés (reprise historique ponctuelle seulement) | Recopie de chaque événement EB, ENG, LIQ, ORD, PAI dans la même transaction | `MirroredInAuditJournal.php`, `AuditService.php`, 5 modèles | `ScenarioBoutEnBoutTest` | Testé |
| A14 | Performance | Listes | 168, 118 et 83 requêtes pour une page de 8 EB, ENG ou LIQ | MOYEN | N+1 sur les soldes de ligne et les permissions | Mouvements de crédit groupés par ligne ; niveaux de permission mémorisés par requête | `BudgetLine.php`, `User.php` | Mesure : 102, 64 et 57 requêtes ; suite complète | Amélioré |
| A15 | Recettes | Régularisation | Encaissement non identifié impossible à affecter à un titre depuis l’écran | MAJEUR | API sans écran | Action « Affecter » (titre, montant, excédent en avance) | `EncaissementsPage.tsx` | Vérifié dans le navigateur | Testé |
| A16 | Préparation | Ventilation | Détails, périodes et financements ni visibles ni retirables ; une erreur bloquait la soumission | MAJEUR | API sans écran | Tableau de ventilation avec « Retirer » | `DossierFichePage.tsx` | Typage et build | Corrigé |
| A17 | Préparation | Campagne et pièces | Pas d’archivage ni d’export PDF de campagne ; pas de retour de version ; pièce non retirable | MOYEN | API sans écran | Actions ajoutées | `CampagneFichePage.tsx`, `DossierFichePage.tsx` | Typage et build | Corrigé |
| A18 | Administration | Paramétrage | Paramètres, exercices, numérotation et workflows en lecture seule ; sessions non révocables ; gel d’audit non levable | MOYEN | API sans écran | Modification motivée, réouverture d’exercice, ajustement de séquence (sans recul), version, édition et publication de workflow, onglet Sessions, levée de gel | `AdminSettingsPage.tsx`, `AuditJournalPage.tsx`, `AuditService.php` | Vérifié dans le navigateur | Testé |
| A19 | Administration | Sécurité | Texte affirmant que l’authentification reposait sur l’en-tête d’acteur | MINEUR | Texte obsolète | Texte exact (Sanctum, MFA) | `AdminSettingsPage.tsx` | Relecture | Corrigé |
| A20 | Qualité | Tests frontend | Aucun test React | MOYEN | Absent | Vitest et 10 tests des formats et des erreurs | `format.test.ts`, `package.json` | `npm test` | Testé |
| A21 | API | Codes de refus | Un refus métier renvoie 403 ou 422 selon le service | MINEUR | Héritage | Non modifié : le message est clair dans les deux cas | — | `ScenarioBoutEnBoutTest` accepte les deux | Documenté |
| A22 | Sessions | Liste | Les anciennes sessions restent « actives » sans échéance affichée | MINEUR | Pas d’expiration côté registre | Non modifié | — | — | Documenté |
| A23 | Documents | Actes officiels | Les actes ne sont générés que par les contrôleurs HTTP : une commande ou un job n’en produit pas | MOYEN | Architecture | Non modifié ; les actes se génèrent aussi à la première consultation | — | `ScenarioBoutEnBoutTest` (par l’API) | Documenté |
| A24 | Modèles S&E | Affectation massive | `$guarded = []` sur 10 modèles de suivi-évaluation | MINEUR | Héritage | Non modifié : aucune affectation directe depuis la requête | — | — | Documenté |

---

## 3. Matrice de conformité

| Exigence | Source | Implémentation actuelle | Écart | Action | Résultat |
|---|---|---|---|---|---|
| EB PAP : Expert/Chef de service → Directeur → Commissaire ou SG | Cahier d’audit §8 | Initiateur → Directeur → Commissaire (structure technique) ou SG → **Ordonnateur** | Approbation finale de l’ordonnateur en plus | Décision du 7 octobre : **conserver l’ordonnateur** (note de workflow d’octobre) | Conforme à la décision |
| EB hors PAP : SMG → DRHMG → SG → Ordonnateur → Engagement | Cahier d’audit §8 | Création réservée au SMG ; directeur résolu par rattachement (DRHMG) ; SG ; ordonnateur | Aucun | — | Conforme |
| ENG : Directeur Budget → Contrôleur financier | Cahier d’audit §9 | Expert → Chef Budget → Directeur Budget → Contrôleur financier | Étapes d’instruction préalables | Aucune (aval conforme) | Conforme |
| LIQ : service fait → CF → ORD, sans dépasser l’engagement | Cahier d’audit §10 | Certification par l’initiateur, visa CF ; brut = min(facture TTC, service fait) ; service fait ≤ reliquat | Aucun | Testé (facture double : reste plafonnée) | Conforme |
| ORD : ≤ 5 000 000 SG, > 5 000 000 Président, sans choix manuel | Cahier d’audit §11 | Seuil de la délégation courante (5 000 000 XAF) ; rôle calculé par le serveur | Seuil porté par la délégation, non codé en dur | Aucune | Conforme |
| PAI : Comptable/Chef comptable → Agent comptable | Cahier d’audit §12 | Comptable → Chef comptable → Agent comptable | Aucun | — | Conforme |
| Paiements partiels, cumul, reste, interdiction de dépasser | Cahier d’audit §12 | Registre des exécutions ; statut `paye_partiel` ; dépassement refusé | Aucun | Test 3/10, 2/10, 5/10 | Conforme |
| Disponible = autorisé − engagements | Cahier d’audit §6.4 | `BudgetBalanceService` : révisé − gelé − engagé − réservé | Écrans ORD et PAI recalculaient autrement | A11 | Corrigé |
| Transitions sans doublon | Cahier d’audit §13, §31 | `TransitionLock`, unicités, tests de double validation et de double exécution | Aucun | — | Conforme |
| Aucun objet accessible en changeant l’ID | Cahier d’audit §29 | Policies par périmètre | EB et listes de la chaîne | A06 | Corrigé |
| Journalisation de tout événement sensible | Cahier d’audit §21 | Journal central append-only | Transitions EB et ORD absentes | A13 | Corrigé |
| Actes générés dans la GED | Cahier d’audit §20 | Actes archivés, versionnés, SHA-256, versés à la GED | Aucun | Vérifié de bout en bout | Conforme |
| Aucun mock | Cahier d’audit §25 | Aucun trouvé | Aucun | — | Conforme |
| Statuts normalisés | Cahier d’audit §37 | Enums et codes français, sans doublon sémantique | Aucun | — | Conforme |
| Montants en types exacts | Cahier d’audit §39 | Entiers XAF | Aucun | — | Conforme |
| Erreurs API uniformes | Cahier d’audit §33 | Format `{message, code}` | Messages techniques exposés | A10 | Corrigé |
| Tests React | Cahier d’audit §34 | Vitest | Aucun test | A20 | Corrigé (socle) |

---

## 4. Matrice de couverture fonctionnelle

Légende : ✔ présent et vérifié · ◐ partiel · ✘ absent.

| Module | Fonction attendue | Backend | Frontend | DB | Workflow | Permissions | Tests | Statut |
|---|---|---|---|---|---|---|---|---|
| Chaîne EB → PAI | Circuit complet, PAP et hors PAP | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ bout en bout | Conforme |
| Paiement | Partiel, rejet bancaire, lots, rapprochement | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| Budget | Soldes par ligne, mouvements, gel, dégagement | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| Préparation | Campagnes, dossiers, ventilation, versions | ✔ | ◐ | ✔ | ✔ | ✔ | ✔ | Hypothèses et orientations sans édition à l’écran ; comparaison de versions sans écran |
| Planification GAR | Pilier → Tâche, versions publiées | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| Recettes | Prévisions, titres, encaissements, affectation, rapprochement | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme après A15 |
| Suivi-évaluation | Indicateurs, mesures, réalisations, écarts, rapports | ✔ | ◐ | ✔ | ✔ | ✔ | ✔ | Cibles pluriannuelles et agrégation d’indicateurs sans écran |
| Mes tâches, notifications | Projection, échéances, liens, lu/non lu | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| GED | Dépôt contrôlé, versions, quarantaine, actes versés | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| Marchés, Tiers | CRUD, statuts, comptes validés par un second acteur | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Conforme |
| Administration | Utilisateurs, habilitations, paramétrage, sessions, workflows | ✔ | ◐ | ✔ | ✔ | ✔ | ✔ | Rectification d’événement d’audit et suppression de nomenclature sans écran |
| Référentiel organisationnel | Unités, fonctions, affectations, versions | ✔ | ◐ | ✔ | — | ✔ | ✔ | Édition des fonctions et clôture d’affectation sans écran |
| Clôture | Demande, confirmation, archivage, périodes | ✔ | ◐ | ✔ | ✔ | ✔ | ✔ | Réouverture d’une période sans écran |
| Contrôle interne | Registre, cohérence automatique | ✔ | ✔ | ✔ | — | ✔ | ✔ | Conforme après A12 |
| Reporting | États, exports, tableaux de bord | ✔ | ✔ | ✔ | — | ✔ | ✔ | Conforme |

Croisement API et frontend (second audit) : 385 appels du frontend, **0 appel vers une route inexistante**. Sur 427 routes, celles sans écran relevées ci-dessus sont les seules fonctions non accessibles à l’utilisateur.

---

## 5. Rapport final

### 5.1 Tests

| Suite | Avant | Après |
|---|---|---|
| Backend (PHPUnit) | 233 tests, dont 1 échec permanent et 97 erreurs le 7 octobre | **240 tests, 0 échec, 2 242 assertions** |
| Frontend (Vitest) | Aucun | **10 tests, 0 échec** |
| Typage TypeScript et build Vite | OK | OK |
| Tournée visuelle des 89 routes | — | 0 erreur JavaScript, 0 erreur 5xx, 0 débordement |

Tests ajoutés :

- `ScenarioBoutEnBoutTest` : scénario réel par l’API, 68 assertions.
  - EB PAP créée, pièces jointes, soumission.
  - Circuit directeur, commissaire, ordonnateur.
  - Engagement : instruction et visa du contrôleur financier ; double visa refusé.
  - Liquidation : facture surévaluée plafonnée, service fait au-delà de l’engagement refusé.
  - Ordonnancement : le Président est refusé sous le seuil ; signature par le SG.
  - Paiement : refusé au comptable, autorisé par l’agent comptable, exécuté en trois tranches, rapproché.
  - Vérifications finales : soldes, tâches closes, notifications, actes, GED et journal central.
- `PaiementTest::…trois_tranches…` : un ordre réglé en trois tranches (3/10, 2/10, 5/10), dépassement refusé à chaque tranche et après le solde.
- `PerimetreOrganisationnelTest` (3 tests) : périmètre appliqué à la fiche et à la liste.
- `CoherenceFinanciereTest` (2 tests) : source unique des agrégats ; incohérence relevée au registre.

### 5.2 Données

- Référentiel officiel réimporté :
  - `ceeac:importer-2026` : 363 lignes officielles, 40 309 295 803 XAF votés ;
  - `organisation:referentiel` : publication de l’organigramme officiel.
- Jeu d’essai rechargé (`demo:jeu-essai`) : 29 EB, 17 engagements, 5 paiements, 131 tâches.
- Aucune migration ajoutée.

### 5.3 Risques résiduels

1. **Décision métier documentée** : le circuit EB PAP conserve l’approbation de l’ordonnateur (décision du 7 octobre).
2. **Prévision de recette `DON-AUTRES`** importée à −53 706 858 829 FCFA. Le montant figure tel quel dans le fichier officiel du budget 2026. Il est signalé au registre et n’est pas réécrit.
3. Fonctions disponibles par l’API sans écran (§ 4) :
   - hypothèses et orientations : édition et archivage ;
   - versions de préparation : comparaison et archivage ;
   - pièces de préparation : remplacement ;
   - cibles pluriannuelles et agrégation des indicateurs ;
   - fonctions et affectations organisationnelles ;
   - réouverture d’une période ;
   - rectification d’un événement d’audit ;
   - suppression de nomenclature.
4. A21 à A24 (codes de refus, sessions, génération des actes, modèles S&E).
5. Formatage Pint non conforme (fins de ligne) dans des fichiers non modifiés par cet audit.
6. Le cahier des charges et deux jeux de maquettes ne sont pas dans le dépôt : la conformité a été établie sur leurs citations dans les audits précédents.

---

## 6. Actions restantes

| Action | Dépend de |
|---|---|
| Confirmer ou corriger la prévision `DON-AUTRES` négative | Décision institutionnelle (Direction du Budget) |
| Verser au dépôt le cahier des charges v5.0 et les maquettes FIGMA-V6 et Maquette-Budget-Ceeac-New | Documents non disponibles |
| Arbitrer les écrans à ouvrir pour les fonctions du § 5.3, point 3 | Priorités du projet |
| Retirer `NODE_ENV=production` des postes de développement, ou installer avec `npm ci --include=dev` | Configuration des postes |
| Sauvegarde PostgreSQL, rétention et restauration testée ; inclure `storage/app/private/actes/` | Infrastructure d’exploitation (voir `docs/audit/exploitation.md`) |
