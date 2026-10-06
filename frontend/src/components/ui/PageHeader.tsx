import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ICON } from './icons';

export function BackLink({ to, children }: { to: string; children: ReactNode }) {
    return (
        <Link to={to} className="back-link">
            <FontAwesomeIcon icon={ICON.back} />
            {children}
        </Link>
    );
}

/**
 * En-tête de page : retour éventuel, sur-titre (référence, statut…), titre,
 * sous-titre, méta-données, chiffre clé et actions.
 */
export default function PageHeader({ title, subtitle, eyebrow, back, meta, actions, figure, children }: {
    title: ReactNode;
    subtitle?: ReactNode;
    eyebrow?: ReactNode;
    back?: { to: string; label: string };
    meta?: ReactNode;
    actions?: ReactNode;
    figure?: { label: ReactNode; value: ReactNode; unit?: ReactNode; hint?: ReactNode };
    children?: ReactNode;
}) {
    return (
        <header className="page-header">
            <div className="page-header-main">
                {back && <BackLink to={back.to}>{back.label}</BackLink>}
                {eyebrow && <div className="page-eyebrow">{eyebrow}</div>}
                <h1 className="page-title">{title}</h1>
                {subtitle && <p className="page-subtitle">{subtitle}</p>}
                {meta && <div className="page-meta">{meta}</div>}
                {children}
            </div>
            {(figure || actions) && (
                <div className="stack-sm" style={{ alignItems: 'flex-end' }}>
                    {figure && (
                        <div className="page-header-figure">
                            <span className="lbl">{figure.label}</span>
                            <span>
                                <span className="figure-value">{figure.value}</span>
                                {figure.unit && <span className="figure-unit">{figure.unit}</span>}
                            </span>
                            {figure.hint && <span className="subtle" style={{ maxWidth: 320 }}>{figure.hint}</span>}
                        </div>
                    )}
                    {actions && <div className="page-actions">{actions}</div>}
                </div>
            )}
        </header>
    );
}
