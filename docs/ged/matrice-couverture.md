# Matrice de couverture — module GED (91 rubriques)

Source fonctionnelle : description détaillée du module GED. Les constats s’appuient sur le code livré (`ged_*`, `GedService`, routes `/api/v1/ged`, écrans `/ged`).

Légende : **opérationnel** = parcours réel contrôlé côté serveur ; **partiel** = une partie du comportement existe, le reste est absent ou limité ; **non livré** = pas de fonctionnement.

| N° | Rubrique | État | Écart et suite |
|---|---|---|---|
| 1 | Finalité transversale | partiel | Socle unique livré. Pas encore la mémoire de tous les modules. |
| 2 | Positionnement dans tous les modules | partiel | GED autonome + dépôt depuis EB, ENG, LIQ, ORD, PAY. GAR, préparation, marchés, recettes, S&E et clôture ne sont pas des cibles contrôlées. |
| 3 | Trois origines | partiel | Dépôt utilisateur et acte généré. Pas d’origine « import externe » distincte. |
| 4 | Un fichier, plusieurs liaisons | opérationnel | `ged_documents` + `ged_links`. L’héritage de chaîne ne duplique pas le fichier. |
| 5 | Arborescence logique | partiel | Classement par métadonnées, pas une arborescence Exercice / module navigable. |
| 6 | Classement multidimensionnel | partiel | Filtres référence, titre, statut, origine, exercice, vues. Pas fournisseur ni unité dans l’écran. |
| 7 | Fiche de métadonnées | partiel | Titre, référence, statut, confidentialité, exercice, empreinte, versions, liens. Pas date d’expiration. |
| 8 | Types administrables | partiel | Réutilisation de `document_types`. Pas d’écran d’administration GED dédié. |
| 9 | Pièces obligatoires | opérationnel | Lecture des types actifs et `required`. Blocage seulement si `ged.bloquer_pieces = 1`. |
| 10 | Versement des PDF officiels | opérationnel | Après commit de `OfficialDocumentService::archive()`. Échec GED journalisé, l’acte n’est pas annulé. |
| 11 | Documents de la chaîne | opérationnel | Cibles contrôlées : expression de besoin, engagement, liquidation, ordonnancement, paiement. |
| 12 | Dossier consolidé | opérationnel | Ancêtres et descendants de la même branche, pas les dossiers frères. |
| 13 | Versionnage | opérationnel | Nouvelle version, motif, conservation des précédentes. Testé. |
| 14 | Une version courante | opérationnel | `is_current` basculé dans la transaction. Testé. |
| 15 | Statuts | partiel | depose, valide, rejete, signe, archive, annule, purge. Pas de calcul d’expiration. |
| 16 | Dépôt multiple et glisser-déposer | partiel | Dépôt unitaire réel depuis le dossier. Pas de sélection multiple. |
| 17 | Formats | opérationnel | Liste serveur. Pas de contrôle PDF/A. |
| 18 | Contrôle MIME, extension, taille | opérationnel | Refus `422` ou quarantaine. Testé. |
| 19 | Antivirus | partiel | Quarantaine si MIME incohérent ou si l’analyse est obligatoire. Aucun binaire ClamAV. |
| 20 | SHA-256 | opérationnel | Par version. Téléchargement `409` si l’empreinte diverge. |
| 21 | Doublons | partiel | Signalés sur la fiche seulement si l’autre document est visible. Aucune fusion de droits. |
| 22 | Nommage technique | partiel | Chemin `ged/{uuid}/v{n}-{aléa}.ext`. Pas le masque `CEEAC_2026_ENG_…`. |
| 23 | Référence unique | opérationnel | `DOC-{année}-{séquence}`. |
| 24 | Visualiseur intégré | non livré | Téléchargement autorisé, pas d’aperçu PDF dans la page. |
| 25 | Miniatures de liste | non livré | La liste n’envoie pas le binaire et n’a pas de miniature. |
| 26 | Recherche | partiel | Référence et titre, côté serveur. Pas le contenu ni le fournisseur. |
| 27 | Recherche multicritère combinée | partiel | Statut, origine, exercice et vue, pas la période ni l’unité. |
| 28 | OCR et plein texte | non livré | Aucun moteur d’extraction n’est installé. |
| 29 | Document vers dossiers | opérationnel | Fiche : liaisons. Dossier : liste des pièces visibles. |
| 30 | Contrôle d’accès backend | opérationnel | Liste, fiche, téléchargement, export. Test d’un tiers refusé. |
| 31 | Niveaux de confidentialité | opérationnel | public, interne, restreint, confidentiel, tres_confidentiel. Public ≠ anonyme. |
| 32 | Permissions `ged.*` distinctes | non livré | Arbitrage : le catalogue d’habilitations est déjà posé ; une permission absente refuserait tout le monde. L’accès repose sur rôle, politique du dossier, périmètre et confidentialité. |
| 33 | Suppression logique | opérationnel | `deleted_at`. Purge physique réservée à l’administrateur, après annulation, hors gel, sans effacer un acte officiel. |
| 34 | Journal central | opérationnel | `AdministrationService::audit` → `audit_events`. |
| 35 | Historique documentaire | opérationnel | Vue des événements du document, pas une seconde table. |
| 36 | Circuit documentaire propre | partiel | Décisions valider, rejeter, archiver, geler, restaurer. Pas d’étape automatique « à vérifier » sur le statut. |
| 37 | Signature électronique | partiel | Les actes générés sont marqués signés. Pas de vérification cryptographique du signataire. |
| 38 | Acte figé à la génération | opérationnel | La GED pointe le fichier et l’empreinte déjà archivés. Elle ne régénère pas le PDF. |
| 39 | Mes tâches | opérationnel | Tâche `ged:{id}` assignée au contrôleur financier, close lors d’une décision. |
| 40 | Notifications | partiel | Validation et rejet notifiés au déposant, sans le titre confidentiel. Pas d’alerte d’expiration. |
| 41 | Dossier fournisseur | non livré | Les tiers ne sont pas une cible de liaison. |
| 42 | Dossier de marché | non livré | Le modèle existe ; il n’est pas dans la liste contrôlée. |
| 43 | GAR-RBM | non livré | Pas de cible pilier / activité. |
| 44 | Suivi-évaluation | non livré | Pas de liaison indicateur. |
| 45 | Rapports produits | partiel | Les actes de la chaîne sont versés. Les rapports S&E ne le sont pas. |
| 46 | Dossier de clôture | non livré | Pas de versement à la clôture annuelle. |
| 47 | Archivage | opérationnel | Statut archive, nouvelle version refusée. |
| 48 | Politique de conservation | partiel | L’écran de recherche documentaire existant conserve `retain_until`. Pas de règles GED par type. |
| 49 | Gel | opérationnel | `frozen_at` bloque version, archivage, suppression et purge. Testé. |
| 50 | Coffre renforcé | partiel | Porté par la confidentialité, pas par un espace de stockage distinct. |
| 51 | Écran principal | partiel | `/ged` : indicateurs, recherche, vues, pagination. Pas « partagés avec moi » ni « récents » dédiés. |
| 52 | Tableau | partiel | Référence, titre, statut, confidentialité, version. Pas auteur ni module en colonne. |
| 53 | Fiche | opérationnel | `/ged/{id}` : métadonnées, versions, liens, journal, décision. |
| 54 | Documents récents | partiel | Tri par identifiant décroissant, pas une vue nommée. |
| 55 | À traiter | opérationnel | Vue `a_traiter` et tâche. |
| 56 | Favoris | non livré | Table `ged_favorites` créée, sans action. |
| 57 | Tags | partiel | Ajout persistant. Le filtre de recherche par tag n’est pas branché. |
| 58 | Liens entre documents | non livré | Seules les liaisons métier existent. |
| 59 | Téléchargement contrôlé | opérationnel | `GET /ged/{id}/fichier`. |
| 60 | URL temporaire signée | non livré | Transmission authentifiée directe, sans URL signée. |
| 61 | Abstraction de stockage | partiel | Disque `local` privé. S3 non utilisé par la GED. |
| 62 | Modèle de données | opérationnel | Tables `ged_*` pour éviter la collision avec `document_types` et `generated_documents`. |
| 63 | Identité du document | opérationnel | uuid, référence, statut, confidentialité, propriétaire, unité, exercice. |
| 64 | Versions | opérationnel | Chemin, MIME, taille, empreinte, motif, signature, scan. |
| 65 | Liaisons contrôlées | opérationnel | Cinq types. Existence et droit de dépôt vérifiés. |
| 66 | Origine user / système | opérationnel | `origin` et `generated_document_id` unique. |
| 67 | Modèles documentaires | non livré | `document_templates` n’est pas recopié dans la version GED. |
| 68 | Instantané de génération | partiel | L’acte officiel garde son snapshot. La GED référence cet acte. |
| 69 | Unicité sous concurrence | partiel | Contrainte unique + verrou sur la dernière référence. Pas de séquence PostgreSQL dédiée. |
| 70 | Compensation fichier / base | opérationnel | Fichier supprimé si la transaction de dépôt échoue. |
| 71 | Survie après suppression métier | partiel | La GED ne suit pas le `cascade` de `eb_documents`. Le lien GED reste. Le fichier source d’une EB supprimée peut disparaître avec la cascade historique : non modifié dans cette livraison. |
| 72 | Intégrité référentielle | opérationnel | Clés étrangères et liste blanche des cibles. |
| 73 | Pagination sans binaire | opérationnel | `per_page` plafonné à 50. |
| 74 | Index | partiel | Statut, exercice, confidentialité, empreinte, cible. |
| 75 | Sauvegarde conjointe | partiel | Procédure écrite dans `docs/ged/exploitation.md`. Restauration non répétée ici. |
| 76 | Contrôle périodique | opérationnel | `ged:integrite`, planifié à 07:50. Exécution du 5 octobre 2026 : 0 anomalie sur les versions reprises. |
| 77 | Indicateurs | partiel | Total visible, à vérifier, rejetés, archives, quarantaine. Pas le volume ni les expirations. |
| 78 | Administration des référentiels | non livré | Pas d’écran pour créer un type ou une durée de conservation. |
| 79 | Blocage de transition | partiel | Uniquement l’ouverture d’engagement, et seulement si le réglage est activé. |
| 80 | Taux de complétude | opérationnel | Taux entier, ou message « aucune exigence applicable » si aucune règle. Quarantaine et rejet exclus. |
| 81 | Héritage sans copie | opérationnel | Testé EB → engagement. |
| 82 | Propre / hérité | opérationnel | Champ `heritage` sur le dossier. |
| 83 | Export du dossier d’audit | partiel | Export d’une sélection, pas un bouton unique « tout le dossier ». |
| 84 | ZIP autorisé | opérationnel | Les identifiants invisibles sont omis. Testé. |
| 85 | Bordereau | opérationnel | `bordereau.csv` dans l’archive : référence, version, date, statut, empreinte. |
| 86 | API | opérationnel | Référentiel, liste, fiche, dépôt, version, fichier, décision, dossier, tag, export. |
| 87 | Validation serveur | opérationnel | Form requests inline du contrôleur et règles du service. |
| 88 | Composants React nommés | partiel | `GedDossier`, liste et fiche couvrent dépôt, checklist, versions, liens et journal. Pas sept composants séparés ni Font Awesome hors registre existant. |
| 89 | Pas de second journal | opérationnel | Historique = `audit_events`. |
| 90 | Recette bout en bout | partiel | `GedTest` : 2 tests, 30 assertions. Navigateur : liste paginée, fiche `DOC-2026-000090`, dossier EB, et pièce héritée sur l’engagement `ENG-2026-000455`. Liquidation, ordonnancement et paiement n’ont pas été rejoués à l’écran. |
| 91 | Une seule GED | opérationnel | Pas de GED par module. Les écrans métier appellent la même API. |
