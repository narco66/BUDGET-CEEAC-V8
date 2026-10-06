import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { MouseEvent } from 'react';
import { Badge, ICON } from '../../components/ui';
import { destination, type Notice } from './ouvrir';

const ICONES: Record<string, IconDefinition> = {
    expression_besoin: ICON.need,
    engagement: ICON.commitment,
    liquidation: ICON.settlement,
    ordonnancement: ICON.order,
    paiement: ICON.payment,
    tache: ICON.tasks,
    prevision: ICON.revenue,
    titre: ICON.revenue,
    ecart: ICON.gap,
    ecarts: ICON.gap,
    activite: ICON.monitoring,
    activite_gantt: ICON.gantt,
    indicateur: ICON.entry,
    saisie: ICON.entry,
    synthese: ICON.synthesis,
    rapports: ICON.report,
    rapprochements: ICON.reconciliation,
    ligne: ICON.budget,
    campagne: ICON.budget,
    dossier_budget: ICON.budget,
};

export function iconeNotice(notice: Notice): IconDefinition {
    if (notice.ouverture === 'refusee') {
        return ICON.warning;
    }
    if (notice.type && ICONES[notice.type]) {
        return ICONES[notice.type];
    }

    return notice.lue ? ICON.check : ICON.notifications;
}

export function NoticeLigne({ notice, onOpen, onToggle }: {
    notice: Notice;
    onOpen: (notice: Notice) => void;
    onToggle?: (notice: Notice) => void;
}) {
    function activer(event: MouseEvent<HTMLAnchorElement>) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }
        event.preventDefault();
        onOpen(notice);
    }

    return (
        <li className={`notice-line${notice.lue ? '' : ' is-unread'}`}>
            <a className="list-row" href={destination(notice)} onClick={activer}>
                <span className={`notice-mark tone-${notice.lue ? 'neutral' : 'info'}`} aria-hidden="true">
                    <FontAwesomeIcon icon={iconeNotice(notice)} />
                </span>
                <span className="list-row-main">
                    <span className="list-row-title">{notice.message}</span>
                    <span className="list-row-sub">
                        {notice.reference && <span className="mono">{notice.reference} · </span>}
                        {notice.date}
                        {notice.ouverture === 'detail' && ' · information'}
                    </span>
                </span>
                {!notice.lue && <Badge tone="info" size="sm" dot>Non lue</Badge>}
                {notice.ouverture === 'refusee' && <Badge tone="warning" size="sm" icon={ICON.warning}>Indisponible</Badge>}
                <FontAwesomeIcon icon={ICON.open} className="notice-go" />
            </a>
            {onToggle && (
                <button
                    type="button"
                    className="icon-button"
                    aria-label={notice.lue ? 'Marquer comme non lue' : 'Marquer comme lue'}
                    title={notice.lue ? 'Marquer comme non lue' : 'Marquer comme lue'}
                    onClick={() => onToggle(notice)}
                >
                    <FontAwesomeIcon icon={notice.lue ? ICON.pending : ICON.check} />
                </button>
            )}
        </li>
    );
}
