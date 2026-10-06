import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faArrowRightArrowLeft,
    faBan,
    faCircleCheck,
    faCircleXmark,
    faFileCirclePlus,
    faGears,
    faHourglassHalf,
    faPaperPlane,
    faPause,
    faPencil,
    faRotateLeft,
    faScaleBalanced,
    faSignature,
    faTriangleExclamation,
} from '@fortawesome/free-solid-svg-icons';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'orange' | 'violet' | 'indigo' | 'sky' | 'pap' | 'brand';

type StatusStyle = { tone: Tone; icon: IconDefinition };

const DRAFT: StatusStyle = { tone: 'neutral', icon: faPencil };
const SUBMITTED: StatusStyle = { tone: 'info', icon: faPaperPlane };
const PENDING: StatusStyle = { tone: 'warning', icon: faHourglassHalf };
const RETURNED: StatusStyle = { tone: 'orange', icon: faRotateLeft };
const DONE: StatusStyle = { tone: 'success', icon: faCircleCheck };
const REJECTED: StatusStyle = { tone: 'danger', icon: faCircleXmark };
const CANCELLED: StatusStyle = { tone: 'neutral', icon: faBan };
const SUSPENDED: StatusStyle = { tone: 'neutral', icon: faPause };
const TRANSFORMED: StatusStyle = { tone: 'indigo', icon: faArrowRightArrowLeft };
const GENERATED: StatusStyle = { tone: 'sky', icon: faFileCirclePlus };
const PREPARING: StatusStyle = { tone: 'sky', icon: faGears };

/**
 * Représentation visuelle des statuts de workflow : couleur + libellé + icône,
 * jamais la couleur seule. Les libellés viennent toujours de l’API.
 */
export const STATUS_STYLES: Record<string, StatusStyle> = {
    brouillon: DRAFT,
    a_completer: DRAFT,
    soumise: SUBMITTED,
    soumis: SUBMITTED,
    en_validation: PENDING,
    a_valider: PENDING,
    retournee: RETURNED,
    retourne: RETURNED,
    a_corriger: RETURNED,
    complement: RETURNED,
    validee: { tone: 'info', icon: faCircleCheck },
    valide: DONE,
    en_approbation: { tone: 'violet', icon: faHourglassHalf },
    approuvee: DONE,
    rejetee: REJECTED,
    rejete: REJECTED,
    annulee: CANCELLED,
    annule: CANCELLED,
    transformee_engagement: TRANSFORMED,
    transforme_liquidation: TRANSFORMED,
    transformee_ordonnancement: TRANSFORMED,
    transforme_paiement: TRANSFORMED,
    en_instruction: PENDING,
    en_controle: SUBMITTED,
    a_controler: SUBMITTED,
    vise: DONE,
    generee: GENERATED,
    genere: GENERATED,
    en_preparation: PREPARING,
    a_signer: { tone: 'warning', icon: faSignature },
    signe: DONE,
    transmis: SUBMITTED,
    transmission_erreur: { tone: 'danger', icon: faTriangleExclamation },
    autorise: PENDING,
    paye_partiel: { tone: 'orange', icon: faHourglassHalf },
    a_rapprocher: { tone: 'violet', icon: faScaleBalanced },
    cloture: DONE,
    rejete_bancaire: REJECTED,
    suspendu: SUSPENDED,
    actif: DONE,
    bloque: { tone: 'danger', icon: faBan },
    archive: CANCELLED,
    projet: DRAFT,
    notifie: SUBMITTED,
    en_execution: PREPARING,
    clos: DONE,
    resilie: { tone: 'danger', icon: faBan },
    desactive: CANCELLED,
    en_attente: PENDING,
    verifie: { tone: 'info', icon: faCircleCheck },
    pris_en_charge: PREPARING,
    partiellement_encaisse: { tone: 'orange', icon: faHourglassHalf },
    solde: DONE,
    soldee: DONE,
    a_appeler: PENDING,
    appelee: SUBMITTED,
    partiellement_payee: { tone: 'orange', icon: faHourglassHalf },
    echue: { tone: 'danger', icon: faTriangleExclamation },
    en_retard: { tone: 'danger', icon: faTriangleExclamation },
    non_rapproche: { tone: 'violet', icon: faScaleBalanced },
    rapproche: DONE,
    anomalie: { tone: 'danger', icon: faTriangleExclamation },
    non_identifie: PENDING,
};

/** Statut inconnu : ton neutre, pastille sans icône (aucune sémantique supposée). */
export function statusStyle(statut: string | null | undefined): { tone: Tone; icon: IconDefinition | null } {
    return (statut && STATUS_STYLES[statut]) || { tone: 'neutral', icon: null };
}
