import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useRef, type KeyboardEvent, type ReactNode } from 'react';

export type TabItem = { value: string; label: ReactNode; count?: number | null; icon?: IconDefinition; hidden?: boolean };

/**
 * Onglets accessibles (rôle tablist, navigation au clavier par flèches,
 * Début et Fin). Utilisés pour les vues de statut et les fiches dossiers.
 */
export default function Tabs({ items, value, onChange, label, className, inCard = false }: {
    items: TabItem[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    className?: string;
    inCard?: boolean;
}) {
    const list = useRef<HTMLDivElement>(null);
    const visible = items.filter((item) => !item.hidden);

    function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        const index = visible.findIndex((item) => item.value === value);
        let target = -1;
        if (event.key === 'ArrowRight') target = (index + 1) % visible.length;
        if (event.key === 'ArrowLeft') target = (index - 1 + visible.length) % visible.length;
        if (event.key === 'Home') target = 0;
        if (event.key === 'End') target = visible.length - 1;
        if (target < 0) return;
        event.preventDefault();
        onChange(visible[target].value);
        list.current?.querySelectorAll<HTMLButtonElement>('[role=tab]')[target]?.focus();
    }

    return (
        <div ref={list} role="tablist" aria-label={label} className={['tabs', inCard && 'tabs-in-card', className].filter(Boolean).join(' ')} onKeyDown={onKeyDown}>
            {visible.map((item) => {
                const selected = item.value === value;

                return (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        className="tab"
                        aria-selected={selected}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(item.value)}
                    >
                        {item.icon && <FontAwesomeIcon icon={item.icon} />}
                        {item.label}
                        {item.count !== undefined && item.count !== null && <span className="tab-count">{item.count}</span>}
                    </button>
                );
            })}
        </div>
    );
}

/** Contrôle segmenté (choix exclusif court : PAP / Hors PAP, échelle…). */
export function Segmented({ items, value, onChange, label }: {
    items: Array<{ value: string; label: ReactNode; icon?: IconDefinition }>;
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    return (
        <div className="seg" role="group" aria-label={label}>
            {items.map((item) => (
                <button key={item.value} type="button" className="seg-item" aria-pressed={item.value === value} onClick={() => onChange(item.value)}>
                    {item.icon && <FontAwesomeIcon icon={item.icon} />}
                    {item.label}
                </button>
            ))}
        </div>
    );
}
