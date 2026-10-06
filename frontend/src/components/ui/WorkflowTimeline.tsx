import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faArrowRightArrowLeft, faBan, faCircleCheck, faCircleXmark, faGear, faPaperPlane, faPenToSquare, faPlus, faRotateLeft, faSignature, faStamp, faUser } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { EmptyState } from './Feedback';
import { ICON } from './icons';

export type TimelineEvent = {
    action: ReactNode;
    actor?: ReactNode;
    date?: ReactNode;
    note?: ReactNode;
    detail?: ReactNode;
    system?: boolean;
};

/** Icône et ton déduits du libellé d’action (le libellé reste celui de l’API). */
function visual(action: unknown, system?: boolean): { icon: IconDefinition; tone: string } {
    const text = String(action ?? '').toLowerCase();
    if (/rejet|refus/.test(text)) return { icon: faCircleXmark, tone: 'danger' };
    if (/retour|complément|complement|correction/.test(text)) return { icon: faRotateLeft, tone: 'warning' };
    if (/annul|désactiv|desactiv|suspen/.test(text)) return { icon: faBan, tone: 'neutral' };
    if (/vis[ae]/.test(text)) return { icon: faStamp, tone: 'success' };
    if (/sign/.test(text)) return { icon: faSignature, tone: 'success' };
    if (/valid|approuv|certifi|clôtur|clotur|rapproch|pay|exécut|execut/.test(text)) return { icon: faCircleCheck, tone: 'success' };
    if (/soum|transmi|envoi/.test(text)) return { icon: faPaperPlane, tone: 'default' };
    if (/transform|génér|gener/.test(text)) return { icon: faArrowRightArrowLeft, tone: 'default' };
    if (/cré|cree|ouvert|nouvel/.test(text)) return { icon: faPlus, tone: 'default' };
    if (/modif|mise à jour|mettre|enregistr/.test(text)) return { icon: faPenToSquare, tone: 'default' };
    if (system) return { icon: faGear, tone: 'neutral' };

    return { icon: faUser, tone: 'default' };
}

/** Chronologie d’un dossier : action, acteur, date, motif et observations. */
export default function WorkflowTimeline({ events, emptyText = 'Aucun événement enregistré.' }: { events: TimelineEvent[]; emptyText?: string }) {
    if (events.length === 0) {
        return <EmptyState icon={ICON.history} title="Historique vide" compact>{emptyText}</EmptyState>;
    }

    return (
        <ol className="timeline">
            {events.map((event, index) => {
                const { icon, tone } = visual(event.action, event.system);

                return (
                    <li key={index} className="timeline-item">
                        <span className={`timeline-dot tone-${tone}`} aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>
                        <div className="timeline-content">
                            <div className="timeline-title">{event.action}</div>
                            <div className="timeline-meta">
                                {[event.actor ?? (event.system ? 'Système' : null), event.date].filter(Boolean).map((part, position) => (
                                    <span key={position}>{position > 0 && ' · '}{part}</span>
                                ))}
                            </div>
                            {event.detail && <div className="timeline-meta">{event.detail}</div>}
                            {event.note && <div className="timeline-note">{event.note}</div>}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
