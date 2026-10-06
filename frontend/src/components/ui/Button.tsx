import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Link } from 'react-router-dom';

export type ButtonVariant = 'primary' | 'brand' | 'secondary' | 'success' | 'danger' | 'danger-outline' | 'warning' | 'neutral' | 'ghost' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

type CommonProps = {
    variant?: ButtonVariant;
    size?: ButtonSize;
    icon?: IconDefinition;
    iconRight?: IconDefinition;
    loading?: boolean;
    block?: boolean;
    /** Bouton icône seule : le libellé devient l’aria-label et l’info-bulle. */
    iconOnly?: boolean;
    className?: string;
    children?: ReactNode;
};

type ButtonProps = CommonProps & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & { to?: undefined; href?: undefined };
type LinkProps = CommonProps & { to: string; href?: undefined; title?: string; 'aria-label'?: string; onClick?: () => void; state?: unknown };
type AnchorProps = CommonProps & { href: string; to?: undefined; title?: string; 'aria-label'?: string; target?: string; rel?: string; download?: boolean | string };

export function buttonClass({ variant = 'secondary', size = 'md', block, iconOnly, loading, className }: CommonProps): string {
    return ['btn', `btn-${variant}`, size !== 'md' && `btn-${size}`, block && 'btn-block', iconOnly && 'btn-icon', loading && 'is-loading', className]
        .filter(Boolean)
        .join(' ');
}

function Content({ icon, iconRight, loading, iconOnly, children }: CommonProps) {
    return (
        <>
            {loading && <span className="spinner" aria-hidden="true" />}
            {icon && <FontAwesomeIcon icon={icon} />}
            {iconOnly ? <span className="sr-only">{children}</span> : children !== undefined && children !== null && <span>{children}</span>}
            {iconRight && <FontAwesomeIcon icon={iconRight} />}
        </>
    );
}

/**
 * Bouton du Design System. Rend un <Link> avec `to`, un <a> avec `href`,
 * sinon un <button type="button">.
 */
export default function Button(props: ButtonProps | LinkProps | AnchorProps) {
    const { variant, size, icon, iconRight, loading, block, iconOnly, className, children, ...rest } = props as CommonProps & Record<string, any>;
    const classes = buttonClass({ variant, size, block, iconOnly, loading, className });
    const label = iconOnly && typeof children === 'string' ? children : undefined;
    const content = <Content icon={icon} iconRight={iconRight} loading={loading} iconOnly={iconOnly}>{children}</Content>;

    if ('to' in props && props.to !== undefined) {
        const { to, ...linkRest } = rest;
        return <Link to={to} className={classes} title={label} aria-label={label} {...linkRest}>{content}</Link>;
    }
    if ('href' in props && props.href !== undefined) {
        return <a className={classes} title={label} aria-label={label} {...rest}>{content}</a>;
    }

    const { type = 'button', disabled, ...buttonRest } = rest;
    return (
        <button
            type={type}
            className={classes}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            title={label}
            aria-label={label}
            {...buttonRest}
        >
            {content}
        </button>
    );
}
