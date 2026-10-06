import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { EmptyState, TableSkeleton } from './Feedback';
import { ICON } from './icons';

export type Column<T> = {
    key: string;
    header: ReactNode;
    render: (row: T, index: number) => ReactNode;
    align?: 'left' | 'right' | 'center';
    width?: number | string;
    className?: string;
    /** Masque l’en-tête visuellement (colonne d’actions). */
    srHeader?: boolean;
};

/**
 * Tableau de données : en-tête collant, alignements, survol de ligne,
 * squelette de chargement, état vide et état d’erreur intégrés.
 */
export default function DataTable<T>({ columns, rows, rowKey, loading = false, error, empty, onRowClick, rowLabel, footer, compact = false, caption, minWidth, rowClassName }: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T, index: number) => string | number;
    loading?: boolean;
    error?: string | null;
    empty?: ReactNode;
    onRowClick?: (row: T) => void;
    rowLabel?: (row: T) => string;
    footer?: ReactNode;
    compact?: boolean;
    caption?: string;
    minWidth?: number;
    rowClassName?: (row: T) => string | undefined;
}) {
    const showSkeleton = loading && rows.length === 0;
    const style: CSSProperties | undefined = minWidth ? { minWidth } : undefined;

    function onKeyDown(event: KeyboardEvent<HTMLTableRowElement>, row: T) {
        // Une touche frappée sur un contrôle de la ligne (menu, bouton) ne doit pas ouvrir la ligne.
        if (onRowClick && event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            onRowClick(row);
        }
    }

    return (
        <div className="table-wrap" aria-busy={loading || undefined}>
            <table className={`tbl${compact ? ' is-compact' : ''}`} style={style}>
                {caption && <caption className="sr-only">{caption}</caption>}
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.key} className={column.align === 'right' ? 'r' : column.align === 'center' ? 'c' : undefined} style={column.width ? { width: column.width } : undefined} scope="col">
                                {column.srHeader ? <span className="sr-only">{column.header}</span> : column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody style={loading && rows.length > 0 ? { opacity: .55, transition: 'opacity 160ms' } : undefined}>
                    {showSkeleton && (
                        <tr><td colSpan={columns.length} className="is-empty"><TableSkeleton columns={Math.min(columns.length, 6)} rows={5} /></td></tr>
                    )}
                    {!showSkeleton && error && (
                        <tr>
                            <td colSpan={columns.length} className="is-empty">
                                <EmptyState icon={ICON.warning} title="Chargement impossible" compact>{error}</EmptyState>
                            </td>
                        </tr>
                    )}
                    {!showSkeleton && !error && rows.length === 0 && (
                        <tr><td colSpan={columns.length} className="is-empty">{empty ?? <EmptyState title="Aucun résultat" compact />}</td></tr>
                    )}
                    {!showSkeleton && !error && rows.map((row, index) => (
                        <tr
                            key={rowKey(row, index)}
                            className={[onRowClick && 'is-clickable', rowClassName?.(row)].filter(Boolean).join(' ') || undefined}
                            onClick={onRowClick ? () => onRowClick(row) : undefined}
                            onKeyDown={onRowClick ? (event) => onKeyDown(event, row) : undefined}
                            tabIndex={onRowClick ? 0 : undefined}
                            aria-label={onRowClick && rowLabel ? rowLabel(row) : undefined}
                        >
                            {columns.map((column) => (
                                <td key={column.key} className={[column.align === 'right' ? 'r' : column.align === 'center' ? 'c' : undefined, column.className].filter(Boolean).join(' ') || undefined}>
                                    {column.render(row, index)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
                {footer}
            </table>
        </div>
    );
}

/** Lien d’ouverture discret pour la dernière colonne d’un tableau. */
export function OpenCell({ children = 'Ouvrir' }: { children?: ReactNode }) {
    return (
        <span className="subtle" title={typeof children === 'string' ? children : undefined} aria-hidden="true" style={{ display: 'inline-grid', placeItems: 'center', width: 28, height: 28 }}>
            <FontAwesomeIcon icon={ICON.open} />
        </span>
    );
}
