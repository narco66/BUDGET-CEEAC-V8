import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { CSSProperties, ReactNode } from 'react';
import { ICON } from './icons';

/* ── États vides ─────────────────────────────────────────────────────── */

export function EmptyState({ icon = ICON.document, title, children, action, compact = false }: {
    icon?: IconDefinition;
    title: ReactNode;
    children?: ReactNode;
    action?: ReactNode;
    compact?: boolean;
}) {
    return (
        <div className={`empty-state${compact ? ' is-compact' : ''}`} role="status">
            <span className="empty-icon" aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>
            <span className="empty-title">{title}</span>
            {children && <span className="empty-text">{children}</span>}
            {action && <div className="empty-action">{action}</div>}
        </div>
    );
}

/* ── Alertes ─────────────────────────────────────────────────────────── */

export type AlertTone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const ALERT_ICONS: Record<AlertTone, IconDefinition> = {
    info: ICON.info,
    success: ICON.success,
    warning: ICON.warning,
    danger: ICON.danger,
    neutral: ICON.info,
};

export function Alert({ tone = 'info', title, children, icon, actions, onClose, strong = false, role, style }: {
    tone?: AlertTone;
    title?: ReactNode;
    children?: ReactNode;
    icon?: IconDefinition | null;
    actions?: ReactNode;
    onClose?: () => void;
    strong?: boolean;
    role?: 'alert' | 'status';
    style?: CSSProperties;
}) {
    return (
        <div className={`alert tone-${tone}${strong ? ' is-strong' : ''}`} role={role ?? (tone === 'danger' ? 'alert' : 'status')} style={style}>
            {icon !== null && <FontAwesomeIcon icon={icon ?? ALERT_ICONS[tone]} className="alert-icon" />}
            <div className="alert-body">
                {title && <span className="alert-title">{title}</span>}
                {children && <div className="alert-text">{children}</div>}
                {actions && <div className="alert-actions">{actions}</div>}
            </div>
            {onClose && (
                <button type="button" className="alert-close" onClick={onClose} aria-label="Fermer le message">
                    <FontAwesomeIcon icon={ICON.close} />
                </button>
            )}
        </div>
    );
}

/** Message d’erreur d’API, affiché seulement s’il existe. */
export function ErrorMessage({ error, title = 'Action impossible', onClose }: { error?: string | null; title?: string; onClose?: () => void }) {
    if (!error) {
        return null;
    }

    return <Alert tone="danger" title={title} onClose={onClose}>{error}</Alert>;
}

/* ── Chargement ──────────────────────────────────────────────────────── */

export function Skeleton({ width = '100%', height = 14, radius, style }: { width?: number | string; height?: number | string; radius?: number; style?: CSSProperties }) {
    return <span className="skeleton" aria-hidden="true" style={{ width, height, borderRadius: radius, ...style }} />;
}

export function Spinner({ label = 'Chargement…', size = 16 }: { label?: string; size?: number }) {
    return (
        <span role="status" style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: 'var(--color-text-muted)', fontSize: 'var(--text-sm)' }}>
            <span className="spinner" style={{ width: size, height: size }} aria-hidden="true" />
            {label}
        </span>
    );
}

export function TableSkeleton({ rows = 6, columns = 5 }: { rows?: number; columns?: number }) {
    return (
        <div aria-hidden="true" style={{ padding: '6px 16px 12px' }}>
            {Array.from({ length: rows }).map((_, row) => (
                <div key={row} style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`, gap: 18, padding: '13px 0', borderBottom: '1px solid var(--color-divider)' }}>
                    {Array.from({ length: columns }).map((__, column) => <Skeleton key={column} width={column === 0 ? '70%' : `${45 + ((row + column) % 4) * 12}%`} />)}
                </div>
            ))}
        </div>
    );
}

/** Squelette d’une page de liste ou de fiche, pendant le premier chargement. */
export function PageSkeleton({ variant = 'list' }: { variant?: 'list' | 'detail' }) {
    return (
        <main className="app-content" aria-busy="true" aria-label="Chargement de la page">
            <span className="sr-only" role="status">Chargement de la page…</span>
            <div className="stack-sm">
                <Skeleton width={140} height={12} />
                <Skeleton width={360} height={30} />
                <Skeleton width={520} height={12} />
            </div>
            <div className="grid-kpi">
                {Array.from({ length: variant === 'list' ? 4 : 4 }).map((_, index) => (
                    <div key={index} className="card" style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 10 }}>
                        <Skeleton width="50%" height={11} />
                        <Skeleton width="70%" height={24} />
                    </div>
                ))}
            </div>
            {variant === 'list' ? (
                <div className="card"><TableSkeleton /></div>
            ) : (
                <div className="liq-split">
                    <div className="card" style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
                        {Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} width={`${60 + (index % 3) * 13}%`} />)}
                    </div>
                    <div className="card" style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
                        {Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} />)}
                    </div>
                </div>
            )}
        </main>
    );
}

/** Erreur de chargement d’une page entière, avec relance. Un refus d’accès n’est pas une panne. */
export function PageError({ message, onRetry }: { message: string; onRetry?: () => void }) {
    const refus = /réservé|n’avez pas accès|n'avez pas accès/i.test(message);

    return (
        <main className="app-content">
            <div className="card">
                <EmptyState
                    icon={refus ? ICON.lock : ICON.warning}
                    title={refus ? 'Accès refusé' : 'Chargement impossible'}
                    action={!refus && onRetry && (
                        <button type="button" className="btn btn-secondary" onClick={onRetry}>
                            <FontAwesomeIcon icon={ICON.retry} />
                            <span>Réessayer</span>
                        </button>
                    )}
                >
                    {message}
                </EmptyState>
            </div>
        </main>
    );
}
