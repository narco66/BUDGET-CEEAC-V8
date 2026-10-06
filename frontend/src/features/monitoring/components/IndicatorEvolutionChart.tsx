import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, EmptyState, Skeleton } from '../../../components/ui';
import { PerformancePill } from './se';

/**
 * Courbe cible / réalisé d’un indicateur (description S&E §62-63). Les
 * valeurs proviennent de l’API (valeurs validées uniquement) ; aucun calcul
 * de taux n’est refait ici. Deux séries sur un seul axe : réalisé en trait
 * plein, cible en tirets (identité non portée par la seule couleur), légende,
 * étiquettes directes au dernier point, info-bulle par période et vue tableau.
 */
const SERIES = {
    realise: { label: 'Réalisé validé', color: '#3B5FA8', dash: undefined },
    cible: { label: 'Cible', color: '#A8841C', dash: '6 4' },
} as const;


const WIDTH = 640;
const HEIGHT = 240;
const PAD = { top: 16, right: 96, bottom: 32, left: 48 };

export default function IndicatorEvolutionChart({ indicatorId }: { indicatorId: number }) {
    const [donnees, setDonnees] = useState<any>(null);
    const [survol, setSurvol] = useState<number | null>(null);
    const [tableau, setTableau] = useState(false);

    useEffect(() => {
        setDonnees(null);
        api.get(`/suivi/indicateurs/${indicatorId}/evolution`).then((response) => setDonnees(response.data));
    }, [indicatorId]);

    if (!donnees) {
        return <Skeleton height={240} radius={8} />;
    }

    const points: any[] = donnees.data;
    const indicateur = donnees.indicateur;
    if (points.length === 0) {
        return <EmptyState compact title="Aucune donnée à tracer">Aucune cible ni valeur validée pour {indicateur.code}.</EmptyState>;
    }

    const valeurs = points.flatMap((point) => [point.cible, point.realise]).filter((valeur) => valeur !== null) as number[];
    const max = Math.max(...valeurs, indicateur.reference ?? 0, 1) * 1.1;
    const largeur = WIDTH - PAD.left - PAD.right;
    const hauteur = HEIGHT - PAD.top - PAD.bottom;
    const x = (index: number) => PAD.left + (points.length === 1 ? largeur / 2 : (index / (points.length - 1)) * largeur);
    const y = (valeur: number) => PAD.top + hauteur - (valeur / max) * hauteur;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((part) => Math.round(max * part * 100) / 100);
    const unite = indicateur.unite ? ` ${indicateur.unite}` : '';

    function trace(cle: 'realise' | 'cible'): string {
        return points
            .map((point, index) => (point[cle] === null ? null : `${x(index)},${y(point[cle])}`))
            .filter(Boolean)
            .join(' ');
    }

    function dernier(cle: 'realise' | 'cible'): { index: number; valeur: number } | null {
        for (let index = points.length - 1; index >= 0; index -= 1) {
            if (points[index][cle] !== null) {
                return { index, valeur: points[index][cle] };
            }
        }

        return null;
    }

    const actif = survol !== null ? points[survol] : null;

    return (
        <figure style={{ margin: 0, display: 'grid', gap: 8 }}>
            <figcaption style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', alignItems: 'baseline' }}>
                <strong style={{ fontSize: 13 }}>{indicateur.code} · {indicateur.libelle} — cible et réalisé par période</strong>
                <span style={{ display: 'flex', gap: 12, alignItems: 'center', fontSize: 12 }}>
                    {(Object.keys(SERIES) as Array<keyof typeof SERIES>).map((cle) => (
                        <span key={cle} style={{ display: 'inline-flex', gap: 6, alignItems: 'center', color: '#5C6B8C' }}>
                            <svg width="22" height="8" aria-hidden="true"><line x1="0" y1="4" x2="22" y2="4" stroke={SERIES[cle].color} strokeWidth="2" strokeDasharray={SERIES[cle].dash} /></svg>
                            {SERIES[cle].label}
                        </span>
                    ))}
                    <Button size="sm" onClick={() => setTableau((courant) => !courant)} aria-pressed={tableau}>{tableau ? 'Voir la courbe' : 'Voir le tableau'}</Button>
                </span>
            </figcaption>

            {tableau ? (
                <div style={{ overflowX: 'auto' }}>
                    <table className="tbl">
                        <thead><tr><th>Période</th><th className="r">Cible</th><th className="r">Réalisé validé</th><th className="r">Taux</th><th>Statut</th></tr></thead>
                        <tbody>
                            {points.map((point) => (
                                <tr key={point.periode}>
                                    <td>{point.libelle}</td>
                                    <td className="r mono">{point.cible ?? '—'}</td>
                                    <td className="r mono">{point.realise ?? '—'}</td>
                                    <td className="r mono">{point.taux !== null ? `${point.taux} %` : '—'}</td>
                                    <td><PerformancePill status={point.statut} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div style={{ position: 'relative' }}>
                    <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} width="100%" role="img" aria-label={`Évolution de ${indicateur.libelle} : cible et réalisé par période`} style={{ display: 'block', maxWidth: WIDTH }}>
                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(tick)} y2={y(tick)} stroke="#E2E8F0" strokeWidth="1" />
                                <text x={PAD.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="10" fill="#5C6B8C">{tick}</text>
                            </g>
                        ))}
                        {points.map((point, index) => (
                            <text key={point.periode} x={x(index)} y={HEIGHT - 10} textAnchor="middle" fontSize="10" fill="#5C6B8C">{point.periode}</text>
                        ))}
                        {actif && <line x1={x(survol!)} x2={x(survol!)} y1={PAD.top} y2={PAD.top + hauteur} stroke="#94A3B8" strokeWidth="1" />}
                        {(['cible', 'realise'] as const).map((cle) => (
                            <g key={cle}>
                                <polyline points={trace(cle)} fill="none" stroke={SERIES[cle].color} strokeWidth="2" strokeDasharray={SERIES[cle].dash} strokeLinejoin="round" strokeLinecap="round" />
                                {points.map((point, index) => point[cle] === null ? null : (
                                    <circle key={index} cx={x(index)} cy={y(point[cle])} r={survol === index ? 5 : 4} fill={SERIES[cle].color} stroke="#FFFFFF" strokeWidth="2" />
                                ))}
                            </g>
                        ))}
                        {(['realise', 'cible'] as const).map((cle, rang) => {
                            const fin = dernier(cle);
                            return fin ? (
                                <text key={cle} x={x(fin.index) + 10} y={y(fin.valeur) + 4 + (rang === 1 && dernier('realise') && Math.abs(y(fin.valeur) - y(dernier('realise')!.valeur)) < 12 ? 12 : 0)} fontSize="11" fill="#1E2A42">
                                    {SERIES[cle].label} {fin.valeur}
                                </text>
                            ) : null;
                        })}
                        {points.map((point, index) => (
                            <rect
                                key={point.periode}
                                x={x(index) - Math.max(16, largeur / Math.max(points.length, 1) / 2)}
                                y={PAD.top}
                                width={Math.max(32, largeur / Math.max(points.length, 1))}
                                height={hauteur}
                                fill="transparent"
                                tabIndex={0}
                                aria-label={`${point.libelle} : cible ${point.cible ?? 'non définie'}, réalisé ${point.realise ?? 'non validé'}`}
                                onMouseEnter={() => setSurvol(index)}
                                onMouseLeave={() => setSurvol(null)}
                                onFocus={() => setSurvol(index)}
                                onBlur={() => setSurvol(null)}
                            />
                        ))}
                    </svg>
                    {actif && (
                        <div
                            role="status"
                            className="card"
                            style={{
                                position: 'absolute',
                                top: 8,
                                left: `min(calc(${(x(survol!) / WIDTH) * 100}% + 12px), calc(100% - 200px))`,
                                padding: '8px 10px',
                                fontSize: 12,
                                pointerEvents: 'none',
                                minWidth: 180,
                                boxShadow: '0 4px 12px rgba(11,28,62,.12)',
                            }}
                        >
                            <strong>{actif.libelle}</strong>
                            <div>Cible : <span className="mono">{actif.cible ?? '—'}{actif.cible !== null ? unite : ''}</span></div>
                            <div>Réalisé validé : <span className="mono">{actif.realise ?? '—'}{actif.realise !== null ? unite : ''}</span></div>
                            <div>Taux : <span className="mono">{actif.taux !== null ? `${actif.taux} %` : '—'}</span></div>
                            <div style={{ marginTop: 4 }}><PerformancePill status={actif.statut} /></div>
                        </div>
                    )}
                </div>
            )}
            <p className="muted" style={{ margin: 0, fontSize: 11 }}>
                Sens {indicateur.sens}{indicateur.reference !== null ? ` · valeur de référence ${indicateur.reference}${unite}` : ''} · seuils : atteint ≥ {donnees.seuils.atteint} %, bonne voie ≥ {donnees.seuils.en_bonne_voie} %, à surveiller ≥ {donnees.seuils.a_surveiller} %, retard ≥ {donnees.seuils.en_retard} %.
            </p>
        </figure>
    );
}
