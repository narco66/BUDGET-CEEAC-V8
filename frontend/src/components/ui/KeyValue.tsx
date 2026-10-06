import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';

export type KeyValueItem = {
    label: ReactNode;
    value: ReactNode;
    strong?: boolean;
    mono?: boolean;
    warning?: boolean;
    hidden?: boolean;
};

function isEmpty(value: ReactNode): boolean {
    return value === null || value === undefined || value === '';
}

/** Liste libellé / valeur (fiches dossier). `compact` aligne la valeur à droite. */
export function KeyValueList({ items, compact = false, empty = '—' }: { items: KeyValueItem[]; compact?: boolean; empty?: ReactNode }) {
    return (
        <dl className={`kv${compact ? ' is-compact' : ''}`} style={{ margin: 0 }}>
            {items.filter((item) => !item.hidden).map((item, index) => (
                <div key={index} className="kv-row">
                    <dt className="kv-label">{item.label}</dt>
                    <dd className={['kv-value', item.strong && 'is-strong', item.mono && 'is-mono', item.warning && 'is-warning'].filter(Boolean).join(' ')} style={{ margin: 0 }}>
                        {isEmpty(item.value) ? empty : item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export type InfoItem = { label: ReactNode; value: ReactNode; hint?: ReactNode; icon?: IconDefinition; mono?: boolean; hidden?: boolean };

/** Bandeau d’informations clés en colonnes (étape, acteur, échéance…). */
export function InfoGrid({ items, label }: { items: InfoItem[]; label?: string }) {
    return (
        <section className="card info-grid" aria-label={label}>
            {items.filter((item) => !item.hidden).map((item, index) => (
                <div key={index} className="info-item">
                    <span className="info-label">{item.icon && <FontAwesomeIcon icon={item.icon} />}{item.label}</span>
                    <span className={`info-value${item.mono ? ' is-mono' : ''}`}>{isEmpty(item.value) ? '—' : item.value}</span>
                    {item.hint && <span className="info-hint">{item.hint}</span>}
                </div>
            ))}
        </section>
    );
}
