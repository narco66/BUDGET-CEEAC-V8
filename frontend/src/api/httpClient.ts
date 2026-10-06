import axios from 'axios';

const ACTOR_KEY = 'actor_id';

export const http = axios.create({
    baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

http.interceptors.request.use((config) => {
    const actorId = sessionStorage.getItem(ACTOR_KEY);
    if (actorId) {
        config.headers['X-Actor-Id'] = actorId;
    }

    return config;
});

http.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        if ((status === 401 || status === 419) && !window.location.pathname.startsWith('/connexion')) {
            forgetActor();
            const next = encodeURIComponent(window.location.pathname + window.location.search);
            window.location.assign(`/connexion?suite=${next}`);
        }

        return Promise.reject(error);
    },
);

/**
 * Récupère le cookie XSRF de Sanctum avant la connexion SPA.
 */
export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/**
 * Acteur de démonstration : pris en compte par l’API uniquement lorsque le
 * mode démonstration est actif côté serveur.
 */
export function rememberActor(id: number | string): void {
    sessionStorage.setItem(ACTOR_KEY, String(id));
}

export function forgetActor(): void {
    sessionStorage.removeItem(ACTOR_KEY);
}

export default http;
