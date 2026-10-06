import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import { ICON } from './icons';

type ToastTone = 'success' | 'danger' | 'warning' | 'info';
type ToastItem = { id: number; tone: ToastTone; title?: string; message: ReactNode };
type ToastApi = {
    show: (message: ReactNode, options?: { tone?: ToastTone; title?: string; duration?: number }) => void;
    success: (message: ReactNode, title?: string) => void;
    error: (message: ReactNode, title?: string) => void;
    info: (message: ReactNode, title?: string) => void;
};

const ICONS: Record<ToastTone, IconDefinition> = { success: ICON.success, danger: ICON.danger, warning: ICON.warning, info: ICON.info };
const ToastContext = createContext<ToastApi | null>(null);

/** Retour visuel éphémère des actions (enregistrement, transmission, erreur). */
export function ToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<ToastItem[]>([]);
    const counter = useRef(0);

    const dismiss = useCallback((id: number) => setItems((current) => current.filter((item) => item.id !== id)), []);

    const show = useCallback<ToastApi['show']>((message, options = {}) => {
        counter.current += 1;
        const id = counter.current;
        const tone = options.tone ?? 'info';
        setItems((current) => [...current.slice(-3), { id, tone, title: options.title, message }]);
        window.setTimeout(() => dismiss(id), options.duration ?? (tone === 'danger' ? 7000 : 4500));
    }, [dismiss]);

    const api = useMemo<ToastApi>(() => ({
        show,
        success: (message, title) => show(message, { tone: 'success', title }),
        error: (message, title) => show(message, { tone: 'danger', title: title ?? 'Action impossible' }),
        info: (message, title) => show(message, { tone: 'info', title }),
    }), [show]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div className="toast-region" aria-live="polite" aria-relevant="additions">
                {items.map((item) => (
                    <div key={item.id} className={`toast tone-${item.tone}`} role={item.tone === 'danger' ? 'alert' : 'status'}>
                        <FontAwesomeIcon icon={ICONS[item.tone]} className="toast-icon" />
                        <div className="toast-body">
                            {item.title && <div className="toast-title">{item.title}</div>}
                            <div>{item.message}</div>
                        </div>
                        <button type="button" className="toast-close" onClick={() => dismiss(item.id)} aria-label="Fermer la notification">
                            <FontAwesomeIcon icon={ICON.close} />
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast doit être utilisé dans un ToastProvider.');
    }

    return context;
}
