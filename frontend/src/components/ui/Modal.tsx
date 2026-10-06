import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ICON } from './icons';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Piège le focus dans la boîte, ferme sur Échap et rend le focus à l’ouverture. */
function useDialogBehaviour(onClose: () => void, dismissible: boolean) {
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const node = box.current;
        const first = node?.querySelector<HTMLElement>('[data-autofocus]') ?? node?.querySelector<HTMLElement>(FOCUSABLE);
        first?.focus();
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape' && dismissible) {
                event.stopPropagation();
                onClose();
            }
            if (event.key === 'Tab' && node) {
                const items = [...node.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((item) => item.offsetParent !== null);
                if (items.length === 0) return;
                const head = items[0];
                const tail = items[items.length - 1];
                if (event.shiftKey && document.activeElement === head) { event.preventDefault(); tail.focus(); }
                if (!event.shiftKey && document.activeElement === tail) { event.preventDefault(); head.focus(); }
            }
        }

        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            previous?.focus?.();
        };
    }, []);

    return box;
}

type DialogProps = {
    title: ReactNode;
    description?: ReactNode;
    icon?: IconDefinition;
    tone?: 'default' | 'danger' | 'warning' | 'success';
    onClose: () => void;
    footer?: ReactNode;
    children?: ReactNode;
    dismissible?: boolean;
};

/** Boîte de dialogue modale : titre, contexte, fermeture claire, actions en pied. */
export default function Modal({ title, description, icon, tone = 'default', onClose, footer, children, size = 'md', dismissible = true }: DialogProps & { size?: 'sm' | 'md' | 'lg' | 'xl' }) {
    const titleId = useId();
    const descriptionId = useId();
    const box = useDialogBehaviour(onClose, dismissible);

    return createPortal(
        <div className="overlay" onMouseDown={(event) => { if (event.target === event.currentTarget && dismissible) onClose(); }}>
            <div ref={box} className={`modal size-${size}`} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined}>
                <DialogHeader title={title} description={description} icon={icon} tone={tone} onClose={onClose} titleId={titleId} descriptionId={descriptionId} dismissible={dismissible} />
                {children && <div className="modal-body">{children}</div>}
                {footer && <div className="modal-footer">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}

/** Panneau latéral : consultation d’une fiche sans quitter la liste. */
export function Drawer({ title, description, icon, onClose, footer, children, width = 520 }: DialogProps & { width?: number }) {
    const titleId = useId();
    const descriptionId = useId();
    const box = useDialogBehaviour(onClose, true);

    return createPortal(
        <div className="overlay drawer-overlay" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
            <div ref={box} className="drawer" style={{ ['--drawer-w' as string]: `${width}px` }} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined}>
                <DialogHeader title={title} description={description} icon={icon} onClose={onClose} titleId={titleId} descriptionId={descriptionId} dismissible />
                <div className="modal-body">{children}</div>
                {footer && <div className="modal-footer">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}

function DialogHeader({ title, description, icon, tone = 'default', onClose, titleId, descriptionId, dismissible }: {
    title: ReactNode;
    description?: ReactNode;
    icon?: IconDefinition;
    tone?: string;
    onClose: () => void;
    titleId: string;
    descriptionId: string;
    dismissible: boolean;
}) {
    return (
        <div className="modal-header">
            {icon && <span className={`modal-header-icon tone-${tone}`} aria-hidden="true"><FontAwesomeIcon icon={icon} /></span>}
            <div style={{ minWidth: 0 }}>
                <h2 className="modal-title" id={titleId}>{title}</h2>
                {description && <div className="modal-description" id={descriptionId}>{description}</div>}
            </div>
            {dismissible && (
                <button type="button" className="modal-close" onClick={onClose} aria-label="Fermer">
                    <FontAwesomeIcon icon={ICON.close} />
                </button>
            )}
        </div>
    );
}
