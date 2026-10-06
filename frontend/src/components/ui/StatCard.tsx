import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

export type StatTone = 'default' | 'success' | 'warning' | 'danger' | 'info' | 'orange' | 'neutral';

/**
 * Carte d’indicateur : libellé, valeur principale, information secondaire,
 * icône et état. Cliquable (filtre) avec `onClick`, navigable avec `to`.
 */
export default function StatCard({ label, value, unit, hint, icon, tone = 'default', dark = false, active = false, onClick, to, children }: {
    label: ReactNode;
    value: ReactNode;
    unit?: ReactNode;
    hint?: ReactNode;
    icon?: IconDefinition;
    tone?: StatTone;
    dark?: boolean;
    active?: boolean;
    onClick?: () => void;
    to?: string;
    children?: ReactNode;
}) {
    const interactive = Boolean(onClick || to);
    const className = ['stat-card', `tone-${tone}`, dark && 'is-dark', interactive && 'is-interactive', active && 'is-active'].filter(Boolean).join(' ');
    const body = (
        <>
            <div className="stat-head">
                <span className="stat-label">{label}</span>
                {icon && <span className="stat-icon" aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>}
            </div>
            <div className="stat-value">
                {value ?? '—'}
                {unit && <small>{unit}</small>}
            </div>
            {hint && <div className="stat-hint">{hint}</div>}
            {children}
        </>
    );

    if (to) {
        return <Link to={to} className={className}>{body}</Link>;
    }
    if (onClick) {
        return <button type="button" className={className} onClick={onClick} aria-pressed={active}>{body}</button>;
    }

    return <div className={className}>{body}</div>;
}

/** Cellule d’un bandeau d’indicateurs compact. */
export function StripCell({ label, value, hint, color, onClick, active, to, mono = false, valueColor }: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    color?: string;
    onClick?: () => void;
    active?: boolean;
    to?: string;
    mono?: boolean;
    valueColor?: string;
}) {
    const className = `strip-cell${active ? ' is-active' : ''}`;
    const body = (
        <>
            <span className="strip-label">
                {color && <span className="strip-dot" style={{ background: color }} aria-hidden="true" />}
                {label}
            </span>
            <span className={`strip-value${mono ? ' mono' : ''}`} style={valueColor ? { color: valueColor } : undefined}>{value ?? '—'}</span>
            {hint && <span className="strip-hint">{hint}</span>}
        </>
    );

    if (to) {
        return <Link to={to} className={className}>{body}</Link>;
    }
    if (onClick) {
        return <button type="button" className={className} onClick={onClick} aria-pressed={active}>{body}</button>;
    }

    return <div className={className}>{body}</div>;
}

export function StatStrip({ children, label }: { children: ReactNode; label?: string }) {
    return <section className="card stat-strip" aria-label={label}>{children}</section>;
}
