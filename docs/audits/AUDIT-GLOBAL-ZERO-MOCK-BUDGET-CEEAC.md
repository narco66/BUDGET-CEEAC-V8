# Audit global — zéro mock, zéro donnée métier fictive

Date : 2 octobre 2026  
Périmètre : modules déjà présents dans le dépôt BUDGET-CEEAC / GESBUDEP  
Chaîne exigée : React → API Laravel → service métier → Eloquent → PostgreSQL

## Synthèse exécutive

| Indicateur | Valeur |
|---|---|
| Modules métier développés | 14 |
| Pages React | 32 composants de page, 36 routes applicatives |
| Déclarations de routes HTTP | 191 (API v1 et route web de santé) |
| Tables créées par les migrations | 88, y compris sessions, cache et files d’attente |
| Anomalies de simulation métier détectées | 6 |
| Anomalies corrigées | 6 |
| Simulations métier restantes dans les écrans développés | 0 |

Aucun écran développé n’affiche de KPI, de liste ou de graphique alimenté par un tableau JavaScript de démonstration. Les recherches `mock`, `mockData`, `dummy`, `sampleData`, `TODO`, `FIXME`, `HACK`, `STUB` dans `frontend/src` et `backend/app` ne remontent aucune simulation de fonctionnalité. `setTimeout` n’apparaît que comme anti-rebond de recherche (assistant d’expression de besoin, saisie d’indicateur). `placeholder=` désigne des libellés de champs, pas des données.

Les factories et Faker restent limités à `database/factories/UserFactory.php` et aux tests. Aucune page ne les importe.

## Inventaire des modules réellement présents

| Module | Pages | API | Tables principales |
|---|---|---|---|
| Authentification | Connexion | `/api/v1/auth/*` | `users`, `personal_access_tokens` |
| Administration | Accueil, utilisateurs, habilitations, paramétrage | `/api/v1/admin/*` | rôles, permissions, règles, workflows, `document_types`, `reference_values`, audit |
| Structures | utilisées par les fiches et l’admin | via admin et dossiers | `organization_units` |
| Budget | Lignes, rapprochements | `/api/v1/lignes-budgetaires`, rapprochements de paiement | `exercices`, `budget_lines`, mouvements |
| PAP | embarqué dans les lignes, l’EB et le suivi | enrichissement et fiche activité | `pap_enrichments`, tâches PAP |
| Expressions de besoin | Liste, assistant, fiche | `/api/v1/expressions-besoin` | `expression_besoins`, lignes, pièces, événements |
| Engagements | Liste, fiche | `/api/v1/engagements` | `engagements`, événements, dégagements |
| Liquidations | Liste, fiche | `/api/v1/liquidations` | `liquidations`, rectifications |
| Ordonnancements | Liste, délégations, fiche | `/api/v1/ordonnancements` | `ordonnancements`, suppléances |
| Paiements | Liste, lots, fiche | `/api/v1/paiements` | `paiements`, exécutions, lots |
| Chaîne de dépense | Tableau de chaîne | `/api/v1/chaine/tableau-de-bord` | agrégats des tables ci-dessus |
| Tiers | Liste et fiche | `/api/v1/tiers` | `tiers`, comptes |
| Documents | Vérification par code | `/api/v1/documents` | `generated_documents` |
| Mes tâches | Liste, fiche | `/api/v1/taches` | `workflow_tasks` |
| Suivi-évaluation | Tableau de bord, Gantt, saisie, écarts, actions, rapports, synthèse, référentiels, fiche 360 | `/api/v1/suivi/*` | mesures, réalisations, écarts, risques, recommandations, scores |
| Notifications | bandeau Mes tâches et canaux base | table `notifications` | `notifications` |

Modules du cahier des charges **absents du dépôt** (ni écran, ni API, ni table métier) : préparation budgétaire autonome, planification stratégique, marchés et contrats, recettes, clôture, contrôle interne, interopérabilité externe, entrepôt décisionnel. Leur absence n’est pas un écran factice : rien ne prétend les exécuter. L’onglet Marché d’un ordonnancement affiche le message persistant « Aucun marché ni contrat n’est rattaché », parce qu’aucune table de marché n’existe.

## Mocks et données statiques détectés

| Fichier | Nature | Impact | Correction |
|---|---|---|---|
| `frontend/src/features/settlements/pages/LiqFichePage.tsx` | Le maillon Paiement était toujours libellé « à venir », même lorsqu’un paiement existait | Critique : la chaîne affichée mentait | `LiquidationResource` expose `paiement` et `paiement_id` lus sur `ordonnancements.paiement` |
| `frontend/src/features/commitments/pages/EngFichePage.tsx` | Sept types de pièces obligatoires codés en dur | Majeure : le contrôle financier ne suivait pas le référentiel | `pieces_attendues` lu dans `document_types` (opération `engagement`) |
| `frontend/src/features/needs/pages/EbWizardPage.tsx` | Types de pièces de l’EB codés en dur | Majeure : référentiel administrable ignoré | `types_pieces` lu dans `document_types` (opération `expression_besoin`) |
| `frontend/src/features/settlements/pages/LiqFichePage.tsx` | Natures de prestation codées en dur | Majeure | `natures_prestation` lu dans `reference_values` (`nature_prestation`) |
| `frontend/src/features/payments/pages/OrdFichePage.tsx` | Liste figée des effets de signature, en double de l’API | Moyenne | La modale reprend `dossier.effets`, calculé sur l’ordonnancement réel |
| `backend/database/seeders/DatabaseSeeder.php` | Les dossiers de démonstration étaient appelés sans garde d’environnement | Majeure en production | `AdministrationSeeder` seul en production ; dossiers de démo réservés aux autres environnements |
| `AdministrationSeeder` | Séquence PAY initialisée à 3 | Mineure sur installation neuve | `last_value` ramené à 0 pour une installation neuve |

La migration `2026_10_02_154705_seed_piece_and_prestation_referentials` enregistre ces libellés dans PostgreSQL (`insertOrIgnore`). Ils sont ensuite lus par `ReferentialReader`.

## Occurrences laissées volontairement

- Attributs HTML `placeholder="Rechercher…"` : aide de saisie, pas une donnée.
- `setTimeout` de 250 ms : debounce avant l’appel API des lignes budgétaires et de la saisie d’indicateur.
- Libellés d’interface stables : vues de Mes tâches, onglets, étapes d’assistant, filtres de statut. Ce sont des constantes de navigation, pas des référentiels administrables.
- Urgence et priorité de l’EB (`faible`, `normale`, `urgente`) : énumérations de workflow déjà validées côté Laravel, pas des listes administrables.
- Seeders `ExpressionBesoinSeeder`, `EngagementSeeder`, `LiquidationSeeder`, `OrdonnancementSeeder`, `PaiementSeeder`, `TiersSeeder` : jeux de développement. Ils écrivent dans PostgreSQL et les écrans les relisent par l’API. Ils ne sont plus lancés lorsque `APP_ENV=production`.
- `UserFactory` / Faker : tests uniquement.
- Impersonation de démonstration : drapeau `gesbudep.demo_impersonation`, ignoré en production. Ce n’est pas une donnée métier figée dans un composant.
- Graphique d’évolution d’indicateur : séries `cible` et `réalisé` fournies par `/suivi/indicateurs/{id}/evolution`. L’écran vide le dit explicitement lorsqu’aucune valeur validée n’existe.

## Écrans factices

Aucun bouton Enregistrer, Valider, Viser, Signer, Exporter ou filtrer des modules développés n’a été trouvé sans endpoint. Les actions de workflow passent par `POST` Laravel, une policy, une transaction et un journal (`eb_events`, `eng_events`, `liq_events`, `ord_events`, `pay_events` ou `audit_events`). Mes tâches est projetée dans la même transaction et notifie l’acteur attendu.

## API simulées

Aucune. Les contrôleurs interrogent Eloquent. Les tableaux de bord (chaîne, administration, EB, engagement, liquidation, ordonnancement, paiement, suivi) comptent ou somment les tables.

## Migrations, modèles, relations

Les montants métier sont des entiers FCFA. Les relations de la chaîne EB → engagement → liquidation → ordonnancement → paiement sont des clés étrangères réelles. Le seul écart corrigé ici était l’oubli du paiement dans la ressource de liquidation, pas l’absence de colonne.

## Seeders

| Classe | Classe | Rôle |
|---|---|---|
| `AdministrationSeeder` | Production | Permissions, rôles, règles, workflows, paramètres, pièce « Facture » |
| Migration de référentiel du 2 octobre 2026 | Production | Types de pièces EB et engagement, natures de prestation |
| Seeders de dossiers et de tiers | Développement | Jeux locaux, exclus de la production |

## Workflows, autorisations, journal

Chaque transition sensible est autorisée par une policy Laravel, pas seulement par le masquage d’un bouton. La prise en charge d’une tâche d’un autre acteur, vue depuis le périmètre d’unité, répond 403. Les notifications de tâche, de suivi et de chaîne sont des enregistrements `notifications`, pas des textes injectés dans React.

## Corrections réalisées

1. Référentiel des pièces et des natures de prestation persisté et servi par l’API.
2. Fiches engagement, expression de besoin et liquidation branchées sur ce référentiel.
3. Maillon paiement de la fiche liquidation lu sur le dossier réel.
4. Effets de signature de l’ordonnancement lus sur la ressource API.
5. Séparation des seeders de production et de démonstration.
6. Séquence de paiement neuve initialisée à 0.

## Tests réalisés

- `tests/Feature/EngagementTest.php` : la fiche contient `TDR` et `Bon de commande` issus du référentiel. Suite complète : 5 tests, verte.
- `tests/Feature/LiquidationTest.php` : la fiche expose `paiement` et la nature `Travaux`. Suite complète : 5 tests, verte.
- `npx tsc --noEmit` : vert.
- Migration appliquée sur `budget_ceeac_v8`.

Le second passage de recherche, après correction, ne retrouve plus `PIECES`, `NATURES` ni `EFFETS` dans le frontend.

## Anomalies restantes

- Les modules non développés (marchés, préparation, clôture, etc.) ne peuvent pas être « démockés » : ils n’ont pas d’écran. L’onglet Marché reste un état vide honnête.
- Les jeux de démonstration existent toujours pour le développement local. Ils ne s’exécutent pas en production.
- L’authentification de production (MFA, annuaire) n’est pas l’objet de cet audit ; le drapeau de démonstration est déjà coupé hors local.

## Recommandations

- Ne pas lancer `db:seed` en production : seul le paramétrage institutionnel est prévu, et un second passage écraserait des `insertOrIgnore` sans remettre les compteurs réels.
- Lorsque le module marchés existera, remplacer le message fixe de l’onglet par la relation contrat, sans réintroduire de libellé codé.
- Continuer à traiter `document_types` et `reference_values` comme source des listes administrables, et les énumérations de statut comme des constantes de code.

## Rapport par module

### Authentification
Pages : connexion. Tables : `users`, jetons. API : login, logout, acteurs. Seeders : utilisateurs de démo hors production. Mocks : aucun. Résultat : conforme.

### Administration
Pages : accueil, utilisateurs, habilitations, paramétrage. KPI du tableau de bord calculés en base. Référentiels, workflows, seuils, numérotation, intégrations et journal lus par l’API. Mocks : séquence PAY à 3, corrigée pour les installations neuves. Résultat : conforme.

### Budget et PAP
Pages : lignes, rapprochements. Soldes calculés par `BudgetBalanceService`. Enrichissement PAP lu sur la ligne. Mocks : aucun. Résultat : conforme.

### Expressions de besoin
Pages : liste, assistant, fiche. Persistance : création, mise à jour, pièces, circuit. Types de pièces : référentiel, corrigé. Résultat : conforme.

### Engagements
Pages : liste, fiche. Pièces obligatoires : référentiel, corrigé. Visa, retour, rejet et liquidation suivante : API et tests existants. Résultat : conforme.

### Liquidations
Pages : liste, fiche. Nature de prestation et maillon paiement : corrigés. Visa et ordonnancement : API. Résultat : conforme.

### Ordonnancements
Pages : liste, délégations, fiche. Effets de signature : API, corrigé. Onglet Marché : absence réelle de contrat, pas un marché fictif. Résultat : conforme pour le périmètre développé.

### Paiements
Pages : liste, lots, fiche, rapprochements. Exécutions, comptes éligibles et lots lus en base. Résultat : conforme.

### Chaîne, tâches, notifications, documents
Tableaux de bord et compteurs calculés. Tâches issues des workflows ouverts, pas d’une liste statique. Notifications en base. Documents générés avec empreinte et code de vérification. Résultat : conforme.

### Tiers
Page unique branchée sur `/tiers`. Résultat : conforme.

### Suivi-évaluation
Tableau de bord, Gantt, saisie, écarts, actions, rapports, synthèse, référentiels de causes, critères et score, fiche 360. Formules côté Laravel. Graphique d’indicateur vide tant qu’aucune série validée n’existe. Résultat : conforme.

## Matrice de traçabilité (données corrigées)

| Module | Écran | Donnée | Source React | Endpoint | Modèle / lecture | Table | Persistée | Statut |
|---|---|---|---|---|---|---|---|---|
| Engagement | Fiche | Pièces obligatoires | `dossier.pieces_attendues` | `GET /engagements/{id}` | `ReferentialReader` | `document_types` | oui | corrigé |
| Expression de besoin | Assistant | Types de pièces | `dossier.types_pieces` | `GET /expressions-besoin/{id}` | `ReferentialReader` | `document_types` | oui | corrigé |
| Liquidation | Fiche | Nature de prestation | `natures` | `GET /liquidations/{id}` | `ReferentialReader` | `reference_values` | oui | corrigé |
| Liquidation | Fiche | Paiement lié | `dossier.paiement` | `GET /liquidations/{id}` | `Ordonnancement.paiement` | `paiements` | oui | corrigé |
| Ordonnancement | Signature | Effets | `dossier.effets` | `GET /ordonnancements/{id}` | `OrdonnancementResource::effects` | `ordonnancements` | oui | corrigé |
