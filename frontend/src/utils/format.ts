export function fcfa(value) {
    return new Intl.NumberFormat('fr-FR').format(value ?? 0);
}

/** Montant abrégé pour les graphiques et indicateurs : « 560,6 M », « 42,5 Md ». */
export function fcfaCompact(value: number | null | undefined): string {
    return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 2 }).format(value ?? 0);
}

/** « 2026-10-05 » ou « 2026-10-05 22:01:06 » → « 05/10/2026 ». */
export function dateFr(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const [jour] = value.split(/[ T]/);
    const [annee, mois, j] = jour.split('-');
    return annee && mois && j ? `${j}/${mois}/${annee}` : value;
}

/** « 2026-10-05 22:01:06 » → « 05/10/2026 à 22:01 ». */
export function dateHeure(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const heure = value.split(/[ T]/)[1]?.slice(0, 5);
    return heure ? `${dateFr(value)} à ${heure}` : dateFr(value);
}

const ACCENTS: Record<string, string> = {
    cree: 'créé', creee: 'créée', creer: 'créer', verifie: 'vérifié', verifiee: 'vérifiée', valide: 'validé', validee: 'validée',
    rejete: 'rejeté', rejetee: 'rejetée', annule: 'annulé', annulee: 'annulée', retourne: 'retourné', retournee: 'retournée',
    enregistre: 'enregistré', affecte: 'affecté', rapproche: 'rapproché', encaisse: 'encaissé', solde: 'soldé', echeance: 'échéance',
    prevision: 'prévision', depose: 'déposé', generer: 'générer', genere: 'généré', reference: 'référence', debiteur: 'débiteur',
    categorie: 'catégorie', rattache: 'rattaché', modifie: 'modifié', supprime: 'supprimé', resilie: 'résilié', notifie: 'notifié', decision: 'décision', oeuvre: 'œuvre', activite: 'activité', mesure: 'mesure', telecharger: 'télécharger', cloture: 'clôture', cloturee: 'clôturée', regularise: 'régularisé',
};

/** « titre_cree » ou « ged.deposer » → « Titre créé », « Ged · deposer ». */
export function libelleCode(code: string | null | undefined): string {
    if (!code) {
        return '—';
    }
    const texte = code
        .split('.')
        .map((part) => part.split('_').map((mot) => ACCENTS[mot] ?? mot).join(' '))
        .join(' · ');
    return texte.charAt(0).toUpperCase() + texte.slice(1);
}

/** Résumé lisible d’un objet de détails : « Montant : 1 500 000 FCFA · Statut : soldé ». */
export function resumeDetails(details: unknown): string | undefined {
    if (details === null || details === undefined || details === '') {
        return undefined;
    }
    if (typeof details !== 'object') {
        return String(details);
    }
    const parties = Object.entries(details as Record<string, unknown>)
        .filter(([, valeur]) => valeur !== null && valeur !== undefined && valeur !== '' && typeof valeur !== 'object')
        .map(([cle, valeur]) => {
            const affichee = typeof valeur === 'number' && /montant|solde|encaisse|constate|reste|paye/i.test(cle)
                ? `${fcfa(valeur)} FCFA`
                : typeof valeur === 'string' && /^\d{4}-\d{2}-\d{2}/.test(valeur) ? dateFr(valeur) : libelleCode(String(valeur)).toLowerCase();
            return `${libelleCode(cle)} : ${affichee}`;
        });
    return parties.length ? parties.join(' · ') : undefined;
}

export function percent(value: number | null | undefined, digits = 1): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: digits }).format(value ?? 0)} %`;
}

export function errorsOf(error) {
    const status = error?.response?.status;
    const message = error?.response?.data?.message;
    if (status === 403) {
        if (message && !/^(forbidden|unauthorized|this action is unauthorized\.?)$/i.test(String(message).trim())) {
            return message;
        }

        return 'Vous n’avez pas accès à cet écran.';
    }

    const bag = error?.response?.data?.errors;
    if (!bag) {
        return message || 'Une erreur est survenue.';
    }

    return Object.values(bag).flat().join(' ');
}

