import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faArrowLeft,
    faArrowRight,
    faArrowTrendUp,
    faBarsStaggered,
    faBell,
    faCheck,
    faChevronDown,
    faChevronRight,
    faCircleCheck,
    faCircleQuestion,
    faClipboardList,
    faClock,
    faComment,
    faDownload,
    faEye,
    faFileLines,
    faFloppyDisk,
    faHourglassEnd,
    faLock,
    faPaperPlane,
    faPenToSquare,
    faTriangleExclamation,
    faBullseye,
    faUpload,
    faUsers,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { CSSProperties, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import Pagination from '../../../components/ui/Pagination';
import { StripCell as UiStripCell } from '../../../components/ui/StatCard';

export { FilterSelect } from '../../../components/ui/FilterBar';

/**
 * Composants des écrans Suivi-Évaluation (docs/maquette-SE), alignés sur le
 * Design System. Aucune donnée ici : les valeurs viennent toujours de l’API.
 */

const ICONS: Record<string, IconDefinition> = {
    chevron: faChevronRight,
    chevronDown: faChevronDown,
    back: faArrowLeft,
    clock: faClock,
    retard: faHourglassEnd,
    gantt: faBarsStaggered,
    download: faDownload,
    upload: faUpload,
    alert: faTriangleExclamation,
    checkCircle: faCircleCheck,
    check: faCheck,
    trend: faArrowTrendUp,
    eye: faEye,
    help: faCircleQuestion,
    bell: faBell,
    tasks: faClipboardList,
    pen: faPenToSquare,
    comment: faComment,
    target: faBullseye,
    save: faFloppyDisk,
    send: faPaperPlane,
    users: faUsers,
    file: faFileLines,
    arrow: faArrowRight,
    lock: faLock,
};

/** Icône nommée du module (Font Awesome). Conserve l’API historique `name`. */
export function Icon({ name, size = 14, color, style }: { name: keyof typeof ICONS | string; size?: number; color?: string; style?: CSSProperties }) {
    const icon = ICONS[name];
    if (!icon) {
        return null;
    }

    return <FontAwesomeIcon icon={icon} style={{ fontSize: size, color, flexShrink: 0, ...style }} />;
}

export type Tone = 'vert' | 'bleu' | 'ambre' | 'orange' | 'rouge' | 'gris' | 'pap' | 'marine';

/** Couleurs des tons, lues dans les jetons du Design System. */
export const TONES: Record<Tone, { bg: string; fg: string; dot: string }> = {
    vert: { bg: 'var(--success-bg)', fg: 'var(--success-fg)', dot: 'var(--success-solid)' },
    bleu: { bg: 'var(--info-bg)', fg: 'var(--info-fg)', dot: 'var(--info-fg)' },
    ambre: { bg: 'var(--warning-bg)', fg: 'var(--warning-fg)', dot: 'var(--warning-solid)' },
    orange: { bg: 'var(--orange-bg)', fg: 'var(--orange-fg)', dot: 'var(--orange-solid)' },
    rouge: { bg: 'var(--danger-bg)', fg: 'var(--danger-fg)', dot: 'var(--danger-solid)' },
    gris: { bg: 'var(--neutral-bg)', fg: 'var(--neutral-fg)', dot: 'var(--slate-400)' },
    pap: { bg: 'var(--green-100)', fg: 'var(--green-800)', dot: 'var(--green-600)' },
    marine: { bg: 'var(--navy-100)', fg: 'var(--navy-700)', dot: 'var(--navy-700)' },
};

/** Statuts de performance (description S&E §20) : libellé, ton et icône — jamais la couleur seule. */
export const PERFORMANCE: Record<string, { label: string; tone: Tone; icon: string }> = {
    atteint: { label: 'Atteint', tone: 'vert', icon: 'checkCircle' },
    en_bonne_voie: { label: 'En bonne voie', tone: 'bleu', icon: 'trend' },
    a_surveiller: { label: 'À surveiller', tone: 'ambre', icon: 'eye' },
    en_retard: { label: 'En retard', tone: 'orange', icon: 'retard' },
    critique: { label: 'Critique', tone: 'rouge', icon: 'alert' },
    non_renseigne: { label: 'Non renseigné', tone: 'gris', icon: 'help' },
    rejetee: { label: 'Rejetée', tone: 'gris', icon: 'help' },
};

export const ACTIVITY_STATUS: Record<string, { label: string; tone: Tone }> = {
    non_demarree: { label: 'Non démarrée', tone: 'gris' },
    planifiee: { label: 'Planifiée', tone: 'gris' },
    en_cours: { label: 'En cours', tone: 'bleu' },
    en_retard: { label: 'En retard', tone: 'orange' },
    suspendue: { label: 'Suspendue', tone: 'ambre' },
    bloquee: { label: 'Bloquée', tone: 'rouge' },
    realisee: { label: 'Réalisée', tone: 'vert' },
    cloturee: { label: 'Clôturée', tone: 'vert' },
    annulee: { label: 'Annulée', tone: 'gris' },
};

export const GAP_LEVEL: Record<string, { label: string; bg: string; fg: string; tone: Tone; icon: string }> = {
    normal: { label: 'Normal', bg: 'var(--green-50)', fg: 'var(--success-fg)', tone: 'vert', icon: 'checkCircle' },
    a_surveiller: { label: 'À surveiller', bg: 'var(--warning-bg)', fg: 'var(--warning-fg)', tone: 'ambre', icon: 'eye' },
    critique: { label: 'Critique', bg: 'var(--danger-bg)', fg: 'var(--danger-fg)', tone: 'rouge', icon: 'alert' },
};

export const HEAT: Record<string, { tone: Tone; icon: string }> = {
    conforme: { tone: 'vert', icon: 'check' },
    a_surveiller: { tone: 'ambre', icon: 'eye' },
    critique: { tone: 'rouge', icon: 'alert' },
    non_renseigne: { tone: 'gris', icon: 'help' },
};

export const APPRECIATION: Record<string, string> = {
    excellent: 'Excellent',
    satisfaisant: 'Satisfaisant',
    a_ameliorer: 'À améliorer',
    insuffisant: 'Insuffisant',
    critique: 'Critique',
    non_renseigne: 'Non renseigné',
};

export const APPRECIATION_COLOR: Record<string, string> = {
    excellent: 'var(--success-fg)',
    satisfaisant: 'var(--green-600)',
    a_ameliorer: 'var(--warning-fg)',
    insuffisant: 'var(--danger-fg)',
    critique: 'var(--danger-fg)',
    non_renseigne: 'var(--slate-600)',
};

/** Pastille arrondie (statuts d’indicateurs, de tâches, de jalons). */
export function Pill({ tone, icon, children, size = 'sm', dot = false }: { tone: Tone; icon?: string; children: ReactNode; size?: 'sm' | 'md'; dot?: boolean }) {
    return (
        <span className={`badge tone-${tone}${size === 'sm' ? ' badge-sm' : ''}`}>
            {dot && !icon && <span className="badge-dot" aria-hidden="true" />}
            {icon && <Icon name={icon} size={size === 'sm' ? 10 : 11} />}
            {children}
        </span>
    );
}

export function PerformancePill({ status }: { status: string | null | undefined }) {
    const meta = PERFORMANCE[status ?? 'non_renseigne'] ?? PERFORMANCE.non_renseigne;

    return <Pill tone={meta.tone} icon={meta.icon}>{meta.label}</Pill>;
}

/** Étiquette de provenance (CHAÎNE DE DÉPENSE, RÉFÉRENTIEL, SUIVI-ÉVAL.). */
export function Tag({ kind }: { kind: 'chaine' | 'referentiel' | 'suivi' }) {
    const label = { chaine: 'CHAÎNE DE DÉPENSE', referentiel: 'RÉFÉRENTIEL', suivi: 'SUIVI-ÉVAL.' }[kind];

    return <span className={`tag tag-${kind}`}>{label}</span>;
}

export function CardHead({ title, tag, right, padded = false, id }: { title: ReactNode; tag?: ReactNode; right?: ReactNode; padded?: boolean; id?: string }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', padding: padded ? '14px 20px 0' : undefined }}>
            <h2 id={id} className="card-title">{title}</h2>
            {tag}
            <span style={{ marginLeft: 'auto' }}>{right}</span>
        </div>
    );
}

export function Card({ children, label, padded = true, style }: { children: ReactNode; label?: string; padded?: boolean; style?: CSSProperties }) {
    return (
        <section className="card" aria-label={label} style={{ padding: padded ? '16px 20px' : undefined, overflow: 'hidden', display: 'flex', flexDirection: 'column', gap: 12, ...style }}>
            {children}
        </section>
    );
}

/** Ligne « libellé · barre · valeur » (exécution physique / financière). */
export function BarRow({ label, value, color }: { label: string; value: number | null | undefined; color: string }) {
    const width = Math.max(0, Math.min(100, Number(value ?? 0)));

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(120px, 190px) minmax(0, 1fr) 56px', alignItems: 'center', gap: 12 }}>
            <span style={{ fontSize: 'var(--text-sm)', fontWeight: 600 }}>{label}</span>
            <div className="progress is-thick" role="progressbar" aria-label={label} aria-valuenow={Math.round(width)} aria-valuemin={0} aria-valuemax={100} style={{ height: 14, borderRadius: 4 }}>
                <span style={{ width: `${width}%`, background: color, borderRadius: 4 }} />
            </div>
            <span className="mono" style={{ fontSize: 'var(--text-base)', fontWeight: 700, textAlign: 'right' }}>{pct(value)}</span>
        </div>
    );
}

/** Barre fine d’avancement (tableaux de tâches et d’indicateurs). */
export function MiniBar({ value, color = 'var(--green-600)', width = 38 }: { value: number | null | undefined; color?: string; width?: number }) {
    const fill = Math.max(0, Math.min(100, Number(value ?? 0)));

    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <div className="progress is-thin" aria-hidden="true" style={{ flexGrow: 1 }}>
                <span style={{ width: `${fill}%`, background: color }} />
            </div>
            <span className="mono" style={{ fontSize: 'var(--text-xs)', width, textAlign: 'right' }}>{value === null || value === undefined ? '—' : pct(value)}</span>
        </div>
    );
}

export function Donut({ value, color, label }: { value: number | null | undefined; color: string; label: string }) {
    const fill = Math.max(0, Math.min(100, Number(value ?? 0)));

    return (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6 }} role="img" aria-label={`${label} : ${pct(value, 0)}`}>
            <div aria-hidden="true" style={{ width: 104, height: 104, borderRadius: 99, background: `conic-gradient(${color} 0 ${fill}%, var(--slate-150) ${fill}% 100%)`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <div style={{ width: 84, height: 84, borderRadius: 99, background: 'var(--color-surface)', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: 'inset 0 0 0 1px var(--color-divider)' }}>
                    <span className="mono" style={{ fontSize: 21, fontWeight: 700, color: 'var(--color-text-strong)' }}>{pct(value, 0)}</span>
                </div>
            </div>
            <span style={{ fontSize: 'var(--text-xs)', fontWeight: 600, color: 'var(--slate-700)' }}>{label}</span>
        </div>
    );
}

/** Encadré « Écart N points · niveau · sens » avec les seuils paramétrés. */
export function GapBox({ gap, level, thresholds }: { gap: number; level: string; thresholds?: { surveiller: number; critique: number } }) {
    const meta = GAP_LEVEL[level] ?? GAP_LEVEL.normal;
    const watch = thresholds?.surveiller ?? 10;
    const critical = thresholds?.critique ?? 20;

    return (
        <div role="status" style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 'var(--radius-md)', background: meta.bg, color: meta.fg, fontSize: 'var(--text-sm)', flexWrap: 'wrap' }}>
            <Icon name={meta.icon} size={15} />
            <span>
                <b>Écart {fmt(Math.abs(gap), 0)} points · {meta.label}</b> · {gap >= 0 ? 'Physique > financier' : 'Financier > physique'}
            </span>
            <span style={{ marginLeft: 'auto', fontSize: 'var(--text-2xs)', opacity: .85 }}>
                seuils : ≤ {fmt(watch, 0)} normal · {fmt(watch, 0)}–{fmt(critical, 0)} à surveiller · &gt; {fmt(critical, 0)} critique
            </span>
        </div>
    );
}

/** Tuile d’un bandeau d’indicateurs (« Activités suivies 126 · PAP 98 · Hors PAP 28 »). */
export function StatTile({ label, value, hint, color, onClick, active, to }: { label: string; value: ReactNode; hint?: ReactNode; color?: string; onClick?: () => void; active?: boolean; to?: string }) {
    return <UiStripCell label={label} value={value} hint={hint} color={color} onClick={onClick} active={active} to={to} />;
}

export function Pager({ meta, onPage }: { meta?: { current_page: number; last_page: number; total: number } | null; onPage: (page: number) => void }) {
    return <Pagination meta={meta} onPage={onPage} noun="élément" />;
}

/** Cellule de bandeau à six colonnes (fiche activité 360°). */
export function StripCell({ label, value, hint, color = 'var(--color-text)', mono = true }: { label: string; value: ReactNode; hint?: ReactNode; color?: string; mono?: boolean }) {
    return <UiStripCell label={label} value={value} hint={hint} mono={mono} valueColor={color} />;
}

export function Breadcrumb({ items }: { items: Array<{ label: string; to?: string }> }) {
    return (
        <nav aria-label="Fil d’Ariane" className="breadcrumbs">
            {items.map((item, index) => (
                <span key={item.label} style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    {index > 0 && <Icon name="chevron" size={10} color="var(--slate-300)" />}
                    {item.to ? <Link to={item.to}>{item.label}</Link> : <span className="crumb-current">{item.label}</span>}
                </span>
            ))}
        </nav>
    );
}

export function pct(value: number | string | null | undefined, digits = 1): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${fmt(Number(value), digits)} %`;
}

export function fmt(value: number | null | undefined, digits = 1): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: digits }).format(value);
}

export function money(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : new Intl.NumberFormat('fr-FR').format(value);
}

export function dayMonth(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }
    const [year, month, day] = date.slice(0, 10).split('-');

    return year ? `${day}/${month}` : '—';
}

export function fullDate(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }
    const [year, month, day] = date.slice(0, 10).split('-');

    return `${day}/${month}/${year}`;
}

/** Échéance relative « J-3 », « J+2 · retard ». */
export function relativeDay(date: string | null | undefined): { label: string; late: boolean } | null {
    if (!date) {
        return null;
    }
    const target = new Date(`${date.slice(0, 10)}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((target.getTime() - today.getTime()) / 86400000);

    return days >= 0 ? { label: days === 0 ? 'J' : `J-${days}`, late: false } : { label: `J+${-days}`, late: true };
}

export function errorMessage(error: any, fallback = 'Action refusée.'): string {
    const details = error?.response?.data?.errors;

    return details ? Object.values(details).flat().join(' ') : error?.response?.data?.message ?? fallback;
}
