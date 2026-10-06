# Audit de la chaîne de dépense et intégration des modèles PDF

Date : 4 octobre 2026. Application BUDGET-CEEAC / GESBUDEP. Les modèles joints servent de référence de présentation. Les références, dates, montants et mentions d’exemple n’ont pas été recopiés comme valeurs fixes.

## Inventaire des modèles

Modèles joints, lus comme référence de présentation (tous portent la mention « MODÈLE VIERGE » et « QR CODE DU MODÈLE », absentes des actes émis) :

| Fichier joint | Acte |
|---|---|
| `Modele_Fiche_Engagement_CEEAC_QR.pdf` | Fiche d’engagement |
| `Modele_Controle_Budgetaire_CEEAC_QR.pdf` | Contrôle budgétaire |
| `Modele_PV_Reception_CEEAC_QR.pdf` | Procès-verbal de réception |
| `Modele_Attestation_Service_Fait_CEEAC_QR-1.pdf` | Attestation de service fait |
| `Modele_Fiche_Ordonnancement_CEEAC_QR-1.pdf` | Fiche d’ordonnancement |
| `Modele_Bordereau_Transmission_OP_CEEAC_QR.pdf` | Bordereau de transmission |
| `Modele_Fiche_Paiement_CEEAC_QR.pdf` | Fiche de paiement |
| `Modele_Ordre_Virement_CEEAC_QR.pdf` | Ordre de virement |
| `Modele_Bordereau_Cheque_CEEAC_QR.pdf` | Bordereau de remise de chèque |
| `Modele_Bon_Sortie_Caisse_CEEAC_QR.pdf` | Bon de sortie de caisse |
| `Modele_Rapprochement_Paiement_CEEAC_QR.pdf` | Fiche de rapprochement |

Modèles non joints :

- Fiche d’expression de besoin : déjà intégrée (`resources/views/pdf/expression-besoin.blade.php`), conservée.
- Fiche de liquidation : aucun modèle vierge dans le paquet ni dans le projet. L’acte émis reprend la mise en page commune, alimentée par la liquidation visée.
- Reçu de paiement : aucun modèle joint. Aucun acte n’a été inventé.

## Moteur et règles communes

- Vue unique `resources/views/pdf/acte.blade.php` : logo `resources/images/logo-ceeac.png`, sections, tableaux, total, zones de validation, pied de page, pagination DomPDF selon le volume réel.
- Données : `ChainActePresenter`. Émission : `ChainDocumentPublisher` vers `OfficialDocumentService`.
- Un PDF n’exécute ni visa, ni certification, ni paiement. L’échec après une transition déjà commise est journalisé (`archiveQuietly`) et repris à la première consultation.
- Les montants restent des entiers XAF, comme le grand livre. Aucune conversion décimale n’a été introduite.
- Le QR encode `{frontend_url}/verifier/{uuid}`. L’empreinte SHA-256 est calculée sur les octets finaux et stockée dans `generated_documents`. Elle n’est pas imprimée dans le fichier.
- `generated_documents` est en ajout seul. Une nouvelle émission crée une version. Le téléchargement avec `version` restitue le fichier archivé.
- Aucune migration : la colonne `kind` existait déjà.

## Correspondance, déclencheurs et montants

| Document | Template | Module et emplacement | Déclencheur | Source des données | Permissions | GED / QR | Test et résultat |
|---|---|---|---|---|---|---|---|
| Fiche d’expression de besoin | `pdf.expression-besoin` | EB, fiche existante | Approbation, ou première consultation d’un dossier déjà approuvé | Dossier EB, circuit, sous-lignes | Politique PDF EB | Archive + QR opérationnel | Déjà couvert par l’audit EB. Non régénéré dans ce lot |
| Fiche d’engagement | `pdf.acte` | Engagement, rubrique « Documents du dossier » | Visa du contrôleur ; reprise à la consultation si le visa existe et qu’aucune archive n’est là | EB, imputation, sous-lignes, visa, crédits lus à l’émission | `pdf` sur l’engagement | Nouvelle version, QR, SHA-256 | `DocumentsOfficielsTest` : visa archive une fois, `%PDF`, sans « à renseigner ». Navigateur : ENG-2026-003891 version 1, HTTP 200 `%PDF` |
| Contrôle budgétaire | `pdf.acte` | Même fiche d’engagement | Même visa. Le contrôle n’est ni un service fait ni un paiement | `EngagementWorkflow::credit()` à l’émission : disponible avant, engagé, disponible après | `pdf` | Kind distinct `controle_budgetaire` | Test : une ligne `controle_budgetaire` au visa. Navigateur : version 1 du même dossier, disponible 900 000 000 / demandé 456 000 000 / après 444 000 000 |
| Attestation de service fait | `pdf.acte` | Liquidation | Certification (`service_fait_at`). Le PDF ne certifie pas | Certificateur, réserves, montants acceptés | `pdf` | Kind `attestation_service_fait` | Présentateur : refus sans date de service fait. Émission automatique dans `certify` |
| Procès-verbal de réception | `pdf.acte` | Liquidation, lien engagement / EB | Même certification, seulement si un lieu de réception ou un bon de livraison est enregistré. Sinon l’émission silencieuse est ignorée et la certification reste valide | Lignes de service, quantités, lieu, bon | `pdf` | Kind `pv_reception` | Refus explicite hors réception constatée |
| Fiche de liquidation | `pdf.acte` (modèle vierge absent) | Liquidation | Visa de liquidation ; rectification = nouvelle version | Brut, retenue, pénalité, net. Le texte indique que la retenue et la pénalité ne sont pas déduites de nouveau à l’ordonnancement | `pdf` | Kind `liquidation` | Présentateur exige `visa_reference` |
| Fiche d’ordonnancement | `pdf.acte` | Ordonnancement | Signature, seulement si le statut est signé | Ordre, imputation, net à payer, signataire enregistré | `view` | Kind `ordonnancement` | Présentateur refuse un ordre non signé |
| Bordereau de transmission | `pdf.acte` | Ordonnancement / Agence Comptable | Signature et reprise, seulement si `paiement_reference` est renseignée. La transmission n’est pas un paiement | Référence d’accusé, signataire | `view` | Kind `bordereau_transmission` | Émis par `sign` et `reprendre` ; ignoré si l’accusé manque |
| Fiche de paiement | `pdf.acte` | Paiement | Exécution unitaire ou de lot, si le statut compte comme payé. Le rapprochement ne crée plus une nouvelle version de cette fiche | Montant, payé, reste, exécutions | `view` | Kind `paiement` | Émis dans `executer` et `executerLot` |
| Ordre de virement | `pdf.acte` | Paiement par virement | Exécution, mode `virement`, compte bénéficiaire renseigné, statut payé. Le PDF n’ordonne pas la banque | Compte, banque, titulaire, montant payé | `view` | Kind `ordre_virement` | Test : un chèque lève une erreur de validation sur ce kind |
| Bordereau de chèque | `pdf.acte` | Paiement par chèque | Exécution en mode `cheque` avec numéro de chèque. La remise reste distincte de l’encaissement | Numéro, banque, bénéficiaire, montant payé | `view` | Kind `bordereau_cheque` | Refusé si le mode n’est pas le chèque ou si le numéro manque |
| Bon de sortie de caisse | `pdf.acte` | Paiement en espèces | Exécution en mode `caisse` avec un montant payé strictement positif | Bénéficiaire, montant sorti, signataire | `view` | Kind `bon_sortie_caisse` | Refusé pour un autre mode |
| Reçu de paiement | — | — | — | — | — | — | Modèle absent. Non créé |
| Fiche de rapprochement | `pdf.acte` | Paiement, après rapprochement | `rapprocher`, si `reconciliation_reference` est enregistrée | Montant de l’ordre, exécuté, écart | `view` | Kind `rapprochement`, sans réécrire la fiche de paiement | Émis uniquement depuis `rapprocher` |

Un type inconnu sur l’URL PDF répond 404. Une version demandée absente répond 404. La première consultation sans version émet si les conditions du présentateur sont réunies, et l’erreur de condition manquante est renvoyée à l’utilisateur.

## Interface

Rubrique « Documents du dossier » sur les fiches engagement, liquidation, ordonnancement et paiement (`ActesDossier`). Pour chaque acte : type, référence, version, date, événement, téléchargement de la révision archivée. « Émettre » n’apparaît que pour un kind applicable encore absent de la GED. La génération ne change pas le statut métier.

## Exemple émis sur les données persistées

Dossier ENG-2026-003891, session Directeur du Budget, reprise d’un visa historique (l’archive n’existait pas avant ce moteur) :

- Contrôle budgétaire, version 1, événement `premiere_consultation`, empreinte débutant par `31ee108854a1213b`.
- Fiche d’engagement, version 1, événement `premiere_consultation`, empreinte débutant par `df60b005c886ed27`.
- Les deux réponses HTTP sont `200 application/pdf` et commencent par `%PDF`.
- Après rechargement, les deux lignes affichent « Télécharger ».
- Montant de l’engagement : 456 000 000 XAF. Visa : VISA-2026-000001. Statut : transformé en liquidation.
- Ligne 410234 : vote 900 000 000 XAF, inchangé. Cette ligne reste hors import officiel ; elle n’a pas été réécrite.
- L’aperçu texte de la fiche, avant archivage, contenait la référence, l’EB source, le créancier, l’imputation, le montant en lettres et le visa. Le signataire du visa historique s’affiche « Non enregistré » : l’événement de visa n’a pas d’acteur. Aucun nom n’a été inventé.
- Les mentions « à renseigner », « QR CODE DU MODÈLE » et « MODÈLE VIERGE » sont absentes de cet aperçu.

## Anomalies traitées dans ce lot

- Les quatre vues minces `pdf/engagement`, `pdf/liquidation`, `pdf/ordonnancement` et `pdf/paiement` ont été retirées après remplacement de leurs appels par `pdf.acte`.
- Le rapprochement créait une nouvelle version de la fiche de paiement. Il émet maintenant le kind `rapprochement`.
- Un paiement par chèque ne produit pas d’ordre de virement, ni l’inverse : le mode filtre le document.
- Le procès-verbal n’est pas émis si aucune réception n’est constatée, sans faire échouer la certification.
- Les consignes des modèles vierges ne sont pas imprimées.

## Fichiers

- `backend/resources/views/pdf/acte.blade.php` (création)
- `backend/resources/views/pdf/engagement.blade.php`, `liquidation.blade.php`, `ordonnancement.blade.php`, `paiement.blade.php` (suppression)
- `backend/app/Domains/Commitments/Services/ChainActePresenter.php`
- `backend/app/Domains/Commitments/Services/ChainDocumentPublisher.php`
- Contrôleurs engagement, liquidation, ordonnancement, paiement
- Resources de ces quatre dossiers (`actes`, `actes_a_emettre`)
- `frontend/src/features/commitments/ActesDossier.tsx` et les quatre fiches
- `backend/tests/Feature/DocumentsOfficielsTest.php`

Migrations : aucune.

## Contrôles exécutés

- `php artisan test --compact tests/Feature/DocumentsOfficielsTest.php` : 6 tests, 46 assertions, réussis. Couverture : archive unique au visa, contrôle budgétaire, octets PDF, immuabilité, détection d’altération, vérification publique sans empreinte, refus de l’ordre de virement sur un chèque.
- `vendor/bin/pint --format agent` sur les fichiers PHP de ce lot.
- Navigateur, `http://127.0.0.1:5173/engagements/1`, session Directeur du Budget : émission puis téléchargement proposé pour les deux actes applicables. Montants relus en base après émission.

## Limites restantes

- Le reçu de paiement n’existe pas : aucun modèle n’était joint.
- La fiche de liquidation n’a pas de modèle vierge de référence. Sa présentation est celle du gabarit commun.
- Seule la fiche d’engagement a été extraite en texte page par page avant archivage. Les autres kinds n’ont pas été rendus en images page par page. La pagination suit le contenu via DomPDF.
- Les archives déjà stockées avant ce moteur conservent leur fichier. Une réédition crée une version nouvelle.
- Un visa historique sans acteur sur l’événement affiche « Non enregistré ».
- Les montants officiels sont des entiers XAF. Le grand livre n’a pas été passé en décimal.
- L’initiation Hors PAP n’est pas réservée au Service des Moyens Généraux : aucune procédure de `/docs` ne nomme cette unité comme filtre, et les dossiers existants restent valides.
- La suite PHPUnit complète et `tsc` n’ont pas été relancés pour ce lot.
