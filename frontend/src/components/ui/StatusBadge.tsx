import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { statusStyle, type Tone } from './status';

export function Badge({ tone = 'neutral', icon, dot = false, size = 'md', outline = false, title, children }: {
    tone?: Tone | string;
    icon?: IconDefinition | null;
    dot?: boolean;
    size?: 'sm' | 'md';
    outline?: boolean;
    title?: string;
    children: ReactNode;
}) {
    return (
        <span className={['badge', `tone-${tone}`, size === 'sm' && 'badge-sm', outline && 'is-outline'].filter(Boolean).join(' ')} title={title}>
            {icon ? <FontAwesomeIcon icon={icon} /> : dot && <span className="badge-dot" aria-hidden="true" />}
            {children}
        </span>
    );
}

/** Statut de workflow : libellé de l’API, ton et icône déduits du code de statut. */
export default function StatusBadge({ statut, libelle, size }: { statut: string | null | undefined; libelle?: ReactNode; size?: 'sm' | 'md' }) {
    const style = statusStyle(statut);

    return (
        <Badge tone={style.tone} icon={style.icon} dot={!style.icon} size={size}>
            {libelle ?? statut ?? '—'}
        </Badge>
    );
}

/** Nature de la dépense : PAP ou Hors PAP. */
export function NatureBadge({ nature, libelle }: { nature: string | null | undefined; libelle?: ReactNode }) {
    if (!nature && !libelle) {
        return null;
    }

    return <Badge tone={nature === 'pap' ? 'pap' : 'brand'}>{libelle ?? (nature === 'pap' ? 'PAP' : 'Hors PAP')}</Badge>;
}
