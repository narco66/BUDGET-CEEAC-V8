import { useEffect, useState } from 'react';

/** Valeur différée : évite un appel d’API à chaque frappe dans une recherche. */
export default function useDebouncedValue<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);

        return () => window.clearTimeout(timer);
    }, [value, delay]);

    return debounced;
}
