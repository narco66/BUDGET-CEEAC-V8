import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ICON } from './icons';

export type MenuAction = {
    label: string;
    icon?: IconDefinition;
    onSelect?: () => void;
    to?: string;
    href?: string;
    danger?: boolean;
    disabled?: boolean;
    hidden?: boolean;
    separatorBefore?: boolean;
};

/** Ferme un élément flottant au clic extérieur et sur Échap. */
export function useDismiss<T extends HTMLElement>(open: boolean, onClose: () => void) {
    const ref = useRef<T>(null);

    useEffect(() => {
        if (!open) return undefined;
        function onPointer(event: MouseEvent) {
            if (ref.current && !ref.current.contains(event.target as Node)) onClose();
        }
        function onKey(event: globalThis.KeyboardEvent) {
            if (event.key === 'Escape') onClose();
        }
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    return ref;
}

/**
 * Menu « trois points » : regroupe les actions secondaires d’une ligne ou
 * d’une fiche au lieu d’accumuler des boutons colorés.
 */
export default function ActionMenu({ actions, label = 'Plus d’actions', trigger, align = 'right' }: {
    actions: MenuAction[];
    label?: string;
    trigger?: ReactNode;
    align?: 'left' | 'right';
}) {
    const [open, setOpen] = useState(false);
    const ref = useDismiss<HTMLDivElement>(open, () => setOpen(false));
    const visible = actions.filter((action) => !action.hidden);

    useEffect(() => {
        if (open) ref.current?.querySelector<HTMLElement>('[role=menuitem]:not(:disabled)')?.focus();
    }, [open]);

    if (visible.length === 0) {
        return null;
    }

    function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        event.preventDefault();
        const items = [...(ref.current?.querySelectorAll<HTMLElement>('[role=menuitem]:not(:disabled)') ?? [])];
        const index = items.indexOf(document.activeElement as HTMLElement);
        const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
        items[next]?.focus();
    }

    function select(action: MenuAction) {
        setOpen(false);
        action.onSelect?.();
    }

    return (
        <div className="menu-anchor" ref={ref} onClick={(event) => event.stopPropagation()} onKeyDown={onKeyDown}>
            {trigger ? (
                <span onClick={() => setOpen(!open)}>{trigger}</span>
            ) : (
                <button type="button" className="btn btn-ghost btn-sm btn-icon" aria-haspopup="menu" aria-expanded={open} aria-label={label} title={label} onClick={() => setOpen(!open)} style={{ color: 'var(--slate-600)' }}>
                    <FontAwesomeIcon icon={ICON.more} />
                </button>
            )}
            {open && (
                <div className={`menu${align === 'left' ? ' align-left' : ''}`} role="menu" aria-label={label}>
                    {visible.map((action) => {
                        const className = `menu-item${action.danger ? ' is-danger' : ''}`;
                        const content = (
                            <>
                                {action.icon ? <FontAwesomeIcon icon={action.icon} fixedWidth /> : <span style={{ width: 14 }} />}
                                {action.label}
                            </>
                        );

                        return (
                            <div key={action.label}>
                                {action.separatorBefore && <div className="menu-separator" role="separator" />}
                                {action.to ? (
                                    <Link role="menuitem" className={className} to={action.to} onClick={() => setOpen(false)}>{content}</Link>
                                ) : action.href ? (
                                    <a role="menuitem" className={className} href={action.href} onClick={() => setOpen(false)}>{content}</a>
                                ) : (
                                    <button role="menuitem" type="button" className={className} disabled={action.disabled} onClick={() => select(action)}>{content}</button>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
