# Audit de la chaîne de dépense et refonte de la fiche d’expression de besoin

Date : 4 octobre 2026. Application BUDGET-CEEAC / GESBUDEP. Les références, montants, dates et acteurs du PDF d’exemple n’ont pas été recopiés comme valeurs fixes.

## Sources utilisées

- Modèle visuel : `Fiche_EB_CEEAC_avec_QRcode-1.pdf` (A4 portrait, deux pages d’exemple).
- Logo officiel fourni, recadré sur le disque blanc et enregistré dans `backend/resources/images/logo-ceeac.png`.
- Circuit déjà codé : `ExpressionBesoinWorkflow`, `OrdonnancementWorkflow`, `OrdDelegation`, politiques et tâches existantes.
- Référentiel organisationnel (`organization_units`, fonctions, affectations). Aucun nom de personne n’est figé dans le workflow.
- Configuration `gesbudep.frontend_url` (`FRONTEND_URL`, repli `http://localhost:5173`, puis `app.url`).
- Base PostgreSQL `budget_ceeac_v8`. La base `budget_ceeac` n’a pas été touchée. L’import 2026 n’a pas été relancé.

## Règles retenues

- EB PAP rattachée à un département technique : initiateur, directeur de la structure, commissaire, ordonnateur.
- EB Hors PAP et EB PAP d’une structure d’appui : initiateur, directeur, secrétaire général, ordonnateur.
- Le seuil de 5 000 000 XAF reste la délégation d’ordonnancement (`ord_delegations.seuil_max`) et le seuil d’alerte des tâches (`gesbudep.taches.montant_eleve`). Il ne conditionne pas la validation de l’EB.
- L’initiation Hors PAP n’a pas été restreinte au Service des Moyens Généraux : aucun document de `/docs` ne nomme cette unité, et les dossiers ainsi que les tests existants s’appuient sur la structure de la ligne budgétaire. Forcer ce filtre aurait invalidé le circuit en place.
- Un acteur absent ou plusieurs affectations concurrentes produisent un message `anomalie_acteur`. Le dossier n’est pas validé automatiquement.
- Les montants de ligne sont calculés en entiers FCFA avec `bcmath` (arrondi half-up). Le solde indicatif du PDF est le disponible hors de la demande courante, diminué du montant demandé. Il n’est pas une écriture d’engagement.
- Le PDF officiel n’est archivé qu’une fois le dossier approuvé ou transformé. Un aperçu de brouillon n’est pas versé à la GED et n’a pas de QR. Une réédition officielle crée une nouvelle version ; les fichiers déjà archivés ne sont pas réécrits.
- L’empreinte SHA-256 est calculée sur les octets du PDF final et stockée dans les métadonnées. Elle n’est pas imprimée dans le fichier.
- Le QR contient uniquement l’URL `{frontend_url}/verifier/{code}`. Le code est l’UUID opaque du document. Aucun secret n’y figure. La page publique indique l’existence, la référence, la version, la date d’émission, l’état d’archive (courant ou remplacé) et l’intégrité. Elle ne donne ni l’empreinte, ni l’auteur, ni le contenu du dossier.

## Tableau d’audit

| Élément audité | Anomalie constatée | Correction réalisée | Fichiers concernés | Vérification | Résultat |
|---|---|---|---|---|---|
| Fiche PDF EB | Modèle simple, sans logo officiel, sans QR opérationnel, sans la présentation du modèle joint | Modèle unique A4 : en-tête CEEAC, sections 01 à 07, total vert, pied de page et pagination | `resources/views/pdf/expression-besoin.blade.php`, `ExpressionBesoinFichePresenter.php`, `resources/images/logo-ceeac.png` | Rendu DomPDF : dossier court (2 pages), 18 sous-lignes (3 pages), Hors PAP brouillon. Titres de section collés à leur tableau. Pagination `1 / 2` et `1 / 3` | Conforme |
| Points de génération | Aperçu, téléchargement et archive pouvaient diverger | Aperçu et archive utilisent la même vue. L’aperçu d’un dossier déjà approuvé sert le fichier archivé, sans nouvelle version | `ExpressionBesoinController.php`, `OfficialDocumentService.php` | Test d’aperçu : le nombre de `GeneratedDocument` ne change pas. Aperçu réel du brouillon 24 : PDF 200, zéro archive | Conforme |
| QR code | Mention d’exemple, pas un code lisible | Encodeur QR (byte, ECC L) et URL issue de la configuration | `app/Shared/Documents/QrCode.php`, `DocumentVerification.php` | Décodage du QR imprimé sur la page 2 : `https://budget.ceeac.example/verifier/11111111-1111-1111-1111-111111111111` | Conforme |
| Vérification publique | Un code non UUID provoquait une erreur SQL 500 sur PostgreSQL | Refus 404 avant la requête, pour la vérification publique et authentifiée | `DocumentController.php`, `routes/api.php`, `PublicVerifyPage.tsx`, `AppRouter.tsx` | API locale : 404 JSON. Page `/verifier/inconnu` hors session : message d’absence, sans contenu de dossier | Conforme |
| Empreinte et GED | Risque d’imprimer l’empreinte dans le PDF, ce qui l’invaliderait | Empreinte calculée après génération, stockée, contrôlée au téléchargement, absente du PDF | `OfficialDocumentService.php` | Test : la réponse publique ne contient pas l’empreinte | Conforme |
| Acteurs du circuit EB | Aucun signalement si le poste est vacant ou occupé plusieurs fois | Message explicite, sans validation automatique | `ExpressionBesoinWorkflow.php`, `ExpressionBesoinResource.php`, `EbFichePage.tsx` | Fiche PAP `EB/2026/DATI-DENER/000458` : initiateur, directeur DATI-DENER, commissaire, ordonnateur | Conforme |
| Initiation Hors PAP par les Moyens Généraux | La procédure citée dans la demande n’est pas établie dans `/docs` | Aucun nouveau verrou. Le circuit existant et les tests sont conservés | — | Constat documenté, non codé | Anomalie restante |
| Seuil 5 000 000 XAF | Risque de le confondre avec une règle d’EB | Laissé sur la délégation d’ordonnancement et l’alerte des tâches | `OrdonnancementWorkflow.php`, `config/gesbudep.php` | Lecture du code et de `OrdDelegation` | Conforme |
| Montants de ligne | Produit quantité × prix unitaire en flottant | Calcul `bcmath`, résultat entier FCFA | `ExpressionBesoinWorkflow.php` | Couvert par `ExpressionBesoinTest` | Conforme |
| Données d’exemple du modèle | Risque de figer la référence, les montants et les consignes du PDF joint | Présentateur alimenté par le dossier. « Non renseigné » ou « Sans objet » si une rubrique facultative est vide. Bandeau BROUILLON. Visa seulement si un événement existe | `ExpressionBesoinFichePresenter.php` | Le HTML de test ne contient ni `EB-2026-000079`, ni « QR CODE DU MODÈLE », ni « MODÈLE DE PRÉSENTATION » | Conforme |
| Jeu d’essai EB | Deux structures DATI violaient l’unicité du sigle | Sigle du département de test : `DATI-DEP`. La direction reste `DATI` | `ExpressionBesoinSeeder.php` | Les tests EB se créent de nouveau | Conforme |
| Listes de la chaîne | À confirmer sur les données persistées | Aucune réécriture des workflows d’engagement, liquidation, ordonnancement et paiement | Contrôleurs et pages déjà en place | Session authentifiée : EB, engagements, liquidations, ordonnancements et paiements répondent 200 avec des dossiers | Conforme |
| PDF historiques | Une régénération aurait écrasé les actes signés | Téléchargement du fichier stocké. Nouvelle génération = nouvelle version | `OfficialDocumentService.php` | L’aperçu approuvé délègue au PDF archivé | Conforme |

## Intégration du QR et de l’archive

1. Un identifiant opaque est attribué au document avant le rendu.
2. Le PDF est produit avec le QR pointant vers cet identifiant.
3. Le SHA-256 des octets obtenus est enregistré avec la référence EB, l’auteur, la date, l’état du workflow et la version.
4. Le téléchargement compare l’empreinte au fichier. Un fichier altéré est refusé.
5. La page `/verifier/{code}` est hors du shell authentifié. Elle interroge `GET /api/v1/public/documents/{code}` (limite 30 requêtes par minute).

En développement, `FRONTEND_URL` est `http://localhost:5173`. Le schéma n’est pas forcé en HTTPS : c’est la valeur déployée qui fait foi. Aucun domaine n’est écrit dans le code du QR.

## Contrôles exécutés

- `php artisan test --compact tests/Feature/ExpressionBesoinTest.php tests/Feature/DocumentsOfficielsTest.php` : 11 tests, 79 assertions, tous réussis. Couverture : aperçu non archivé, contenu du modèle, URL de vérification, refus d’un code inconnu et d’un code non UUID, absence de l’empreinte dans la réponse publique.
- `vendor/bin/pint --format agent` sur les fichiers PHP modifiés.
- `npx tsc --noEmit` dans `frontend/` : succès.
- Rendu visuel DomPDF, ensuite supprimé de `storage/app` : fiche courte, fiche longue (en-têtes de tableau répétés, total tenu avec son libellé), fiche Hors PAP avec bandeau de brouillon et sans QR.
- Décodage du QR de la page 2 du PDF court.
- Navigateur, session Directeur du Budget : page publique `/verifier/inconnu` ; fiche du brouillon 24 avec le bouton Aperçu PDF ; téléchargement authentifié `%PDF-1.7` (963 091 octets) sans création d’archive.
- Listes authentifiées EB, engagements (8), liquidations (8), ordonnancements (4) et paiements (2) : HTTP 200.

## Anomalies restantes

- L’initiation d’une EB Hors PAP n’est pas réservée au Service des Moyens Généraux, faute de règle documentée et pour ne pas contredire les dossiers existants.
- Les PDF officiels déjà archivés gardent leur ancienne présentation. Seules les nouvelles générations utilisent le modèle. Un aperçu d’un dossier déjà approuvé affiche donc l’archive historique jusqu’à une réédition explicite.
- `anomalieActeur` parcourt les utilisateurs pour compter les affectations compatibles. Le résultat est exact ; le coût grandit avec l’annuaire.
- Les nouvelles archives officielles, y compris engagement et liquidation, sont émises en A4 portrait. Les fichiers déjà stockés ne changent pas.
- Le parcours complet engagement → paiement n’a pas été rejoué bouton par bouton dans cette session. Les listes et le circuit EB ont été contrôlés sur les données persistées ; les workflows, verrous et tests de ces étapes n’ont pas été réécrits.
