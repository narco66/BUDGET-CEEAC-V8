import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { CSSProperties, ReactNode } from 'react';

export type CardTone = 'default' | 'warning' | 'danger' | 'info' | 'success' | 'brand';

/**
 * Carte de section : en-tête (icône, titre, sous-titre, étiquette, actions),
 * corps et pied facultatif. `flush` retire la marge interne (tableaux).
 */
export default function SectionCard({ title, subtitle, icon, tag, actions, footer, flush = false, tone = 'default', plainHeader = false, id, className, style, children }: {
    title?: ReactNode;
    subtitle?: ReactNode;
    icon?: IconDefinition;
    tag?: ReactNode;
    actions?: ReactNode;
    footer?: ReactNode;
    flush?: boolean;
    tone?: CardTone;
    plainHeader?: boolean;
    id?: string;
    className?: string;
    style?: CSSProperties;
    children?: ReactNode;
}) {
    const headingId = id ? `${id}-title` : undefined;

    return (
        <section
            id={id}
            className={['card', 'section-card', tone !== 'default' && `card-tone-${tone}`, className].filter(Boolean).join(' ')}
            aria-labelledby={title ? headingId : undefined}
            style={style}
        >
            {(title || actions) && (
                <div className={`card-header${plainHeader ? ' plain' : ''}`}>
                    {icon && <span className="card-title-icon" aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>}
                    <div style={{ minWidth: 0 }}>
                        {title && (
                            <h2 className="card-title" id={headingId}>
                                {title}
                                {tag}
                            </h2>
                        )}
                        {subtitle && <div className="card-subtitle">{subtitle}</div>}
                    </div>
                    {actions && <div className="card-actions">{actions}</div>}
                </div>
            )}
            {children !== undefined && children !== null && children !== false && <div className={`card-body${flush ? ' flush' : ''}`}>{children}</div>}
            {footer && <div className="card-footer">{footer}</div>}
        </section>
    );
}
