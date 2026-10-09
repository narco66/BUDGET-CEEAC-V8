import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { ICON } from '../components/ui/icons';

export type NavItem = {
    key: string;
    label: string;
    to: string;
    icon: IconDefinition;
    /** Le chemin courant appartient-il à cette entrée ? */
    match: (path: string) => boolean;
    /** Entrée rattachée à un sous-module (affichée en retrait). */
    nested?: boolean;
    /** Clé de compteur dynamique renvoyée par /api/v1/navigation. */
    badge?: string;
    keywords?: string;
    /** Rôles autorisés à voir l’entrée lorsque le serveur est indisponible. */
    roles?: string[];
};

export type NavGroup = { id: string; label: string; items: NavItem[] };

const prefix = (base: string) => (path: string) => path === base || path.startsWith(`${base}/`);
const isGantt = (path: string) => path === '/suivi/gantt' || /^\/suivi\/activites\/\d+\/gantt$/.test(path);
const adminReaders = ['administrateur_habilitations', 'administrateur_fonctionnel', 'auditeur'];
const adminHabilitations = ['administrateur_habilitations'];
const adminParametrage = ['administrateur_fonctionnel'];

/**
 * Arborescence fonctionnelle de GESBUDEP.
 *
 * Les libellés, icônes et règles d’affichage de route sont la présentation.
 * La visibilité réelle est calculée par le serveur (`/api/v1/navigation`) et
 * utilisée par `navigationForKeys`. Les routes métier restent protégées côté
 * Laravel par les policies et les workflows.
 */
export const NAVIGATION: NavGroup[] = [
    {
        id: 'accueil',
        label: 'Accueil',
        items: [
            { key: 'taches', label: 'Mes tâches', to: '/taches', icon: ICON.tasks, match: prefix('/taches'), badge: 'taches', keywords: 'à traiter corbeille' },
            { key: 'notifications', label: 'Notifications', to: '/notifications', icon: ICON.notifications, match: prefix('/notifications'), badge: 'notifications', keywords: 'cloche alertes messages' },
        ],
    },
    {
        id: 'planification',
        label: 'Planification et performance',
        items: [
            { key: 'planification', label: 'Planification GAR', to: '/planification', icon: ICON.planning, match: prefix('/planification'), keywords: 'chaîne de résultats RBM PAP' },
            { key: 'suivi', label: 'Tableau de bord S&E', to: '/suivi', icon: ICON.monitoring, match: (path) => path === '/suivi' || /^\/suivi\/activites\/\d+$/.test(path), keywords: 'suivi évaluation pilotage' },
            { key: 'gantt', label: 'Gantt d’exécution', to: '/suivi/gantt', icon: ICON.gantt, match: isGantt, keywords: 'planning jalons global période PAP' },
            { key: 'saisie', label: 'Saisie et validation', to: '/suivi/saisie', icon: ICON.entry, match: (path) => prefix('/suivi/saisie')(path) || prefix('/suivi/indicateurs')(path), keywords: 'réalisations indicateurs mesures' },
            { key: 'ecarts', label: 'Écarts', to: '/suivi/ecarts', icon: ICON.gap, match: prefix('/suivi/ecarts'), keywords: 'actions correctives sous-performance' },
            { key: 'actions', label: 'Suivi des actions', to: '/suivi/actions', icon: ICON.actions, match: prefix('/suivi/actions'), keywords: 'risques recommandations mesures correctives' },
            { key: 'referentiels', label: 'Référentiels S&E', to: '/suivi/referentiels', icon: ICON.settings, match: prefix('/suivi/referentiels'), roles: adminParametrage, keywords: 'causes critères score' },
        ],
    },
    {
        id: 'budget',
        label: 'Préparation et budget',
        items: [
            { key: 'preparation', label: 'Vue d’ensemble', to: '/preparation', icon: ICON.entry, match: (path) => path === '/preparation', keywords: 'report exercice cadrage adoption' },
            { key: 'campagnes', label: 'Campagnes', to: '/preparation/campagnes', icon: ICON.calendar, match: prefix('/preparation/campagnes'), keywords: 'ouverture calendrier étapes enveloppes consolidation' },
            { key: 'dossiers', label: 'Propositions', to: '/preparation/dossiers', icon: ICON.document, match: prefix('/preparation/dossiers'), keywords: 'dossiers lignes fonctionnement investissement arbitrage' },
            { key: 'lignes', label: 'Lignes budgétaires', to: '/lignes-budgetaires', icon: ICON.budget, match: prefix('/lignes-budgetaires'), keywords: 'crédits gel virement disponible nomenclature' },
            { key: 'cloture', label: 'Clôture annuelle', to: '/cloture', icon: ICON.archive, match: prefix('/cloture'), keywords: 'exercice clos' },
        ],
    },
    {
        id: 'execution',
        label: 'Exécution budgétaire',
        items: [
            { key: 'besoins', label: 'Expressions de besoin', to: '/expressions-besoin', icon: ICON.need, match: prefix('/expressions-besoin'), keywords: 'EB demande' },
            { key: 'engagements', label: 'Engagements', to: '/engagements', icon: ICON.commitment, match: prefix('/engagements'), badge: 'engagements_a_viser', keywords: 'ENG visa contrôleur financier' },
            { key: 'liquidations', label: 'Liquidations', to: '/liquidations', icon: ICON.settlement, match: prefix('/liquidations'), badge: 'liquidations_a_viser', keywords: 'LIQ service fait facture' },
            { key: 'ordonnancements', label: 'Ordonnancements', to: '/ordonnancements', icon: ICON.order, match: (path) => prefix('/ordonnancements')(path) && !path.startsWith('/ordonnancements/delegations'), badge: 'ordonnancements_a_signer', keywords: 'ORD ordre de paiement signature' },
            { key: 'delegations', label: 'Délégations et seuil', to: '/ordonnancements/delegations', icon: ICON.roles, match: prefix('/ordonnancements/delegations'), nested: true, keywords: 'ordonnateur délégué suppléance' },
            { key: 'marches', label: 'Marchés et contrats', to: '/marches', icon: ICON.commitment, match: prefix('/marches'), keywords: 'marché contrat appel d’offres' },
            { key: 'tiers', label: 'Tiers et comptes', to: '/tiers', icon: ICON.suppliers, match: prefix('/tiers'), keywords: 'fournisseurs bénéficiaires banque RIB' },
        ],
    },
    {
        id: 'tresorerie',
        label: 'Trésorerie et agence comptable',
        items: [
            { key: 'paiements', label: 'Paiements', to: '/paiements', icon: ICON.payment, match: (path) => prefix('/paiements')(path) && !path.startsWith('/paiements/lots'), badge: 'paiements_a_executer', keywords: 'PAY agence comptable règlement' },
            { key: 'lots', label: 'Lots de paiement', to: '/paiements/lots', icon: ICON.paymentBatch, match: prefix('/paiements/lots'), nested: true, keywords: 'virements groupés' },
            { key: 'tresorerie', label: 'Trésorerie réalisée', to: '/chaine/tresorerie', icon: ICON.payment, match: prefix('/chaine/tresorerie'), keywords: 'décaissement ordonnancé réalisé sans plan' },
            { key: 'obligations', label: 'Restes à payer', to: '/chaine/obligations', icon: ICON.calendar, match: prefix('/chaine/obligations'), keywords: 'arriéré ancienneté obligation non soldée sans seuil' },
            { key: 'comptabilite', label: 'Export comptable', to: '/chaine/comptabilite', icon: ICON.report, match: prefix('/chaine/comptabilite'), keywords: 'écritures rejets interface schéma débit crédit' },
            { key: 'rapprochements', label: 'Rapprochements des dépenses', to: '/rapprochements', icon: ICON.reconciliation, match: prefix('/rapprochements'), keywords: 'banque caisse relevé dépenses' },
        ],
    },
    {
        id: 'pilotage',
        label: 'Reporting et pilotage',
        items: [
            { key: 'chaine', label: 'Tableau de chaîne', to: '/chaine', icon: ICON.dashboard, match: (path) => path === '/chaine', keywords: 'synthèse reste à payer' },
            { key: 'dossier', label: 'Dossier financier', to: '/chaine/dossier', icon: ICON.need, match: prefix('/chaine/dossier'), keywords: 'recherche référence EB ENG LIQ ORD paiement' },
            { key: 'execution', label: 'Instantanés d’exécution', to: '/rapports/execution', icon: ICON.report, match: prefix('/rapports/execution'), keywords: 'rapport planifié volumes journalier' },
            { key: 'rapports_se', label: 'Rapports S&E', to: '/suivi/rapports', icon: ICON.report, match: prefix('/suivi/rapports'), keywords: 'rapport de performance évaluation' },
            { key: 'synthese', label: 'Synthèse exécutive', to: '/suivi/synthese', icon: ICON.synthesis, match: prefix('/suivi/synthese'), keywords: 'revue décisions' },
            { key: 'etats', label: 'États de base', to: '/etats', icon: ICON.report, match: prefix('/etats'), keywords: 'ordonnancements non payés rejets ancienneté' },
        ],
    },
    {
        id: 'recettes',
        label: 'Recettes',
        items: [
            { key: 'recettes', label: 'Tableau de bord', to: '/recettes', icon: ICON.revenue, match: (path) => path === '/recettes', keywords: 'recouvrement encaissements' },
            { key: 'recettes_etats', label: 'États', to: '/recettes/etats', icon: ICON.report, match: prefix('/recettes/etats'), keywords: 'export pdf excel' },
            { key: 'previsions', label: 'Prévisions', to: '/recettes/previsions', icon: ICON.budget, match: prefix('/recettes/previsions'), keywords: 'prévision de recettes' },
            { key: 'titres', label: 'Recettes', to: '/recettes/titres', icon: ICON.order, match: prefix('/recettes/titres'), keywords: 'titre ordre de recette constatée appel de fonds' },
            { key: 'contributions', label: 'Contributions', to: '/recettes/contributions', icon: ICON.users, match: prefix('/recettes/contributions'), keywords: 'États membres quote-part' },
            { key: 'creances', label: 'Créances', to: '/recettes/creances', icon: ICON.calendar, match: prefix('/recettes/creances'), keywords: 'échues vieillissement' },
            { key: 'encaissements', label: 'Encaissements', to: '/recettes/encaissements', icon: ICON.payment, match: prefix('/recettes/encaissements'), keywords: 'recettes perçues' },
            { key: 'recettes_rapprochements', label: 'Rapprochements des recettes', to: '/recettes/rapprochements', icon: ICON.reconciliation, match: prefix('/recettes/rapprochements'), keywords: 'banque recettes' },
            { key: 'relances', label: 'Relances', to: '/recettes/relances', icon: ICON.comment, match: prefix('/recettes/relances'), keywords: 'mise en demeure' },
            { key: 'recettes_parametrage', label: 'Paramétrage', to: '/recettes/parametrage', icon: ICON.settings, match: prefix('/recettes/parametrage'), roles: adminParametrage, keywords: 'catégories modes seuils' },
        ],
    },
    {
        id: 'ged',
        label: 'GED et contrôle',
        items: [
            { key: 'verifier', label: 'Vérifier un document', to: '/documents/verifier', icon: ICON.verifyDocument, match: (path) => path === '/documents/verifier', keywords: 'authenticité code SHA' },
            { key: 'recherche', label: 'Recherche documentaire', to: '/documents/recherche', icon: ICON.search, match: prefix('/documents/recherche'), keywords: 'pièces actes conservation' },
            { key: 'ged', label: 'GED', to: '/ged', icon: ICON.document, match: prefix('/ged'), keywords: 'documents versions pièces dossier' },
            { key: 'anomalies', label: 'Registre d’anomalies', to: '/controles/anomalies', icon: ICON.warning, match: prefix('/controles'), keywords: 'contrôle interne constat' },
            { key: 'imports', label: 'Préparation des imports', to: '/imports/preparation', icon: ICON.document, match: prefix('/imports'), roles: ['directeur_budget', 'administrateur_fonctionnel'], keywords: 'staging csv contrôle préalable' },
        ],
    },
    {
        id: 'administration',
        label: 'Administration',
        items: [
            { key: 'administration', label: 'Administration', to: '/administration', icon: ICON.administration, match: (path) => path === '/administration', roles: adminReaders, keywords: 'gouvernance' },
            { key: 'organisation', label: 'Organisation', to: '/administration/organisation', icon: ICON.administration, match: prefix('/administration/organisation'), roles: adminParametrage, nested: true, keywords: 'organigramme structures directions services fonctions' },
            { key: 'roles_permissions', label: 'Rôles et permissions', to: '/administration/roles-permissions', icon: ICON.roles, match: prefix('/administration/roles-permissions'), roles: adminReaders, nested: true, keywords: 'matrice rôles permissions' },
            { key: 'habilitations', label: 'Habilitations', to: '/administration/habilitations', icon: ICON.roles, match: (path) => path === '/administration/habilitations', roles: [...adminHabilitations, 'ordonnateur'], nested: true, keywords: 'habilitation périmètre plafond validité' },
            { key: 'utilisateurs', label: 'Utilisateurs', to: '/administration/utilisateurs', icon: ICON.users, match: prefix('/administration/utilisateurs'), roles: adminReaders, nested: true, keywords: 'comptes rôles périmètre' },
            { key: 'audit', label: 'Journal d’audit', to: '/administration/audit', icon: ICON.history, match: prefix('/administration/audit'), roles: adminReaders, nested: true, keywords: 'audit journal traçabilité' },
            { key: 'suivi_acces', label: 'Suivi des accès', to: '/administration/suivi-acces', icon: ICON.roles, match: (path) => path === '/administration/suivi-acces', roles: adminHabilitations, nested: true, keywords: 'intérims délégations incompatibilités' },
            { key: 'parametrage', label: 'Paramétrage général', to: '/administration/parametrage', icon: ICON.settings, match: prefix('/administration/parametrage'), roles: adminParametrage, nested: true, keywords: 'workflows seuils numérotation sécurité' },
        ],
    },
];

/** Filtre de repli quand /navigation est indisponible. */
export function navigationFor(role: string | null | undefined): NavGroup[] {
    return filterNavigation((item) => !item.roles || (role !== null && role !== undefined && item.roles.includes(role)));
}

/** Filtre à partir des clés renvoyées par le serveur (source d’autorité). */
export function navigationForKeys(allowed: Set<string>): NavGroup[] {
    return filterNavigation((item) => allowed.has(item.key));
}

function filterNavigation(predicate: (item: NavItem) => boolean): NavGroup[] {
    return NAVIGATION
        .map((group) => ({ ...group, items: group.items.filter(predicate) }))
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
