import type { ReactNode } from 'react';

function clamp(value: number | null | undefined): number {
    return Math.max(0, Math.min(100, Number(value ?? 0) || 0));
}

/** Barre de progression simple, avec libellé accessible. */
export function ProgressBar({ value, color, label, size = 'md' }: { value: number | null | undefined; color?: string; label: string; size?: 'thin' | 'md' | 'thick' }) {
    const width = clamp(value);

    return (
        <div className={`progress${size === 'thin' ? ' is-thin' : size === 'thick' ? ' is-thick' : ''}`} role="progressbar" aria-label={label} aria-valuenow={Math.round(width)} aria-valuemin={0} aria-valuemax={100}>
            <span style={{ width: `${width}%`, background: color }} />
        </div>
    );
}

/** Jauge : libellé, montant ou valeur, barre et pourcentage. */
export function Meter({ label, value, ratio, color, hint }: { label: ReactNode; value: ReactNode; ratio: number | null | undefined; color?: string; hint?: ReactNode }) {
    return (
        <div className="meter">
            <div className="meter-head">
                <span className="lbl">{label}</span>
                <span className="subtle num">{ratio === null || ratio === undefined ? '—' : `${Math.round(clamp(ratio) * 10) / 10} %`}</span>
            </div>
            <span className="meter-value">{value}</span>
            <ProgressBar value={ratio} color={color} label={String(label)} size="thin" />
            {hint && <span className="subtle">{hint}</span>}
        </div>
    );
}

/** Barre empilée (répartition) avec légende. */
export function StackedBar({ segments, label }: { segments: Array<{ label: string; value: number; color: string }>; label: string }) {
    const total = segments.reduce((sum, segment) => sum + Math.max(0, segment.value), 0);

    return (
        <div className="stack-sm">
            <div className="progress is-thick" role="img" aria-label={`${label} : ${segments.map((segment) => `${segment.label} ${total ? Math.round((segment.value / total) * 100) : 0} %`).join(', ')}`}>
                {segments.map((segment) => (
                    <span key={segment.label} title={segment.label} style={{ width: `${total ? (Math.max(0, segment.value) / total) * 100 : 0}%`, background: segment.color }} />
                ))}
            </div>
            <div className="legend">
                {segments.map((segment) => (
                    <span key={segment.label} className="legend-item"><span className="legend-swatch" style={{ background: segment.color }} />{segment.label}</span>
                ))}
            </div>
        </div>
    );
}
