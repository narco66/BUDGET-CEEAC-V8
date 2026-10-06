import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { ICON } from '../components/ui/icons';

export type NavItem = {
    label: string;
    to: string;
    icon: IconDefinition;
    /** Le chemin courant appartient-il à cette entrée ? */
    match: (path: string) => boolean;
    /** Entrée rattachée à un sous-module (affichée en retrait). */
    nested?: boolean;
    /** Intertitre affiché avant cette entrée lorsqu’il change. */
    section?: string;
    badge?: 'taches';
    keywords?: string;
    /** Rôles autorisés à voir l’entrée. Absent : visible pour tout acteur connecté. */
    roles?: string[];
};

export type NavGroup = { id: string; label: string; items: NavItem[]; subhead?: Record<string, string> };

const prefix = (base: string) => (path: string) => path === base || path.startsWith(`${base}/`);
const isGantt = (path: string) => path === '/suivi/gantt' || /^\/suivi\/activites\/\d+\/gantt$/.test(path);

/**
 * Arborescence fonctionnelle de GESBUDEP. Source unique pour la barre
 * latérale, le fil d’Ariane et la palette de navigation.
 */
export const NAVIGATION: NavGroup[] = [
    {
        id: 'travail',
        label: 'Espace de travail',
        items: [
            { label: 'Mes tâches', to: '/taches', icon: ICON.tasks, match: prefix('/taches'), badge: 'taches', keywords: 'à traiter corbeille' },
            { label: 'Notifications', to: '/notifications', icon: ICON.notifications, match: prefix('/notifications'), keywords: 'cloche alertes messages' },
        ],
    },
    {
        id: 'budget',
        label: 'Budget',
        items: [
            { label: 'Lignes budgétaires', to: '/lignes-budgetaires', icon: ICON.budget, match: prefix('/lignes-budgetaires'), section: 'Exécution', keywords: 'crédits gel virement disponible nomenclature' },
            { label: 'Vue d’ensemble', to: '/preparation', icon: ICON.entry, match: (path) => path === '/preparation', section: 'Préparation', keywords: 'report exercice cadrage adoption' },
            { label: 'Campagnes', to: '/preparation/campagnes', icon: ICON.calendar, match: prefix('/preparation/campagnes'), keywords: 'ouverture calendrier étapes enveloppes consolidation' },
            { label: 'Propositions', to: '/preparation/dossiers', icon: ICON.document, match: prefix('/preparation/dossiers'), keywords: 'dossiers lignes fonctionnement investissement arbitrage' },
            { label: 'Clôture annuelle', to: '/cloture', icon: ICON.archive, match: prefix('/cloture'), section: 'Clôture', keywords: 'exercice clos' },
            { label: 'États de base', to: '/etats', icon: ICON.report, match: prefix('/etats'), keywords: 'ordonnancements non payés rejets ancienneté' },
        ],
    },
    {
        id: 'depense',
        label: 'Chaîne de dépense',
        items: [
            { label: 'Tableau de chaîne', to: '/chaine', icon: ICON.dashboard, match: (path) => path === '/chaine', section: 'Suivi', keywords: 'synthèse reste à payer' },
            { label: 'Dossier financier', to: '/chaine/dossier', icon: ICON.need, match: prefix('/chaine/dossier'), keywords: 'recherche référence EB ENG LIQ ORD paiement' },
            { label: 'Instantanés d’exécution', to: '/rapports/execution', icon: ICON.report, match: prefix('/rapports/execution'), keywords: 'rapport planifié volumes journalier' },
            { label: 'Expressions de besoin', to: '/expressions-besoin', icon: ICON.need, match: prefix('/expressions-besoin'), section: 'Circuit', keywords: 'EB demande' },
            { label: 'Engagements', to: '/engagements', icon: ICON.commitment, match: prefix('/engagements'), keywords: 'ENG visa contrôleur financier' },
            { label: 'Liquidations', to: '/liquidations', icon: ICON.settlement, match: prefix('/liquidations'), keywords: 'LIQ service fait facture' },
            { label: 'Ordonnancements', to: '/ordonnancements', icon: ICON.order, match: (path) => prefix('/ordonnancements')(path) && !path.startsWith('/ordonnancements/delegations'), keywords: 'ORD ordre de paiement signature' },
            { label: 'Délégations et seuil', to: '/ordonnancements/delegations', icon: ICON.roles, match: prefix('/ordonnancements/delegations'), nested: true, keywords: 'ordonnateur délégué suppléance' },
            { label: 'Paiements', to: '/paiements', icon: ICON.payment, match: (path) => prefix('/paiements')(path) && !path.startsWith('/paiements/lots'), keywords: 'PAY agence comptable règlement' },
            { label: 'Lots de paiement', to: '/paiements/lots', icon: ICON.paymentBatch, match: prefix('/paiements/lots'), nested: true, keywords: 'virements groupés' },
            { label: 'Trésorerie réalisée', to: '/chaine/tresorerie', icon: ICON.payment, match: prefix('/chaine/tresorerie'), section: 'Trésorerie', keywords: 'décaissement ordonnancé réalisé sans plan' },
            { label: 'Restes à payer', to: '/chaine/obligations', icon: ICON.calendar, match: prefix('/chaine/obligations'), keywords: 'arriéré ancienneté obligation non soldée sans seuil' },
            { label: 'Export comptable', to: '/chaine/comptabilite', icon: ICON.report, match: prefix('/chaine/comptabilite'), section: 'Comptabilité', keywords: 'écritures rejets interface schéma débit crédit' },
            { label: 'Rapprochements des dépenses', to: '/rapprochements', icon: ICON.reconciliation, match: prefix('/rapprochements'), keywords: 'banque caisse relevé dépenses' },
            { label: 'Marchés et contrats', to: '/marches', icon: ICON.commitment, match: prefix('/marches'), section: 'Référentiels', keywords: 'marché contrat appel d’offres' },
            { label: 'Tiers et comptes', to: '/tiers', icon: ICON.suppliers, match: prefix('/tiers'), keywords: 'fournisseurs bénéficiaires banque RIB' },
        ],
    },
    {
        id: 'recettes',
        label: 'Recettes',
        items: [
            { label: 'Tableau de bord', to: '/recettes', icon: ICON.revenue, match: (path) => path === '/recettes', section: 'Pilotage', keywords: 'recouvrement encaissements' },
            { label: 'États', to: '/recettes/etats', icon: ICON.report, match: prefix('/recettes/etats'), keywords: 'export pdf excel' },
            { label: 'Prévisions', to: '/recettes/previsions', icon: ICON.budget, match: prefix('/recettes/previsions'), section: 'Recouvrement', keywords: 'prévision de recettes' },
            { label: 'Recettes', to: '/recettes/titres', icon: ICON.order, match: prefix('/recettes/titres'), keywords: 'titre ordre de recette constatée appel de fonds' },
            { label: 'Contributions', to: '/recettes/contributions', icon: ICON.users, match: prefix('/recettes/contributions'), keywords: 'États membres quote-part' },
            { label: 'Créances', to: '/recettes/creances', icon: ICON.calendar, match: prefix('/recettes/creances'), keywords: 'échues vieillissement' },
            { label: 'Encaissements', to: '/recettes/encaissements', icon: ICON.payment, match: prefix('/recettes/encaissements'), keywords: 'recettes perçues' },
            { label: 'Rapprochements des recettes', to: '/recettes/rapprochements', icon: ICON.reconciliation, match: prefix('/recettes/rapprochements'), section: 'Suivi', keywords: 'banque recettes' },
            { label: 'Relances', to: '/recettes/relances', icon: ICON.comment, match: prefix('/recettes/relances'), keywords: 'mise en demeure' },
            { label: 'Paramétrage', to: '/recettes/parametrage', icon: ICON.settings, match: prefix('/recettes/parametrage'), section: 'Réglages', keywords: 'catégories modes seuils' },
        ],
    },
    {
        id: 'performance',
        label: 'Performance',
        items: [
            { label: 'Planification GAR', to: '/planification', icon: ICON.planning, match: prefix('/planification'), section: 'Planification', keywords: 'chaîne de résultats RBM PAP' },
            { label: 'Tableau de bord S&E', to: '/suivi', icon: ICON.monitoring, match: (path) => path === '/suivi' || (/^\/suivi\/activites\/\d+$/.test(path)), section: 'Suivi', keywords: 'suivi évaluation pilotage' },
            { label: 'Gantt d’exécution', to: '/suivi/gantt', icon: ICON.gantt, match: isGantt, keywords: 'planning jalons' },
            { label: 'Saisie et validation', to: '/suivi/saisie', icon: ICON.entry, match: (path) => prefix('/suivi/saisie')(path) || prefix('/suivi/indicateurs')(path), keywords: 'réalisations indicateurs mesures' },
            { label: 'Écarts', to: '/suivi/ecarts', icon: ICON.gap, match: prefix('/suivi/ecarts'), keywords: 'actions correctives sous-performance' },
            { label: 'Suivi des actions', to: '/suivi/actions', icon: ICON.actions, match: prefix('/suivi/actions'), keywords: 'risques recommandations mesures correctives' },
            { label: 'Rapports', to: '/suivi/rapports', icon: ICON.report, match: prefix('/suivi/rapports'), section: 'Restitution', keywords: 'rapport de performance évaluation' },
            { label: 'Synthèse exécutive', to: '/suivi/synthese', icon: ICON.synthesis, match: prefix('/suivi/synthese'), keywords: 'revue décisions' },
            { label: 'Référentiels S&E', to: '/suivi/referentiels', icon: ICON.settings, match: prefix('/suivi/referentiels'), keywords: 'causes critères score' },
        ],
    },
    {
        id: 'gouvernance',
        label: 'Gouvernance',
        items: [
            { label: 'Vérifier un document', to: '/documents/verifier', icon: ICON.verifyDocument, match: (path) => path === '/documents/verifier', section: 'Contrôle', keywords: 'authenticité code SHA' },
            { label: 'Recherche documentaire', to: '/documents/recherche', icon: ICON.search, match: prefix('/documents/recherche'), keywords: 'pièces actes conservation' },
            { label: 'GED', to: '/ged', icon: ICON.document, match: prefix('/ged'), keywords: 'documents versions pièces dossier' },
            { label: 'Registre d’anomalies', to: '/controles/anomalies', icon: ICON.warning, match: prefix('/controles'), keywords: 'contrôle interne constat' },
            { label: 'Préparation des imports', to: '/imports/preparation', icon: ICON.document, match: prefix('/imports'), keywords: 'staging csv contrôle préalable', roles: ['directeur_budget', 'administrateur_fonctionnel'] },
            { label: 'Administration', to: '/administration', icon: ICON.administration, match: (path) => path === '/administration', section: 'Administration', keywords: 'gouvernance', roles: ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'] },
            { label: 'Organisation', to: '/administration/organisation', icon: ICON.administration, match: prefix('/administration/organisation'), section: 'Administration', nested: true, keywords: 'organigramme structures directions services fonctions' },
            { label: 'Rôles et permissions', to: '/administration/roles-permissions', icon: ICON.roles, match: prefix('/administration/roles-permissions'), nested: true, keywords: 'matrice rôles permissions', roles: ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'] },
            { label: 'Habilitations', to: '/administration/habilitations', icon: ICON.roles, match: (path) => path === '/administration/habilitations', nested: true, keywords: 'habilitation périmètre plafond validité', roles: ['administrateur_habilitations', 'ordonnateur'] },
            { label: 'Utilisateurs', to: '/administration/utilisateurs', icon: ICON.users, match: prefix('/administration/utilisateurs'), nested: true, keywords: 'comptes rôles périmètre', roles: ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'] },
            { label: 'Journal d’audit', to: '/administration/audit', icon: ICON.history, match: prefix('/administration/audit'), nested: true, keywords: 'audit journal traçabilité', roles: ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'] },
            { label: 'Suivi des accès', to: '/administration/suivi-acces', icon: ICON.roles, match: (path) => path === '/administration/suivi-acces', nested: true, keywords: 'intérims délégations incompatibilités', roles: ['administrateur_habilitations'] },
            { label: 'Droits de la chaîne', to: '/administration/suivi-acces#droits', icon: ICON.roles, match: () => false, nested: true, keywords: 'permissions eb visa paiement', roles: ['administrateur_habilitations'] },
            { label: 'Incompatibilités', to: '/administration/suivi-acces#incompatibilites', icon: ICON.roles, match: () => false, nested: true, keywords: 'séparation des fonctions cumul', roles: ['administrateur_habilitations'] },
            { label: 'Intérims', to: '/administration/suivi-acces#interims', icon: ICON.roles, match: () => false, nested: true, keywords: 'suppléance remplacement', roles: ['administrateur_habilitations'] },
            { label: 'Délégations administratives', to: '/administration/suivi-acces#delegations', icon: ICON.roles, match: () => false, nested: true, keywords: 'délégation révocation', roles: ['administrateur_habilitations'] },
            { label: 'Paramétrage général', to: '/administration/parametrage', icon: ICON.settings, match: prefix('/administration/parametrage'), nested: true, keywords: 'workflows seuils numérotation sécurité', roles: ['administrateur_fonctionnel'] },
        ],
    },
];

/** Menu visible pour le rôle courant. Les entrées sans rôle restent affichées. */
export function navigationFor(role: string | null | undefined): NavGroup[] {
    return NAVIGATION
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => !item.roles || (role !== null && role !== undefined && item.roles.includes(role))),
        }))
        .filter((group) => group.items.length > 0);
}

export function findNavigation(path: string): { group: NavGroup; item: NavItem } | null {
    let found: { group: NavGroup; item: NavItem } | null = null;

    for (const group of NAVIGATION) {
        for (const item of group.items) {
            if (!item.match(path)) {
                continue;
            }
            if (!found || item.to.length > found.item.to.length) {
                found = { group, item };
            }
        }
    }

    return found;
}
