import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';
import { ICON } from './icons';

/** Bandeau de recherche et de filtres placé en tête d’un tableau. */
export default function FilterBar({ children, end, onReset, label = 'Filtres' }: {
    children: ReactNode;
    end?: ReactNode;
    onReset?: (() => void) | null;
    label?: string;
}) {
    return (
        <div className="filter-bar" role="search" aria-label={label}>
            {children}
            {(end || onReset) && (
                <div className="filter-bar-end">
                    {onReset && (
                        <button type="button" className="btn btn-ghost btn-sm" onClick={onReset}>
                            <FontAwesomeIcon icon={ICON.close} />
                            <span>Réinitialiser</span>
                        </button>
                    )}
                    {end}
                </div>
            )}
        </div>
    );
}

/**
 * Filtre « bouton » : libellé discret, valeur en gras et liste déroulante
 * native superposée (accessible au clavier et aux lecteurs d’écran).
 */
export function FilterSelect({ label, value, onChange, options, allLabel = 'Tous' }: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
    allLabel?: string;
}) {
    const current = options.find((option) => option.value === value)?.label ?? allLabel;

    return (
        <label className={`filter-select${value ? ' is-set' : ''}`}>
            <span className="filter-select-label">{label}</span>
            <span className="filter-select-value">{current}</span>
            <FontAwesomeIcon icon={ICON.filter} style={{ color: 'var(--slate-400)', fontSize: 10 }} />
            <select aria-label={label} value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">{allLabel}</option>
                {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
        </label>
    );
}
