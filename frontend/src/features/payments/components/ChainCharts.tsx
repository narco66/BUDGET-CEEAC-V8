import { faArrowDown } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, ICON } from '../../../components/ui';
import { fcfa, fcfaCompact, percent } from '../../../utils/format';

/*
 * Graphiques du tableau de chaîne. Palettes validées (contraste ≥ 3:1,
 * séparation daltonisme ΔE ≥ 9) : voir docs/audit-refonte-ui-budget-ceeac.md.
 */
export const CHAIN_COLORS = {
    funnel: '#3B5FA8',
    enCours: '#A8841C',
    aboutis: '#3B5FA8',
    ecartes: '#B65A7C',
    engage: '#3B5FA8',
    paye: '#A8841C',
};

type Tip = { x: number; y: number; content: ReactNode } | null;

/** Infobulle positionnée dans le conteneur relatif du graphique. */
function useTooltip() {
    const box = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<Tip>(null);

    function show(target: Element, content: ReactNode) {
        const frame = box.current?.getBoundingClientRect();
        const rect = target.getBoundingClientRect();
        if (!frame) return;
        setTip({ x: rect.left - frame.left + rect.width / 2, y: rect.top - frame.top, content });
    }

    const tooltip = tip && (
        <div className="chart-tooltip" style={{ left: tip.x, top: tip.y }} role="status">{tip.content}</div>
    );

    return { box, show, hide: () => setTip(null), tooltip };
}

function TipRow({ color, line = false, label, value }: { color?: string; line?: boolean; label: ReactNode; value: ReactNode }) {
    return (
        <div className="chart-tooltip-row">
            <span className="chart-tooltip-key">
                {color && <span className={line ? 'chart-line-key' : 'chart-swatch'} style={{ background: color }} />}
                {label}
            </span>
            <span className="chart-tooltip-value">{value}</span>
        </div>
    );
}

/* ── Entonnoir d’exécution : de l’engagé au payé ─────────────────────── */

export type Execution = { engage: number; liquide: number; ordonnance: number; paye: number; reste_a_liquider: number; reste_a_ordonnancer: number; reste_a_payer: number };

export function ExecutionFunnel({ execution }: { execution: Execution }) {
    const { box, show, hide, tooltip } = useTooltip();
    const base = execution.engage;
    const steps = [
        { key: 'engage', label: 'Engagé', value: execution.engage, gap: { label: 'Reste à liquider', value: execution.reste_a_liquider } },
        { key: 'liquide', label: 'Liquidé', value: execution.liquide, gap: { label: 'Reste à ordonnancer', value: execution.reste_a_ordonnancer } },
        { key: 'ordonnance', label: 'Ordonnancé', value: execution.ordonnance, gap: { label: 'Reste à payer', value: execution.reste_a_payer } },
        { key: 'paye', label: 'Payé', value: execution.paye, gap: null },
    ];

    if (base <= 0) {
        return <p className="muted">Aucun engagement sur l’exercice : l’entonnoir s’affichera dès le premier engagement.</p>;
    }

    return (
        <div className="chart" ref={box}>
            <div className="funnel" role="list" aria-label="Exécution de l’engagé au payé">
                {steps.map((step) => {
                    const share = (step.value / base) * 100;
                    const content = (
                        <>
                            <div className="chart-tooltip-title">{step.label}</div>
                            <TipRow color={CHAIN_COLORS.funnel} label="Montant" value={`${fcfa(step.value)} FCFA`} />
                            <TipRow label="Part de l’engagé" value={percent(share)} />
                        </>
                    );

                    return (
                        <div key={step.key} role="listitem">
                            <div className="funnel-row">
                                <span className="funnel-label">{step.label}</span>
                                <div className="funnel-track">
                                    <span
                                        className="funnel-bar"
                                        tabIndex={0}
                                        aria-label={`${step.label} : ${fcfa(step.value)} FCFA, ${percent(share)} de l’engagé`}
                                        style={{ width: `${Math.max(0.5, Math.min(100, share)) * 0.82}%`, background: CHAIN_COLORS.funnel }}
                                        onMouseEnter={(event) => show(event.currentTarget, content)}
                                        onMouseLeave={hide}
                                        onFocus={(event) => show(event.currentTarget, content)}
                                        onBlur={hide}
                                    />
                                    <span className="funnel-value"><b>{fcfaCompact(step.value)}</b> · {percent(share, 0)}</span>
                                </div>
                            </div>
                            {step.gap && (
                                <div className="funnel-gap">
                                    <span />
                                    <span><FontAwesomeIcon icon={faArrowDown} style={{ fontSize: 9 }} />{step.gap.label} : <b className="mono">{fcfaCompact(step.gap.value)}</b></span>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
            {tooltip}
        </div>
    );
}

/* ── Flux des dossiers par maillon ───────────────────────────────────── */

export type Stage = {
    code: string; libelle: string; lien: string; total: number; montant: number;
    en_cours: number; montant_en_cours: number; aboutis: number; montant_aboutis: number; ecartes: number; en_retard: number;
};

const SEGMENTS = [
    { key: 'en_cours', label: 'En cours', color: CHAIN_COLORS.enCours, amount: 'montant_en_cours' },
    { key: 'aboutis', label: 'Aboutis', color: CHAIN_COLORS.aboutis, amount: 'montant_aboutis' },
    { key: 'ecartes', label: 'Rejetés ou annulés', color: CHAIN_COLORS.ecartes, amount: null },
] as const;

export function StageFlow({ stages }: { stages: Stage[] }) {
    const { box, show, hide, tooltip } = useTooltip();

    return (
        <div className="chart stack" ref={box}>
            <div className="legend" aria-hidden="true">
                {SEGMENTS.map((segment) => (
                    <span key={segment.key} className="legend-item"><span className="legend-swatch" style={{ background: segment.color }} />{segment.label}</span>
                ))}
            </div>
            <ul className="flow">
                {stages.map((stage) => (
                    <li key={stage.code} className="flow-row">
                        <Link to={stage.lien} className="flow-label">
                            <span className="flow-name"><span className="chain-code">{stage.code}</span>{stage.libelle}</span>
                            <span className="subtle">{stage.total} dossier{stage.total > 1 ? 's' : ''} · {fcfaCompact(stage.montant)} FCFA</span>
                        </Link>
                        {stage.total === 0 ? (
                            <span className="subtle">Aucun dossier sur l’exercice</span>
                        ) : (
                            <div className="flow-bar" role="img" aria-label={`${stage.libelle} : ${stage.en_cours} en cours, ${stage.aboutis} aboutis, ${stage.ecartes} rejetés ou annulés`}>
                                {SEGMENTS.map((segment) => {
                                    const count = stage[segment.key];
                                    if (count === 0) return null;
                                    const content = (
                                        <>
                                            <div className="chart-tooltip-title">{stage.libelle}</div>
                                            <TipRow color={segment.color} label={segment.label} value={`${count} dossier${count > 1 ? 's' : ''}`} />
                                            {segment.amount && <TipRow label="Montant" value={`${fcfa(stage[segment.amount])} FCFA`} />}
                                            <TipRow label="Part des dossiers" value={percent((count / stage.total) * 100, 0)} />
                                        </>
                                    );

                                    return (
                                        <span
                                            key={segment.key}
                                            className="flow-seg"
                                            tabIndex={0}
                                            aria-label={`${segment.label} : ${count}`}
                                            style={{ width: `${(count / stage.total) * 100}%`, background: segment.color }}
                                            onMouseEnter={(event) => show(event.currentTarget, content)}
                                            onMouseLeave={hide}
                                            onFocus={(event) => show(event.currentTarget, content)}
                                            onBlur={hide}
                                        />
                                    );
                                })}
                            </div>
                        )}
                        <div className="flow-side">
                            <span><b className="strong">{stage.en_cours}</b> en cours · {fcfaCompact(stage.montant_en_cours)}</span>
                            {stage.en_retard > 0
                                ? <Badge tone="danger" icon={ICON.clock} size="sm">{stage.en_retard} en retard</Badge>
                                : <Badge tone="success" icon={ICON.success} size="sm">Aucun retard</Badge>}
                        </div>
                    </li>
                ))}
            </ul>
            {tooltip}
        </div>
    );
}

/* ── Évolution mensuelle cumulée : engagé et payé ────────────────────── */

export type MonthPoint = { mois: number; libelle: string; engage: number; paye: number };

const WIDTH = 720;
const HEIGHT = 260;
const PAD = { top: 18, right: 110, bottom: 30, left: 56 };

function niceMax(value: number): number {
    if (value <= 0) return 1;
    const power = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((candidate) => candidate * power >= value / 4) ?? 10;
    return Math.ceil(value / (step * power)) * step * power;
}

export function CumulativeChart({ points, year }: { points: MonthPoint[]; year: number }) {
    const [hover, setHover] = useState<number | null>(null);
    const [table, setTable] = useState(false);

    if (points.length === 0) {
        return <p className="muted">L’exercice {year} n’a pas encore commencé.</p>;
    }

    const series = [
        { key: 'engage' as const, label: 'Engagé cumulé', color: CHAIN_COLORS.engage },
        { key: 'paye' as const, label: 'Payé cumulé', color: CHAIN_COLORS.paye },
    ];
    const max = niceMax(Math.max(...points.map((point) => point.engage), 1));
    const plotWidth = WIDTH - PAD.left - PAD.right;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const step = points.length > 1 ? plotWidth / (points.length - 1) : 0;
    const x = (index: number) => PAD.left + (points.length > 1 ? index * step : plotWidth / 2);
    const y = (value: number) => PAD.top + plotHeight - (value / max) * plotHeight;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((ratio) => max * ratio);
    const last = points[points.length - 1];
    const active = hover !== null ? points[hover] : null;
    const labelGap = Math.abs(y(last.engage) - y(last.paye)) < 14;

    return (
        <figure className="chart" style={{ margin: 0 }}>
            <figcaption className="split" style={{ marginBottom: 8 }}>
                <span className="legend">
                    {series.map((serie) => (
                        <span key={serie.key} className="legend-item"><span className="chart-line-key" style={{ background: serie.color, width: 18 }} />{serie.label}</span>
                    ))}
                </span>
                <Button size="sm" variant="ghost" icon={table ? ICON.dashboard : ICON.report} onClick={() => setTable(!table)} aria-pressed={table}>
                    {table ? 'Voir la courbe' : 'Voir le tableau'}
                </Button>
            </figcaption>

            {table ? (
                <div className="table-wrap">
                    <table className="tbl is-compact">
                        <thead><tr><th>Mois</th><th className="r">Engagé cumulé (FCFA)</th><th className="r">Payé cumulé (FCFA)</th><th className="r">Payé / engagé</th></tr></thead>
                        <tbody>
                            {points.map((point) => (
                                <tr key={point.mois}>
                                    <td>{point.libelle} {year}</td>
                                    <td className="cell-amount">{fcfa(point.engage)}</td>
                                    <td className="cell-amount">{fcfa(point.paye)}</td>
                                    <td className="r mono">{point.engage > 0 ? percent((point.paye / point.engage) * 100) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="table-wrap"><div style={{ position: 'relative', minWidth: 560 }}>
                    <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} width="100%" role="img" aria-label={`Engagé et payé cumulés par mois en ${year} : ${fcfa(last.engage)} FCFA engagés et ${fcfa(last.paye)} FCFA payés à fin ${last.libelle}`} style={{ display: 'block' }}>
                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(tick)} y2={y(tick)} stroke="#E8EDF3" strokeWidth="1" />
                                <text x={PAD.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="10.5" fill="#5C6B8C">{fcfaCompact(tick)}</text>
                            </g>
                        ))}
                        {points.map((point, index) => (
                            <text key={point.mois} x={x(index)} y={HEIGHT - 8} textAnchor="middle" fontSize="10.5" fill="#5C6B8C">{point.libelle}</text>
                        ))}
                        <path
                            d={`M${x(0)},${y(0)} ${points.map((point, index) => `L${x(index)},${y(point.engage)}`).join(' ')} L${x(points.length - 1)},${y(0)} Z`}
                            fill={CHAIN_COLORS.engage}
                            opacity="0.1"
                        />
                        {active && hover !== null && <line x1={x(hover)} x2={x(hover)} y1={PAD.top} y2={PAD.top + plotHeight} stroke="#94A3B8" strokeWidth="1" />}
                        {series.map((serie) => (
                            <g key={serie.key}>
                                <polyline points={points.map((point, index) => `${x(index)},${y(point[serie.key])}`).join(' ')} fill="none" stroke={serie.color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
                                <circle cx={x(points.length - 1)} cy={y(last[serie.key])} r="4" fill={serie.color} stroke="#FFFFFF" strokeWidth="2" />
                                {hover !== null && <circle cx={x(hover)} cy={y(points[hover][serie.key])} r="4.5" fill={serie.color} stroke="#FFFFFF" strokeWidth="2" />}
                            </g>
                        ))}
                        <text x={x(points.length - 1) + 10} y={y(last.engage) + 4 - (labelGap ? 7 : 0)} fontSize="11" fill="#1E2A42" fontWeight="600">{fcfaCompact(last.engage)}</text>
                        <text x={x(points.length - 1) + 10} y={y(last.paye) + 4 + (labelGap ? 7 : 0)} fontSize="11" fill="#1E2A42" fontWeight="600">{fcfaCompact(last.paye)}</text>
                        {points.map((point, index) => (
                            <rect
                                key={point.mois}
                                x={x(index) - Math.max(step, 24) / 2}
                                y={PAD.top}
                                width={Math.max(step, 24)}
                                height={plotHeight}
                                fill="transparent"
                                tabIndex={0}
                                aria-label={`${point.libelle} ${year} : engagé ${fcfa(point.engage)} FCFA, payé ${fcfa(point.paye)} FCFA`}
                                onMouseEnter={() => setHover(index)}
                                onMouseLeave={() => setHover(null)}
                                onFocus={() => setHover(index)}
                                onBlur={() => setHover(null)}
                            />
                        ))}
                    </svg>
                    {active && hover !== null && (
                        <div className="chart-tooltip" style={{ left: `${(x(hover) / WIDTH) * 100}%`, top: `${(PAD.top / HEIGHT) * 100}%`, transform: `translate(${hover > points.length / 2 ? '-105%' : '5%'}, 0)` }} role="status">
                            <div className="chart-tooltip-title">Fin {active.libelle} {year}</div>
                            {series.map((serie) => <TipRow key={serie.key} color={serie.color} line label={serie.label} value={`${fcfaCompact(active[serie.key])} FCFA`} />)}
                            <TipRow label="Payé / engagé" value={active.engage > 0 ? percent((active.paye / active.engage) * 100) : '—'} />
                        </div>
                    )}
                </div></div>
            )}
        </figure>
    );
}
