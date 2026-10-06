# Audit et correction du module Notifications

Date : 4 octobre 2026. Les notifications métier qui ont une cible ouvrent le dossier autorisé. Le texte du message n’est jamais utilisé pour deviner la destination.

## Constat

La cloche affichait un texte non cliquable. Les notifications de workflow portaient déjà l’identifiant du dossier (`expression_besoin_id`, `engagement_id`, etc.). Les tâches et le suivi-évaluation portaient un chemin interne dans `lien`. Aucune page ne centralisait la lecture, le compteur et l’accès.

Il n’existe pas de canal temps réel. Le compteur est relu à l’ouverture, au focus de l’onglet et après chaque marquage. Un second onglet se met à jour par l’événement de stockage du navigateur.

Les notifications sont écrites dans la même transaction que l’opération métier. Un rollback les retire avec elle. Elles ne sont pas mises en file.

## Résolution des cibles

`NotificationCatalog` est la seule liste de types et de chemins. `NotificationTargetResolver` lit, dans cet ordre :

1. l’objet `cible` (`type`, `id`) des nouvelles notifications ;
2. une clé d’identifiant déjà enregistrée ;
3. un ancien `lien` qui correspond exactement à une route interne.

Un lien externe, un `..`, une requête ou un fragment est ignoré. L’ouverture contrôle ensuite l’existence du dossier et le droit de consultation. Une tâche déjà terminée reste ouvrable pour son destinataire, sans changer son statut.

| Type | Route |
|---|---|
| Expression de besoin | `/expressions-besoin/{id}` |
| Engagement | `/engagements/{id}` |
| Liquidation | `/liquidations/{id}` |
| Ordonnancement | `/ordonnancements/{id}` |
| Paiement | `/paiements/{id}` |
| Tâche | `/taches/{id}` |
| Prévision | `/recettes/previsions/{id}` |
| Titre de recette | `/recettes/titres/{id}` |
| Écart | `/suivi/ecarts/{id}` |
| Activité | `/suivi/activites/{id}` |
| Indicateur | `/suivi/indicateurs/{id}/saisie` |
| Saisie, synthèse, rapports, liste des écarts | pages correspondantes |
| Rapprochements des recettes | `/recettes/rapprochements` |

La ligne budgétaire n’a pas de fiche. Une alerte de ce type ouvre son détail et propose la liste `/lignes-budgetaires`.

## Tableau

| Notification | Déclencheur | Destinataire | Cible réelle | Correction | Test et résultat |
|---|---|---|---|---|---|
| EB soumise, retournée, rejetée ou transformée | `ExpressionBesoinWorkflow` | Initiateur ou titulaire de l’étape | `/expressions-besoin/{id}` | Objet `cible` ajouté ; clic autorisé | Test d’ouverture et de lecture : réussi |
| Engagement à viser, rejeté ou annulé | `EngagementWorkflow` | Acteur de l’étape ou initiateur | `/engagements/{id}` | Même modèle | Interface : `ENG-2026-003893` ouvre `/engagements/13`, compteur 11 → 10 |
| Liquidation, service fait | `LiquidationWorkflow` | Contrôleur ou initiateur | `/liquidations/{id}` | Même modèle | Résolution couverte par le catalogue ; pas de clic live sur une liquidation |
| Ordonnancement à signer | `OrdonnancementWorkflow` | Ordonnateur ou initiateur | `/ordonnancements/{id}` | Même modèle | Catalogue et contrôle d’existence |
| Paiement à prendre en charge ou à signer | `PaiementWorkflow` | Agence comptable | `/paiements/{id}` | Même modèle | Catalogue et contrôle d’existence |
| Tâche affectée ou relancée | `TaskProjector`, `TaskReminderService` | Affectataire ou rôle | `/taches/{id}` | Ancien `lien` reconnu | Tâche terminée toujours consultable, statut inchangé : réussi |
| Prévision ou titre | Projecteur de tâches, alerte et encaissement | Rôles recettes | Fiche prévision ou titre | Ancien `lien` reconnu | Page réelle : PRV et REC listés comme liens |
| Écart, activité, saisie, synthèse | Services de suivi | Directeur ou auteur | Page de suivi correspondante | Ancien `lien` reconnu | Écrans `/suivi/saisie`, synthèse, rapports, écarts : réussi |
| Document généré | Archive PDF | — | Pas de notification distincte | Le dossier porte le PDF | Non déclenchée ; pas de cible inventée |
| Alerte de ligne budgétaire | Aucun émetteur actuel | — | Pas de fiche de ligne | Détail + liste si une clé `budget_line_id` existe | Aucune notification de ce type en base |
| Information sans cible | Message seul | Destinataire | Détail `/notifications/{id}` | Pas de redirection | Lien externe refusé, chemin nul : réussi |

## Comportement d’accès

- `GET /api/v1/notifications` : pagination, filtres lu / module / type / période, tri antéchronologique.
- `GET /api/v1/notifications/compteur` : non lues.
- `GET /api/v1/notifications/{id}` : détail, sans marquer comme lue.
- `POST /api/v1/notifications/{id}/ouvrir` : autorise, puis marque comme lue. 404 si la notification n’appartient pas à l’utilisateur. 403 si le droit a été retiré. 422 si le dossier n’existe plus.
- `POST .../lire`, `.../non-lue` et `POST /api/v1/notifications/lues` ne naviguent pas. Un second appel ne change plus le compteur.

La cloche, la page `/notifications` et le bloc de Mes tâches utilisent ce même résolveur. « Marquer comme lue » est un bouton séparé du lien. L’ouverture du panneau ne marque rien.

Les notifications déjà stockées ne sont pas réécrites. Celles qui n’ont ni identifiant ni chemin autorisé restent consultables dans leur détail.

## Fichiers

- `backend/app/Shared/Notifications/NotificationCatalog.php`
- `backend/app/Shared/Notifications/NotificationTargetResolver.php`
- `backend/app/Shared/Notifications/Http/NotificationController.php`
- `backend/routes/api/v1/notifications.php`
- `backend/database/migrations/2026_10_04_070500_add_notifications_recipient_read_index.php`
- Notifications de workflow EB, engagement, liquidation, ordonnancement, paiement
- `frontend/src/features/notifications/`
- Cloche dans `AppShell.tsx`, page Mes tâches, menu et routes

## Vérifications exécutées

- `NotificationsCiblesTest` et `MesTachesTest` : 14 tests, 112 assertions, réussis.
- `npx tsc --noEmit` : réussi.
- Migration d’index appliquée sur `budget_ceeac_v8`.
- Navigateur, session Directeur du Budget : page Notifications, clic sur l’engagement, arrivée sur la fiche, compteur diminué de 1. Cette notification est donc lue.

## Anomalies restantes

- Deux notifications identiques peuvent coexister, par exemple le rapport `RAP-SE-2026-0001-v1` affiché deux fois. Un blocage global des messages identiques empêcherait une tâche recréée d’avertir à nouveau son destinataire. Les relances quotidiennes et les alertes de recette ont déjà leur propre garde.
- Aucune notification n’est émise à la seule génération d’un PDF. L’accès au document passe par la fiche du dossier.
- Il n’y a pas de fiche de ligne budgétaire : une alerte de ligne ne peut ouvrir que la liste.
- Le compteur n’est pas poussé en temps réel. Il se met à jour au focus et après une action.
