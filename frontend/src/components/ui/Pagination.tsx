import { faChevronLeft, faChevronRight } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

export type PageMeta = { current_page: number; last_page: number; total: number; per_page?: number; from?: number | null; to?: number | null };

function pages(current: number, last: number): Array<number | '…'> {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }
    const set = new Set([1, last, current, current - 1, current + 1]);
    const sorted = [...set].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
    const result: Array<number | '…'> = [];
    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            result.push('…');
        }
        result.push(page);
    });

    return result;
}

/** Pagination serveur : résumé, pages numérotées, précédent et suivant. */
export default function Pagination({ meta, onPage, noun = 'dossier' }: { meta?: PageMeta | null; onPage: (page: number) => void; noun?: string }) {
    if (!meta) {
        return null;
    }
    const { current_page: current, last_page: last, total } = meta;
    const range = meta.from && meta.to ? `${meta.from}–${meta.to} sur ${total}` : `${total}`;

    return (
        <nav className="pagination" aria-label="Pagination">
            <span>{range} {noun}{total > 1 ? 's' : ''}</span>
            {last > 1 && (
                <div className="pagination-pages">
                    <button type="button" className="page-btn" disabled={current <= 1} onClick={() => onPage(current - 1)} aria-label="Page précédente">
                        <FontAwesomeIcon icon={faChevronLeft} />
                    </button>
                    {pages(current, last).map((page, index) => (
                        page === '…'
                            ? <span key={`e${index}`} className="page-ellipsis">…</span>
                            : (
                                <button key={page} type="button" className="page-btn" aria-current={page === current ? 'page' : undefined} onClick={() => onPage(page)} aria-label={`Page ${page}`}>
                                    {page}
                                </button>
                            )
                    ))}
                    <button type="button" className="page-btn" disabled={current >= last} onClick={() => onPage(current + 1)} aria-label="Page suivante">
                        <FontAwesomeIcon icon={faChevronRight} />
                    </button>
                </div>
            )}
        </nav>
    );
}
