# Matrice de couverture — journalisation, traçabilité et horodatage (73 rubriques)

Preuves : table `audit_events`, trigger `audit_events_append_only`, `AuditService`, écran `/administration/audit`, chronologie `/audit/chronologie`. Les historiques de chaîne préexistaient (`eb_events` et équivalents).

Légende : **opérationnel**, **partiel**, **non livré**.

| N° | Rubrique | État | Écart |
|---|---|---|---|
| 1 | Finalité | partiel | Le journal central existe et s’enrichit. Tous les modules ne passent pas encore par le nouveau contrat. |
| 2 | Positionnement transversal | partiel | Administration, authentification, chaîne, GED et tâches écrivent déjà. GAR, recettes et S&E gardent leurs historiques locaux lorsqu’ils n’appelaient pas le journal. |
| 3 | Contexte conservé | partiel | Les nouveaux événements figent le nom et le rôle. Les événements anciens et repris n’ont pas ce contexte : il n’est pas inventé. |
| 4 | Service central | opérationnel | `AuditService`. `AdministrationService::audit`, `FinancialAudit` et l’authentification l’utilisent. |
| 5 | Audit distinct des logs | opérationnel | La piste est `audit_events`. Les logs Laravel restent le diagnostic. |
| 6 | Typologie | partiel | Le préfixe d’action (`auth.`, `ged.`, `audit.`) sert de nomenclature. Pas de catalogue fermé de tous les verbes. |
| 7 | Actions importantes | partiel | Les flux déjà instrumentés le restent. Aucun observer global n’a été ajouté, pour éviter les doublons. |
| 8 | Événements automatiques | partiel | Intégrité GED et reprise identifient le système. Toutes les commandes planifiées ne créent pas un événement. |
| 9 | Identité de l’acteur | opérationnel | `actor_id`, `actor_name` au moment de l’action, `actor_type`. |
| 10 | Rôle exercé | partiel | Le rôle principal est enregistré, ainsi que la liste des rôles tenus. Le rôle « qui a autorisé » n’est pas choisi au hasard parmi plusieurs. |
| 11 | Habilitation utilisée | partiel | Instantané des rôles tenus. Pas l’identifiant de la ligne `user_roles` qui a rendu l’action licite. |
| 12 | Délégations et intérims | partiel | Un intérim actif est noté dans le contexte. Les délégations d’ordonnancement ne sont pas recopiées sur chaque signature. |
| 13 | Objet | opérationnel | Type, identifiant, référence lorsque fournie. |
| 14 | Périmètre et exercice | partiel | Unité de l’acteur si elle est connue. L’exercice n’est rempli que si l’appelant le transmet. |
| 15 | Horloge serveur | opérationnel | `occurred_at` vient du serveur. |
| 16 | UTC | opérationnel | `occurred_at` est un `timestamptz`. L’affichage convertit vers le fuseau configuré. |
| 17 | Affichage Libreville | opérationnel | Défaut `Africa/Libreville`, indiqué dans l’export. |
| 18 | Synchronisation NTP | non livré | Documentée comme responsabilité d’exploitation. Pas de sonde de dérive dans l’application. |
| 19 | Ordre stable | opérationnel | Tri par `id`, corrélation et `causation_id`. |
| 20 | Avant / après | opérationnel | Champs sensibles retirés. Testé. |
| 21 | Différence lisible | opérationnel | `changed_fields` affiché en fiche, pas le JSON brut seul. |
| 22 | Motifs | partiel | Le motif est conservé quand le métier l’exige déjà. Le journal ne rajoute pas une obligation absente du circuit. |
| 23 | Résultat | opérationnel | `succes`, `refus`, `echec`. Un rollback n’enregistre pas la réussite. Testé. |
| 24 | Contexte de requête | opérationnel | `request_id`, adresse IP, agent tronqué, canal. |
| 25 | Corrélation | opérationnel | Un identifiant par requête HTTP. La causalité relie une rectification à l’original. |
| 26 | Même transaction | opérationnel | La réussite est écrite dans la transaction métier. |
| 27 | Refus hors transaction | opérationnel | Le refus écrit après l’annulation reste visible. Testé. |
| 28 | Immutabilité logique | opérationnel | Rectification additive. Test de modification refusé. |
| 29 | Protection base | opérationnel | Trigger PostgreSQL déjà en place. La commande de purge refuse la suppression. |
| 30 | Scellement | non livré | Non implémenté : une empreinte dans la même base ne prouverait pas l’inviolabilité. |
| 31 | Durées de conservation | non livré | Aucune durée arbitraire. La purge applicative est refusée. |
| 32 | Archivage des événements | non livré | Pas de déplacement vers un magasin d’archives. |
| 33 | Gel | opérationnel | `audit_holds` et événement `audit.gel`. La purge reste refusée, gel ou non. |
| 34 | Sauvegarde | partiel | Procédure écrite. Restauration non rejouée. |
| 35 | Chaîne EB → PAY | partiel | 186 historiques de chaîne repris le 5 octobre 2026. La chronologie relie la branche. Les écrans LIQ, ORD et PAY n’ont pas été rejoués au navigateur dans cette livraison. |
| 36 | Expressions de besoin | partiel | Historique local existant, recopié. Les nouveaux passages passent par le journal si le service appelait déjà `audit`. |
| 37 | Engagements | partiel | Même situation. Effets budgétaires détaillés seulement si l’appelant les met dans avant/après. |
| 38 | Liquidations | partiel | Historique repris. |
| 39 | Ordonnancements | partiel | Le seuil appliqué n’est repris que s’il était déjà dans l’événement source. |
| 40 | Paiements | partiel | Signature et exécution restent des actions distinctes dans les historiques existants. |
| 41 | Retours et rejets | partiel | Statut avant/après repris lorsque la source les avait. |
| 42 | Transitions automatiques | partiel | Visibles si un événement source existe. Pas de nouvel identifiant d’idempotence au-delà de la reprise. |
| 43 | GAR-RBM | non livré | Pas d’instrumentation nouvelle des piliers et activités. |
| 44 | Préparation budgétaire | non livré | Pas d’instrumentation nouvelle des arbitrages. |
| 45 | Recettes | non livré | Pas d’instrumentation nouvelle. |
| 46 | Suivi-évaluation | non livré | Pas d’instrumentation nouvelle. |
| 47 | GED | opérationnel | Dépôt, version, décision et échec d’intégrité passent par le journal central. |
| 48 | Notifications | non livré | Création et lecture ne sont pas recopiées dans `audit_events`. |
| 49 | Mes tâches | partiel | Le commentaire de tâche était déjà journalisé. Attribution et clôture ne le sont pas systématiquement. |
| 50 | Référentiels | partiel | Les changements qui appelaient `AdministrationService::audit` conservent le nouveau contexte. |
| 51 | Comptes et habilitations | opérationnel | Mots de passe et jetons exclus. Testé. |
| 52 | Authentification | opérationnel | Connexion et échec existants, désormais via `AuditService`. Pas d’agrégation anti-saturation au-delà du rate limit de connexion déjà en place. |
| 53 | Séparation des fonctions | partiel | Les règles SoD restent préventives. Le journal les éclaire s’il contient les acteurs. |
| 54 | Permissions distinctes | non livré | Arbitrage : ne pas créer `audit.view*` dans le catalogue figé. L’accès repose sur le rôle principal. |
| 55 | Périmètre de lecture | partiel | Le sensible est masqué. Le périmètre organisationnel ne filtre pas encore le journal global. |
| 56 | Minimisation | opérationnel | Liste sans avant/après. Fiche sensible masquée hors auditeur et administrateur des habilitations. |
| 57 | Journal global | opérationnel | Pagination serveur, données réelles. |
| 58 | Filtres | partiel | Texte, module, résultat, action, corrélation. Pas la période ni l’unité dans l’écran. |
| 59 | Timeline dossier | opérationnel | API et panneau sur EB, ENG, LIQ, ORD, PAY. |
| 60 | Fiche événement | opérationnel | Heure locale, UTC, acteur, différences, événements corrélés. |
| 61 | Exports | partiel | CSV filtré, journalisé, fuseau indiqué. Pas de XLSX ni de PDF. |
| 62 | Indicateurs | partiel | Totaux visibles, refus, échecs. Pas de délai moyen. |
| 63 | Modèle de données | opérationnel | Colonnes ajoutées sur `audit_events`, sans réécriture des lignes anciennes. |
| 64 | Architecture | opérationnel | Écriture centralisée. Pas d’observer fourre-tout. |
| 65 | API et interface | opérationnel | Liste, fiche, chronologie, export, gel, rectification. |
| 66 | Index | opérationnel | Date, module, corrélation, objet. Pas de partitionnement : le volume repris est de quelques centaines d’événements. |
| 67 | Supervision | partiel | L’échec d’une réussite annule l’opération. Pas de file d’attente ni de compteur de saturation. |
| 68 | Détection d’anomalies | non livré | Pas de moteur qui qualifierait une fraude. |
| 69 | Alertes de sécurité | non livré | Pas de notification nouvelle vers les responsables. |
| 70 | Deny by default | opérationnel | Journal global et export refusés hors rôles prévus. Chronologie refusée si le dossier n’est pas visible. |
| 71 | Contrôle interne | partiel | La piste soutient le contrôle. Elle ne remplace pas la séparation des fonctions. |
| 72 | Recette | partiel | `AuditTraceTest` : 2 tests, 19 assertions. Reprise réelle : 186, seconde exécution 0. Purge réelle refusée. Navigateur : le Directeur du Budget voit le refus d’accès ; l’auditeur voit 340 événements, dont la signature et l’exécution du paiement 6 distinguées, et ouvre une fiche. |
| 73 | Architecture logique | opérationnel | Une table, un service, des consultations contrôlées. |
