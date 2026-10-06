# Audit et CRUD du module Préparation budgétaire

Date : 4 octobre 2026. Base concernée : `budget_ceeac_v8`. L’exercice 2026 exécutoire et le report 2027 n’ont pas été adoptés ni réimportés.

## Sources

- `docs/AUDIT_CONFORMITE_CDC_V5.md` : le module M04 était décrit comme absent (ni cadrage, ni plafonds, ni arbitrage, ni publication).
- Code déjà en place : `PreparationService`, table `budget_proposals`, page `/preparation`. Ce flux recopie les lignes de l’exercice exécutoire, les soumet, les retient et les adopte. Il est conservé.
- Référentiels réutilisés : exercices, structures, nomenclature portée par le code de ligne, activités `gar_nodes`, catégories de recettes, tâches et notifications.
- Le cahier des charges complet n’est pas dans le dépôt, seulement l’audit de conformité. Aucun circuit d’adoption supplémentaire n’a été inventé au-delà de la séparation déjà testée : l’auteur ne valide pas sa propre décision, le Directeur du Budget tranche.

## Contradictions retenues

- Le prompt demande des montants décimaux. L’application enregistre les crédits en francs CFA entiers. Les quantités acceptent deux décimales ; le montant est arrondi côté serveur.
- Le dépassement de plafond n’est pas tranché dans les documents présents. Le serveur **bloque** l’enregistrement. Les enveloppes parentes ne s’additionnent pas aux enveloppes filles : seuls les plafonds feuilles limitent les lignes.
- Les scénarios budgétaires parallèles ne sont pas décrits par l’audit du cahier des charges. Ils restent hors périmètre. Les versions de projet couvrent le travail, la comparaison et l’adoption.
- Il n’existe pas de GED transverse unique. Les pièces suivent le modèle des pièces d’expression de besoin (fichier, empreinte, version, retrait daté).
- Le report automatique des lignes (`budget_proposals`) et les dossiers de campagne sont deux chemins. L’adoption d’une version transmet les lignes **retenues des dossiers**, pas le report. Les deux ne doivent pas créer le même code avec deux montants : la transmission refuse le doublon divergent et ignore le doublon identique.

## Matrice

| Objet / fonctionnalité | État initial | Écrans CRUD | API / persistance | Règles et permissions | Correction | Test et résultat |
|---|---|---|---|---|---|---|
| Exercices | CRUD central existant | Réutilisé, pas de second référentiel | `exercices` | Une campagne seulement sur un exercice `preparation` | Aucune duplication | Ouverture 2027 déjà présente, non rouverte |
| Campagnes | Absentes | Liste, création, fiche, modification | `budget_campaigns` | Une campagne non close par exercice ; suppression seulement d’un brouillon sans dossier | Créé | Test + navigateur : ESSAI-PREP créée, rechargée, supprimée |
| Étapes | Absentes | Fiche campagne | `budget_campaign_steps` | Étape verrouillée à la première soumission, non retirable | Créé | Test de verrouillage indirect ; étape « Collecte » visible après enregistrement |
| Hypothèses | Absentes | Cadrage | `budget_hypotheses` | Publication immuable ; une édition crée une nouvelle version | Créé | Test : valeur publiée « 3 » conservée, version 2 créée |
| Orientations | Absentes | Cadrage | `budget_orientations` | Même versionnement, publication notifiée | Créé | Couvert par le même service ; pas de clic navigateur dédié |
| Enveloppes | Absentes | Cadrage | `budget_envelopes` | Fille ≤ parente ; suppression d’un brouillon inutilisé | Créé | Test : 20 000 au-dessus d’un plafond de 10 000 refusé |
| Dossiers | Le report n’était pas un dossier | Liste, création, fiche, modification | `budget_dossiers` | Brouillon ou retourné seulement ; suppression d’un brouillon sans arbitrage ; copie sans décisions | Créé | Test de soumission, refus de modification ensuite, suppression du brouillon |
| Lignes de fonctionnement | Absentes comme lignes de dossier | Fiche dossier | `budget_dossier_lines` | Montant = quantité × coût, calcul serveur ; code unique dans la campagne | Créé | Test : 2 × 100 000 = 200 000 |
| Lignes d’investissement | PAP existant ailleurs | Même fiche, rattachement `gar_node_id` | Même table, nature `pap` | Activité ou tâche du même exercice obligatoire ; sinon lien vers la planification | Créé | Règle serveur ; pas d’activité créée dans un second référentiel |
| Détails de chiffrage | Absents | Fiche dossier | `budget_line_details` | Le total des détails remplace le montant direct, sans double comptage | Créé | Test : le détail 150 000 remplace 200 000 |
| Répartitions | Absentes | Fiche dossier | `budget_line_periods` | La somme ne peut pas dépasser la ligne ; l’égalité est exigée à la soumission | Créé | Test : période excédentaire refusée |
| Financements | Catégories de recettes existantes | Fiche dossier | `budget_line_fundings` | Source du référentiel recettes ; même contrôle de somme | Créé | API en place ; test du plafond voisin |
| Prévisions de recettes | CRUD Recettes | Lien de consultation, pas de second CRUD | Instantané `budget_version_forecasts` | Seules les prévisions `valide` sont figées ; une modification ultérieure ne change pas la version | Créé | Figé dans `figer()` à la création de version |
| Arbitrages | Retenir / écarter sur le report seulement | Fiche dossier | `budget_arbitrages` append-only | L’auteur ne tranche pas ; le montant demandé reste sur la ligne | Créé | Test : demandé 150 000, retenu 120 000, les deux conservés |
| Versions | Absentes | Fiche campagne | `budget_versions.snapshot` | Travail, soumission, retour, validation, adoption, publication. Adoptée immuable | Créé | Test : expert ne valide pas ; directeur adopte ; second appel sans doublon de ligne |
| Transmission Budget | Adoption du report seulement | Action « Adopter » confirmée | `budget_lines` | Transaction, idempotence sur exercice + code, exercice passé `executoire` | Étendu | Test : une ligne 999901 à 120 000, un seul enregistrement. Non exécuté sur 2027 réel |
| Pièces | Absentes | Fiche dossier | `budget_pieces` | pdf, image, xlsx, docx, 10 Mo ; remplacement versionné ; retrait si le dossier est encore éditable | Créé | Non téléversé dans le navigateur |
| Consolidation | Absente | Page dédiée | Calcul, pas une table saisie | Feuilles de plafond seulement ; version figée si elle est validée, adoptée ou publiée | Créé | Produite par `consoliderSnapshot` ; export Excel et PDF |
| Notifications et tâches | Catalogue sans préparation | Cibles `/preparation/campagnes/{id}` et `/preparation/dossiers/{id}` | `PreparationAlerte`, `TaskProjector` | Rôles `expert_budget` et `directeur_budget`, pas de nom en dur | Créé | Test : tâche du dossier terminée après arbitrage |
| Scénarios | Non décrits | — | — | Hors périmètre documenté | Non créé | — |
| Report des lignes | Opérationnel | Vue d’ensemble | `budget_proposals` | Inchangé, avec confirmation avant adoption | Conservé | `CycleBudgetaireTest` toujours vert sur base de test |

## Permissions

| Action | Rôle |
|---|---|
| Consulter | Tout utilisateur habilité (`holdsAny`) |
| Créer, modifier, soumettre | Expert Budget ou Directeur du Budget |
| Suspendre, prolonger, clôturer, archiver, retourner, arbitrer, valider, adopter, publier | Directeur du Budget |
| Adopter une version | Directeur du Budget qui n’est pas l’auteur de la version |
| Arbitrer ou retourner un dossier | Directeur du Budget qui n’est pas l’auteur du dossier |

Un utilisateur sans rôle reçoit un refus et aucun chemin de dossier.

## Fichiers principaux

- Migration `backend/database/migrations/2026_10_04_082324_create_budget_preparation_tables.php` (appliquée sur `budget_ceeac_v8`, 206 ms).
- `PreparationModuleService`, `PreparationModuleController`, modèles `BudgetCampaign` à `BudgetPiece`.
- Notification `PreparationAlerte`, types `campagne` et `dossier_budget`.
- Écrans React sous `frontend/src/features/budget/pages/` et navigation `/preparation`.
- Test `backend/tests/Feature/PreparationBudgetaireTest.php` : 3 tests, 45 assertions, réussis.
- `CycleBudgetaireTest::test_la_preparation_adopte_un_exercice_sans_que_l_auteur_decide` : réussi.

## Vérification navigateur

Session Directeur du Budget, `http://localhost:5173`.

- La vue d’ensemble affiche l’exercice 2027 en préparation et le report existant. Le bouton d’adoption du report demande maintenant une confirmation. Il n’a pas été confirmé.
- Création de la campagne ESSAI-PREP sur l’exercice 2027, fiche rechargée, étape « Collecte des propositions » enregistrée, campagne visible dans la liste après rechargement, puis supprimée. La liste ne la contient plus.

## Anomalies restantes

- Le report automatique et les dossiers de campagne coexistent. Adopter le report 2027 créerait les lignes votées de tout l’exercice. Cette action reste disponible, désormais confirmée, et n’a pas été lancée.
- Les pièces ne passent pas par un module GED unique, parce que ce module n’existe pas.
- Les scénarios nommés comme tels ne sont pas implémentés.
- L’échéancier proche est calculé à l’affichage et signalé par `preparation:echeances` (planifié à 07:25). Cette commande n’a pas été lancée sur les données réelles.
- Le parcours complet d’adoption n’a été exécuté que sur la base de test SQLite, afin de ne pas rendre 2027 exécutoire.
