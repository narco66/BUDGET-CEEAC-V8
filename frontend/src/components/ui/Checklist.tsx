import { faCircleCheck, faCircleXmark, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';

export type CheckItem = { label: ReactNode; ok: boolean; detail?: ReactNode; side?: ReactNode; blocking?: boolean };

/**
 * Liste de contrôles : conforme / à revoir, avec icône et libellé
 * (jamais la couleur seule). Un point non bloquant est signalé en ambre.
 */
export default function Checklist({ items }: { items: CheckItem[] }) {
    return (
        <ul className="checklist">
            {items.map((item, index) => {
                const state = item.ok ? 'ok' : item.blocking === false ? 'warn' : 'ko';
                const icon = state === 'ok' ? faCircleCheck : state === 'warn' ? faTriangleExclamation : faCircleXmark;
                const status = state === 'ok' ? 'Conforme' : state === 'warn' ? 'Non bloquant' : 'À revoir';

                return (
                    <li key={index} className={`checklist-item is-${state}`}>
                        <FontAwesomeIcon icon={icon} className="checklist-icon" aria-hidden="true" />
                        <div>
                            <div className="checklist-label">{item.label}</div>
                            {item.detail && <div className="checklist-detail">{item.detail}</div>}
                        </div>
                        <span className="checklist-side">{item.side ?? status}</span>
                    </li>
                );
            })}
        </ul>
    );
}

/** Résumé « n / total conformes » sous forme de pastille. */
export function ChecklistSummary({ items }: { items: CheckItem[] }) {
    const ok = items.filter((item) => item.ok).length;
    const complete = ok === items.length;

    return (
        <span className={`badge tone-${complete ? 'success' : 'warning'}`}>
            <FontAwesomeIcon icon={complete ? faCircleCheck : faTriangleExclamation} />
            {ok} / {items.length} conformes
        </span>
    );
}
