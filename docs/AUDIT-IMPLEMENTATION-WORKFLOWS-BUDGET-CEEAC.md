# Audit et implémentation des workflows

Date : 4 octobre 2026. Application BUDGET-CEEAC / GESBUDEP. Le référentiel joint est un document de travail consolidé, « à valider ». Ses codes sont proposés. Ils n’ont pas été réimportés et n’ont pas remplacé le référentiel déjà en base.

## Ce que la base contient déjà

113 structures. Les rattachements demandés y sont déjà présents, avec les sigles du référentiel :

| Structure | Sigle en base | Rattachement constaté |
|---|---|---|
| Présidence | DPRES | Département sous la Commission |
| Vice-Présidence | DVPRES | Même niveau |
| Secrétariat Général | DSG | Remplace le secrétariat administratif dans le libellé |
| Direction Planification, Programmes et Budget | DSG-DPPB | Sous le Secrétariat Général |
| Service Budget | DSG-DPPB-SB | Sous la DPPB |
| Direction Ressources humaines et Moyens généraux | DSG-DRHMG | Sous le Secrétariat Général |
| Service Moyens généraux | DSG-DRHMG-SMG | Sous la DRHMG |
| Bureau du Conseiller Juridique | DPRES-CAB-BCJ | Sous le Cabinet |
| Contrôle Financier Central | DPRES-CFC | Sous la Présidence |
| Agence Comptable Centrale | DPRES-ACC | Sous la Présidence |
| Audit Interne | DPRES-AI | Sous la Présidence |

Le rôle applicatif `directeur_budget` correspond au poste budgétaire de la DPPB et de son Service Budget. Aucune direction supplémentaire « Direction du Budget » n’a été créée.

## Circuit d’expression de besoin

Le moteur existant est conservé. Les étapes publiées ne changent pas :

- PAP d’une structure technique : initiateur, directeur, commissaire, ordonnateur ;
- PAP d’une direction d’appui et hors PAP : initiateur, directeur, secrétaire général, ordonnateur.

Correction : le directeur et le commissaire étaient exigés sur la structure exacte du dossier. Un service ne pouvait donc pas être validé par le directeur de sa direction ni par le commissaire de son département. La résolution suit maintenant `parent_id`, sans découper les sigles :

- directeur : le dossier et ses parents jusqu’à la direction incluse ;
- commissaire : le dossier et ses parents jusqu’au département technique inclus ;
- si cette direction ou ce département n’existe pas dans la chaîne, seul le rattachement exact reste admissible ;
- secrétaire général et ordonnateur : inchangés, car l’étape elle-même choisit déjà lequel des deux circuits s’applique.

Les notifications de ces étapes utilisent la même chaîne, y compris l’affectation de fonction sur la direction ou le département compétent. Elles ne partent pas à tous les titulaires du rôle.

Hors PAP : une nouvelle expression ne peut être créée que par un agent rattaché à `DSG-DRHMG-SMG` ou à l’une de ses structures. Le contrôle de disponibilité des crédits n’est pas levé. Les dossiers hors PAP déjà ouverts ne sont pas réécrits.

Il n’y a pas d’étape distincte « chef de service ». L’ajouter modifierait les dossiers en cours. Si l’initiateur est aussi le directeur compétent, la séparation des fonctions continue de s’appliquer à l’étape suivante : le même compte ne cumule pas les rôles incompatibles déjà enregistrés.

## Ordonnancement et seuil

Le seuil de 5 000 000 XAF n’est pas établi par le référentiel organisationnel. Il reste le paramètre des délégations d’ordonnancement (`ord_delegations`, devise XAF, acte et dates). En dessous ou à égalité du plafond d’une délégation courante, l’ordonnateur attendu est le secrétaire général. Au-dessus, c’est l’ordonnateur principal. Une délégation absente ou échue ne donne pas le droit de signer.

## Circuits non étendus

| Sujet | Décision |
|---|---|
| Avis du Bureau du Conseiller Juridique | Non inséré sur toutes les dépenses. Les procédures ne disent pas quels contrats le rendent bloquant. |
| Audit Interne | Reste un contrôle indépendant. Il n’est pas un validateur financier. |
| Président | N’est pas ajouté à chaque circuit. Il intervient comme ordonnateur principal au-delà du seuil paramétré. |
| Recettes et préparation budgétaire | Leurs workflows existants ne sont pas recopiés sur le circuit de la dépense. |
| Deuxième moteur de workflow | Non créé. Les définitions publiées (`ChainWorkflowCatalog`) continuent de filtrer les étapes. |

## Matrice

| Circuit | Anomalie initiale | Correction | Acteurs | Règles et sources | Tests | Point restant |
|---|---|---|---|---|---|---|
| EB PAP technique | Directeur et commissaire limités à l’identifiant exact de la structure | Remontée par `parent_id` jusqu’à la direction ou au département technique | Directeur de la direction, commissaire du département | Référentiel, responsabilités DPPB / commissaire / SG. Codes déjà en base, statut « à valider » | Directeur de la direction admis, directeur d’une autre direction refusé, commissaire du département admis | Pas d’étape chef de service séparée |
| EB hors PAP | N’importe quelle structure pouvait ouvrir le besoin | Création réservée au Service des Moyens généraux | Agent de DSG-DRHMG-SMG, puis directeur, SG, ordonnateur | Circuit demandé et structure présente en base | Agent hors SMG : 422. Agent du SMG : création | Le bénéficiaire et l’imputation restent ceux de la ligne. L’approbation ne saute pas le contrôle des crédits |
| Engagement et visa | Déjà porté par l’expert budgétaire puis le contrôleur financier | Inchangé | DPPB / Service Budget, Contrôle Financier Central | Rôles `expert_budget`, `directeur_budget`, `controleur_financier` | Tests de chaîne déjà en place, non rejoués en entier | Le libellé « Directeur du Budget » reste le rôle, pas une structure |
| Ordonnancement | Seuil déjà paramétré | Inchangé | SG dans le plafond, ordonnateur principal au-delà | `ord_delegations`. Le PDF organisationnel ne fixe pas 5 000 000 XAF | Couvert par les tests d’ordonnancement existants | Le cumul anti-fractionnement n’est pas une règle supplémentaire inventée |
| Liquidation et paiement | Acteurs par rôle et par étape | Inchangés | Initiateur du dossier, contrôleur, comptable, agent comptable | Policies et workflows de paiement | Non rejoués dans ce lot | L’avis juridique obligatoire n’est pas défini |
| Avis juridique, audit, circuits des bureaux et projets | Pas de règle de blocage par type de dépense | Non ajoutés | — | Référentiel : responsabilités distinctes, sans seuil ni caractère bloquant | — | À formaliser avant d’insérer une étape |

## Tests exécutés

`php artisan test --compact tests/Feature/ExpressionBesoinTest.php` :

- `test_le_directeur_de_la_direction_couvre_le_service_et_le_hors_pap_part_des_moyens_generaux` : 9 assertions, réussi ;
- `test_le_retour_exige_un_motif_et_conserve_le_dossier` et `test_la_soumission_exige_une_justification_et_une_piece` : 14 assertions, réussis.

La suite complète n’a pas été relancée. Aucune migration. Le référentiel organique, les lignes budgétaires et les décisions historiques n’ont pas été réécrits.

## Fichiers

- `backend/app/Domains/Organization/Services/WorkflowActorResolver.php`
- `backend/app/Domains/Needs/Services/ExpressionBesoinWorkflow.php`
- `backend/tests/Feature/ExpressionBesoinTest.php`
