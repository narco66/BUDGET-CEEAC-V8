import { faChevronRight } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Fragment } from 'react';
import { Link } from 'react-router-dom';

export type ChainStage = 'EB' | 'ENG' | 'LIQ' | 'ORD' | 'PAY';

const MODULES: Record<ChainStage, { label: string; to: string }> = {
    EB: { label: 'Expression de besoin', to: '/expressions-besoin' },
    ENG: { label: 'Engagement', to: '/engagements' },
    LIQ: { label: 'Liquidation', to: '/liquidations' },
    ORD: { label: 'Ordonnancement', to: '/ordonnancements' },
    PAY: { label: 'Paiement', to: '/paiements' },
};

const ORDER: ChainStage[] = ['EB', 'ENG', 'LIQ', 'ORD', 'PAY'];

/**
 * Frise de la chaîne de dépense.
 * - Sans `links` : navigation entre modules, le module courant est mis en avant.
 * - Avec `links` : références du dossier, chaque maillon ouvre la pièce liée.
 */
export default function ChainTrail({ current, links }: {
    current: ChainStage;
    links?: Partial<Record<ChainStage, { reference?: string | null; to?: string | null; pending?: string }>>;
}) {
    const currentIndex = ORDER.indexOf(current);

    return (
        <nav className="chain" aria-label="Chaîne de la dépense">
            {ORDER.map((stage, index) => {
                const isCurrent = stage === current;
                const link = links?.[stage];
                const label = links ? (link?.reference || link?.pending || 'à venir') : MODULES[stage].label;
                const target = links ? link?.to : MODULES[stage].to;
                const pending = links ? !link?.reference : false;
                const className = ['chain-link', isCurrent && 'is-current', !isCurrent && index < currentIndex && 'is-done', pending && !isCurrent && 'is-pending'].filter(Boolean).join(' ');
                const body = (
                    <>
                        <span className="chain-code">{stage}</span>
                        <span className={links ? 'mono' : undefined}>{label}</span>
                    </>
                );

                return (
                    <Fragment key={stage}>
                        {index > 0 && <FontAwesomeIcon icon={faChevronRight} className="chain-sep" aria-hidden="true" />}
                        {isCurrent || !target || pending
                            ? <span className={className} aria-current={isCurrent ? 'page' : undefined}>{body}</span>
                            : <Link className={className} to={target} title={`${MODULES[stage].label}${links ? ` ${label}` : ''}`}>{body}</Link>}
                    </Fragment>
                );
            })}
        </nav>
    );
}
