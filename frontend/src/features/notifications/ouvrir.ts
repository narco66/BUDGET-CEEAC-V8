import api from '../../api/httpClient';

export type Cible = {
    type: string;
    id: number | null;
    chemin: string | null;
    accessible: boolean;
    motif: string | null;
    liste: string | null;
};

export type Notice = {
    id: string;
    message: string;
    reference: string;
    module: string | null;
    type: string | null;
    lue: boolean;
    date: string | null;
    lue_le?: string | null;
    ouverture: 'dossier' | 'detail' | 'refusee';
    cible: Cible | null;
};

export function destination(notice: Notice): string {
    if (notice.ouverture === 'dossier' && notice.cible?.chemin) {
        return notice.cible.chemin;
    }

    return `/notifications/${notice.id}`;
}

export async function ouvrirNotification(id: string): Promise<{ chemin: string | null; non_lues: number }> {
    const response = await api.post(`/notifications/${id}/ouvrir`);
    window.dispatchEvent(new CustomEvent('notifications:maj', { detail: response.data.non_lues }));
    try {
        localStorage.setItem('gesbudep.notifications', String(Date.now()));
    } catch {
        /* Synchronisation entre onglets non disponible. */
    }

    return response.data;
}

export async function basculerLecture(notice: Notice): Promise<number> {
    const response = await api.post(`/notifications/${notice.id}/${notice.lue ? 'non-lue' : 'lire'}`);
    window.dispatchEvent(new CustomEvent('notifications:maj', { detail: response.data.non_lues }));

    return response.data.non_lues as number;
}
