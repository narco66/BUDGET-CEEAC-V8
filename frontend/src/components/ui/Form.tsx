import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { cloneElement, isValidElement, useId, useRef, useState, type CSSProperties, type InputHTMLAttributes, type ReactElement, type ReactNode } from 'react';
import { ICON } from './icons';

/**
 * Champ de formulaire : libellé, indicateur obligatoire, aide contextuelle et
 * message d’erreur reliés au contrôle (id, aria-describedby, aria-invalid).
 */
export function FormField({ label, required = false, optional = false, hint, error, className, style, children }: {
    label: ReactNode;
    required?: boolean;
    optional?: boolean;
    hint?: ReactNode;
    error?: ReactNode;
    className?: string;
    style?: CSSProperties;
    children: ReactElement<any>;
}) {
    const generated = useId();
    const id = (isValidElement(children) && (children.props as any).id) || generated;
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const control = isValidElement(children)
        ? cloneElement(children as ReactElement<Record<string, unknown>>, {
            id,
            'aria-describedby': [hintId, errorId].filter(Boolean).join(' ') || undefined,
            'aria-invalid': error ? true : undefined,
            required: (children.props as any).required ?? (required || undefined),
        })
        : children;

    return (
        <div className={['field', className].filter(Boolean).join(' ')} style={style}>
            <label className="field-label" htmlFor={id}>
                {label}
                {required && <span className="field-required" aria-hidden="true">*</span>}
                {optional && <span className="field-optional">(facultatif)</span>}
            </label>
            {control}
            {hint && !error && <span className="field-hint" id={hintId}>{hint}</span>}
            {error && <span className="field-error" id={errorId}><FontAwesomeIcon icon={ICON.danger} />{error}</span>}
        </div>
    );
}

/** Champ de recherche avec icône et bouton d’effacement. */
export function SearchInput({ value, onChange, placeholder = 'Rechercher…', label, className, autoFocus }: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    label?: string;
    className?: string;
    autoFocus?: boolean;
}) {
    return (
        <div className={['input-group', 'has-icon', 'search-input', className].filter(Boolean).join(' ')}>
            <FontAwesomeIcon icon={ICON.search} className="input-icon" />
            <input
                className="inp inp-sm"
                type="search"
                value={value}
                placeholder={placeholder}
                aria-label={label ?? placeholder}
                onChange={(event) => onChange(event.target.value)}
                autoFocus={autoFocus}
                style={{ paddingRight: value ? 34 : undefined }}
            />
            {value && (
                <button type="button" className="input-clear" onClick={() => onChange('')} aria-label="Effacer la recherche">
                    <FontAwesomeIcon icon={ICON.close} />
                </button>
            )}
        </div>
    );
}

/** Champ avec unité (FCFA, %, j…) ou icône de tête. */
export function InputGroup({ unit, icon, children }: { unit?: ReactNode; icon?: IconDefinition; children: ReactElement<any> }) {
    return (
        <div className={['input-group', icon && 'has-icon', unit && 'has-suffix'].filter(Boolean).join(' ')}>
            {icon && <FontAwesomeIcon icon={icon} className="input-icon" />}
            {children}
            {unit && <span className="input-suffix">{unit}</span>}
        </div>
    );
}

/** Montant en FCFA : saisie numérique, chiffres alignés, unité affichée. */
export function AmountInput({ value, onChange, unit = 'FCFA', ...rest }: Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> & {
    value: string | number;
    onChange: (value: string) => void;
    unit?: ReactNode;
}) {
    return (
        <InputGroup unit={unit}>
            <input
                className="inp num align-right"
                inputMode="decimal"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                {...rest}
            />
        </InputGroup>
    );
}

/** Dépôt de fichier : zone cliquable, nom et taille du fichier retenu. */
export function FileDrop({ file, onFile, accept, hint, label = 'Choisir un fichier', disabled = false }: {
    file: File | null;
    onFile: (file: File | null) => void;
    accept?: string;
    hint?: ReactNode;
    label?: string;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    return (
        <div
            className={`dropzone${dragging ? ' is-dragging' : ''}`}
            onDragOver={(event) => { event.preventDefault(); if (!disabled) setDragging(true); }}
            onDragLeave={() => setDragging(false)}
            onDrop={(event) => {
                event.preventDefault();
                setDragging(false);
                if (!disabled) onFile(event.dataTransfer.files?.[0] ?? null);
            }}
        >
            <span className="dropzone-icon" aria-hidden="true"><FontAwesomeIcon icon={file ? ICON.attachment : ICON.upload} /></span>
            <div className="dropzone-text">
                <span className="dropzone-title">{file ? file.name : 'Aucun fichier sélectionné'}</span>
                <span className="dropzone-hint">{file ? `${Math.max(1, Math.round(file.size / 1024))} Ko` : hint ?? 'Glissez un fichier ici ou parcourez votre poste.'}</span>
            </div>
            <input ref={input} type="file" hidden accept={accept} disabled={disabled} onChange={(event) => onFile(event.target.files?.[0] ?? null)} />
            {file && !disabled && (
                <button type="button" className="btn btn-ghost btn-sm btn-icon" onClick={() => { onFile(null); if (input.current) input.current.value = ''; }} aria-label="Retirer le fichier" title="Retirer le fichier">
                    <FontAwesomeIcon icon={ICON.close} />
                </button>
            )}
            <button type="button" className="btn btn-secondary btn-sm" disabled={disabled} onClick={() => input.current?.click()}>
                <FontAwesomeIcon icon={ICON.upload} />
                <span>{file ? 'Remplacer' : label}</span>
            </button>
        </div>
    );
}

/** Case à cocher présentée en carte (choix multiples lisibles). */
export function CheckCard({ checked, onChange, children, hint, tone = 'default', disabled }: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
    hint?: ReactNode;
    tone?: 'default' | 'warning';
    disabled?: boolean;
}) {
    return (
        <label className={['choice', checked && 'is-checked', tone === 'warning' && 'is-warning'].filter(Boolean).join(' ')}>
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} />
            <span className="stack-sm" style={{ gap: 2 }}>
                <span>{children}</span>
                {hint && <span className="field-hint">{hint}</span>}
            </span>
        </label>
    );
}

/** Choix multiple lisible : recherche, compteur et cases à cocher en carte (remplace le select multiple natif). */
export function MultiCheck<T extends string | number>({ options, value, onChange, id, searchPlaceholder = 'Filtrer la liste…', maxHeight = 260 }: {
    options: { value: T; label: ReactNode; text?: string; hint?: ReactNode }[];
    value: T[];
    onChange: (value: T[]) => void;
    id?: string;
    searchPlaceholder?: string;
    maxHeight?: number;
}) {
    const [filtre, setFiltre] = useState('');
    const terme = filtre.trim().toLowerCase();
    const visibles = terme
        ? options.filter((option) => (option.text ?? String(option.label)).toLowerCase().includes(terme))
        : options;
    const tous = visibles.length > 0 && visibles.every((option) => value.includes(option.value));

    function basculer(cible: T, coche: boolean) {
        onChange(coche ? [...value, cible] : value.filter((courant) => courant !== cible));
    }

    function basculerVisibles() {
        const cibles = visibles.map((option) => option.value);
        onChange(tous ? value.filter((courant) => !cibles.includes(courant)) : Array.from(new Set([...value, ...cibles])));
    }

    return (
        <div className="stack-sm" id={id}>
            <div className="cluster" style={{ justifyContent: 'space-between' }}>
                {options.length > 8 && <SearchInput value={filtre} onChange={setFiltre} placeholder={searchPlaceholder} className="multicheck-search" />}
                <span className="cluster" style={{ marginLeft: 'auto' }}>
                    <span className="subtle">{value.length} / {options.length} sélectionnée(s)</span>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={basculerVisibles} disabled={visibles.length === 0}>
                        {tous ? 'Tout désélectionner' : 'Tout sélectionner'}
                    </button>
                </span>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 8, maxHeight, overflowY: 'auto', paddingRight: 2 }}>
                {visibles.map((option) => (
                    <CheckCard key={option.value} checked={value.includes(option.value)} onChange={(coche) => basculer(option.value, coche)} hint={option.hint}>
                        {option.label}
                    </CheckCard>
                ))}
                {visibles.length === 0 && <span className="subtle">Aucun élément ne correspond.</span>}
            </div>
        </div>
    );
}
