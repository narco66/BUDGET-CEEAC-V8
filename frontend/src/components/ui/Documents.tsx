import { faFile, faFileCircleExclamation, faFilePdf } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { EmptyState } from './Feedback';
import { ICON } from './icons';

export type DocumentEntry = { name: ReactNode; meta?: ReactNode; missing?: boolean; action?: ReactNode };

/** Liste de pièces justificatives (présentes ou attendues). */
export default function DocumentList({ items, emptyTitle = 'Aucune pièce', emptyText }: { items: DocumentEntry[]; emptyTitle?: string; emptyText?: ReactNode }) {
    if (items.length === 0) {
        return <EmptyState icon={ICON.attachment} title={emptyTitle} compact>{emptyText}</EmptyState>;
    }

    return (
        <ul className="doc-list">
            {items.map((item, index) => {
                const name = String(item.name ?? '');
                const icon = item.missing ? faFileCircleExclamation : /\.pdf$/i.test(name) ? faFilePdf : faFile;

                return (
                    <li key={index} className={`doc-item${item.missing ? ' is-missing' : ''}`}>
                        <span className="file-icon" aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>
                        <div className="doc-item-text">
                            <span className="doc-item-name">{item.name}</span>
                            {item.meta && <span className="doc-item-meta">{item.meta}</span>}
                        </div>
                        {item.action}
                    </li>
                );
            })}
        </ul>
    );
}
