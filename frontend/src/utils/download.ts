import api from '../api/httpClient';

/** Téléchargement authentifié : cookie de session et en-tête d’acteur. */
export async function telecharger(path: string, filename: string, params?: Record<string, string>): Promise<void> {
    const response = await api.get(path, { params, responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
