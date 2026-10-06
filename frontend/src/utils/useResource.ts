import { useCallback, useEffect, useRef, useState } from 'react';
import { errorsOf } from './format';

/**
 * Chargement d’une ressource d’API : données, état de chargement, erreur
 * lisible et relance. Ignore les réponses obsolètes (changement de filtres).
 */
export default function useResource<T>(loader: () => Promise<T>, deps: unknown[]) {
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const ticket = useRef(0);

    const reload = useCallback(() => {
        ticket.current += 1;
        const current = ticket.current;
        setLoading(true);
        setError(null);

        return loader()
            .then((result) => {
                if (current === ticket.current) setData(result);
            })
            .catch((caught) => {
                if (current === ticket.current) setError(errorsOf(caught));
            })
            .finally(() => {
                if (current === ticket.current) setLoading(false);
            });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    useEffect(() => {
        reload();
    }, [reload]);

    return { data, setData, loading, error, reload };
}
