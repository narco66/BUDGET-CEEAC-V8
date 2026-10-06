<?php

/**
 * Organigramme applicatif consolidé de la Commission de la CEEAC, juin 2026.
 * Les codes sont ceux du référentiel organisationnel destiné à BUDGET-DEPENSE.
 *
 * @return list<array<string, mixed>>
 */
return [
    ['sigle' => 'CEEAC', 'name' => 'Communauté Économique des États de l’Afrique Centrale', 'kind' => 'communaute', 'technical' => false, 'children' => [
        ['sigle' => 'COM-CEEAC', 'name' => 'Commission de la CEEAC', 'kind' => 'commission', 'technical' => false, 'children' => [
            ['sigle' => 'DPRES', 'name' => 'Présidence de la Commission', 'kind' => 'departement', 'technical' => false, 'children' => [
                ['sigle' => 'DPRES-CAB', 'name' => 'Cabinet du Président', 'kind' => 'cabinet', 'technical' => false, 'children' => [
                    ['sigle' => 'DPRES-CAB-DIRCAB', 'name' => 'Direction du Cabinet', 'kind' => 'direction', 'technical' => false],
                    ['sigle' => 'DPRES-CAB-CONS', 'name' => 'Conseillers du Président', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-CAB-SCOUR', 'name' => 'Service Courrier', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-CAB-BCJ', 'name' => 'Bureau du Conseiller Juridique', 'kind' => 'bureau', 'technical' => false, 'children' => [
                        ['sigle' => 'DPRES-BCJ-SCADS', 'name' => 'Service Conventions, Accords et Documents solennels', 'kind' => 'service', 'technical' => false],
                        ['sigle' => 'DPRES-BCJ-SARC', 'name' => 'Service Affaires réglementaires et contentieuses', 'kind' => 'service', 'technical' => false],
                    ]],
                ]],
                ['sigle' => 'DPRES-ACC', 'name' => 'Agence Comptable Centrale', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DPRES-ACC-SCPT', 'name' => 'Service Comptabilité', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-ACC-SCPP', 'name' => 'Service Comptabilité des projets et programmes', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-ACC-SRT', 'name' => 'Service Recouvrement et Trésorerie', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DPRES-AI', 'name' => 'Audit Interne', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DPRES-AI-SAI', 'name' => 'Service Audit Interne', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-AI-SAPP', 'name' => 'Service Audit des projets et programmes', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DPRES-CFC', 'name' => 'Contrôle Financier Central', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DPRES-CFC-SCF', 'name' => 'Service Contrôle Financier', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DPRES-CFC-SCFPP', 'name' => 'Service Contrôle financier des programmes et projets', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DPRES-BL', 'name' => 'Bureaux de Liaison', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DPRES-BL-CF', 'name' => 'Bureau de liaison RCA', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-CD', 'name' => 'Bureau de liaison RDC', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-UA', 'name' => 'Bureau de liaison auprès de l’UA', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-BI', 'name' => 'Bureau de liaison Burundi / CIRGL', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-CG', 'name' => 'Bureau de liaison Congo', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-TD', 'name' => 'Bureau de liaison Tchad', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-GQ', 'name' => 'Bureau de liaison Guinée équatoriale', 'kind' => 'bureau', 'technical' => false],
                    ['sigle' => 'DPRES-BL-RW', 'name' => 'Bureau de liaison Rwanda', 'kind' => 'bureau', 'technical' => false],
                ]],
            ]],
            ['sigle' => 'DVPRES', 'name' => 'Vice-Présidence', 'kind' => 'departement', 'technical' => false, 'children' => [
                ['sigle' => 'DVPRES-CAB', 'name' => 'Cabinet du Vice-Président', 'kind' => 'cabinet', 'technical' => false, 'children' => [
                    ['sigle' => 'DVPRES-CAB-CHCAB', 'name' => 'Chef de Cabinet', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DVPRES-CAB-CE', 'name' => 'Chargés d’études', 'kind' => 'service', 'technical' => false],
                ]],
            ]],
            ['sigle' => 'DSG', 'name' => 'Secrétariat Général', 'kind' => 'departement', 'technical' => false, 'children' => [
                ['sigle' => 'DSG-DCRPP', 'name' => 'Direction Communication, Relations publiques et Protocole', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DSG-DCRPP-SCRP', 'name' => 'Service Communication et Relations publiques', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DCRPP-CDA', 'name' => 'Centre de Documentation et des Archives', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DCRPP-SPC', 'name' => 'Service Protocole et Cérémonial', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DCRPP-STI', 'name' => 'Service Traduction et Interprétariat', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DSG-DCMR', 'name' => 'Direction Coopération et Mobilisation des ressources', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DSG-DCMR-SCOOP', 'name' => 'Service Coopération', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DCMR-SMR', 'name' => 'Service Mobilisation des ressources', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DSG-DPPB', 'name' => 'Direction Planification, Programmes et Budget', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DSG-DPPB-SPSE', 'name' => 'Service Planification et Suivi-évaluation', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DPPB-SPP', 'name' => 'Service Programmes et Projets', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DPPB-SB', 'name' => 'Service Budget', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DSG-DRHMG', 'name' => 'Direction Ressources humaines et Moyens généraux', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DSG-DRHMG-SARH', 'name' => 'Service Administration des ressources humaines', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DRHMG-SDRH', 'name' => 'Service Développement des ressources humaines', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DRHMG-SMG', 'name' => 'Service Moyens généraux', 'kind' => 'service', 'technical' => false],
                ]],
                ['sigle' => 'DSG-DSI', 'name' => 'Direction des Systèmes d’information', 'kind' => 'direction', 'technical' => false, 'children' => [
                    ['sigle' => 'DSG-DSI-SED', 'name' => 'Service Études et Développement', 'kind' => 'service', 'technical' => false],
                    ['sigle' => 'DSG-DSI-SEM', 'name' => 'Service Exploitation et Maintenance', 'kind' => 'service', 'technical' => false],
                ]],
            ]],
            ['sigle' => 'DAPPS', 'name' => 'Département Affaires politiques, Paix et Sécurité', 'kind' => 'departement', 'technical' => true, 'children' => [
                ['sigle' => 'DAPPS-DAP', 'name' => 'Direction des Affaires politiques', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DAPPS-DAP-SEGDH', 'name' => 'Service Élections, Gouvernance démocratique et Droits humains', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-DAP-SMDP', 'name' => 'Service Médiation et Diplomatie préventive', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DAPPS-DMARAC', 'name' => 'Direction MARAC et Sécurité', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DAPPS-DMARAC-SOBD', 'name' => 'Service Observation et Banque de données', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-DMARAC-SEA', 'name' => 'Service Évaluation et Analyses', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-DMARAC-SSEC', 'name' => 'Service Sécurité', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DAPPS-EMR', 'name' => 'État-Major Régional', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DAPPS-EMR-CMIL', 'name' => 'Composante militaire', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-EMR-CPG', 'name' => 'Composante Police/Gendarmerie', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-EMR-CCIV', 'name' => 'Composante civile', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DAPPS-EMR-CAS', 'name' => 'Composante Appui et Soutien', 'kind' => 'service', 'technical' => true],
                ]],
            ]],
            ['sigle' => 'DMCAEMF', 'name' => 'Département Marché commun, Affaires économiques, monétaires et financières', 'kind' => 'departement', 'technical' => true, 'children' => [
                ['sigle' => 'DMCAEMF-DAEM', 'name' => 'Direction des Affaires économiques et monétaires', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DMCAEMF-DAEM-SPEMF', 'name' => 'Service Politiques économiques, monétaires et fiscales', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DMCAEMF-DAEM-SIPSP', 'name' => 'Service Industrie et Promotion du secteur privé', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DMCAEMF-DPES', 'name' => 'Direction des Prévisions économiques et des Statistiques', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DMCAEMF-DPES-SAPE', 'name' => 'Service Analyses et Prévisions économiques', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DMCAEMF-DPES-SGBD', 'name' => 'Service Gestion des bases de données', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DMCAEMF-DMC', 'name' => 'Direction du Marché commun', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DMCAEMF-DMC-SADFE', 'name' => 'Service Affaires douanières et Facilitation des échanges', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DMCAEMF-DMC-SPCCPI', 'name' => 'Service Politique commerciale, Concurrence et Promotion des investissements', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DMCAEMF-DMC-SLCD', 'name' => 'Service Libre circulation et Droits d’établissement', 'kind' => 'service', 'technical' => true],
                ]],
            ]],
            ['sigle' => 'DENRADR', 'name' => 'Département Environnement, Ressources naturelles, Agriculture et Développement rural', 'kind' => 'departement', 'technical' => true, 'children' => [
                ['sigle' => 'DENRADR-DERN', 'name' => 'Direction Environnement et Ressources naturelles', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DENRADR-DERN-SGRN', 'name' => 'Service Gestion des ressources naturelles', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DENRADR-DERN-SEB', 'name' => 'Service Environnement et Biodiversité', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DENRADR-DERN-SGRC', 'name' => 'Service Gestion des risques et catastrophes', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DENRADR-DADR', 'name' => 'Direction Agriculture et Développement rural', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DENRADR-DADR-SAAN', 'name' => 'Service Agriculture, Alimentation et Nutrition', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DENRADR-DADR-SEP', 'name' => 'Service Élevage et Pêche', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DENRADR-DADR-SDR', 'name' => 'Service Développement rural', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DENRADR-CRCGRE', 'name' => 'Centre régional de coordination et de gestion des ressources en eau', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DENRADR-CRCGRE-SGSIE', 'name' => 'Service Gestion du système d’information sur l’eau', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DENRADR-CRCGRE-SPRD', 'name' => 'Service Politiques, Recherche et Développement', 'kind' => 'service', 'technical' => true],
                ]],
            ]],
            ['sigle' => 'DATI', 'name' => 'Département Aménagement du territoire et Infrastructures', 'kind' => 'departement', 'technical' => true, 'children' => [
                ['sigle' => 'DATI-DATT', 'name' => 'Direction Aménagement du territoire et Transports', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DATI-DATT-SAT', 'name' => 'Service Aménagement du territoire', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DATI-DATT-STRFF', 'name' => 'Service Transport routier, ferroviaire et fluvial', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DATI-DATT-STAM', 'name' => 'Service Transport aérien et maritime', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DATI-DPTEN', 'name' => 'Direction Postes, Télécommunications et Économie numérique', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DATI-DPTEN-SPT', 'name' => 'Service Postes et Télécommunications', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DATI-DPTEN-SEN', 'name' => 'Service Économie numérique', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DATI-DENER', 'name' => 'Direction de l’Énergie', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DATI-DENER-SRS', 'name' => 'Service Réglementation et Statistiques', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DATI-DENER-SENR', 'name' => 'Service Énergies nouvelles et renouvelables', 'kind' => 'service', 'technical' => true],
                ]],
            ]],
            ['sigle' => 'DPGDHS', 'name' => 'Département Promotion du genre, Développement humain et social', 'kind' => 'departement', 'technical' => true, 'children' => [
                ['sigle' => 'DPGDHS-DGPF', 'name' => 'Direction Genre et Promotion de la femme', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DPGDHS-DGPF-SGRC', 'name' => 'Service Genre et Renforcement des capacités', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DPGDHS-DGPF-SPF', 'name' => 'Service Promotion de la femme', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DPGDHS-DSAS', 'name' => 'Direction Santé et Affaires sociales', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DPGDHS-DSAS-SS', 'name' => 'Service Santé', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DPGDHS-DSAS-SAS', 'name' => 'Service Affaires sociales', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DPGDHS-DJSE', 'name' => 'Direction Jeunesse, Sports et Emploi', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DPGDHS-DJSE-SJS', 'name' => 'Service Jeunesse et Sports', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DPGDHS-DJSE-SE', 'name' => 'Service Emploi', 'kind' => 'service', 'technical' => true],
                ]],
                ['sigle' => 'DPGDHS-DECDT', 'name' => 'Direction Éducation, Culture et Développement technologique', 'kind' => 'direction', 'technical' => true, 'children' => [
                    ['sigle' => 'DPGDHS-DECDT-SEDT', 'name' => 'Service Éducation et Développement technologique', 'kind' => 'service', 'technical' => true],
                    ['sigle' => 'DPGDHS-DECDT-SC', 'name' => 'Service Culture', 'kind' => 'service', 'technical' => true],
                ]],
            ]],
        ]],
    ]],
];
