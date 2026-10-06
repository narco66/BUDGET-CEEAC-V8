import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton, useDialogs, useToast } from '../../../components/ui';
import { APPRECIATION, APPRECIATION_COLOR, Card, CardHead, GAP_LEVEL, PerformancePill, Pill, dayMonth, errorMessage, fmt, fullDate, pct } from '../components/se';

/** Écran 6 de docs/maquette-SE : synthèse exécutive de la revue de performance. */
const ETAPES = [['resultats', 'Résultats'], ['finances', 'Finances'], ['delais', 'Délais'], ['indicateurs', 'Indicateurs'], ['risques', 'Risques'], ['recommandations', 'Recommandations'], ['decisions', 'Décisions']] as const;
const CASE: Record<string, { bg: string; fg: string; label: string }> = {
    faible: { bg: 'var(--success-bg)', fg: 'var(--success-fg)', label: 'Faible' },
    modere: { bg: 'var(--warning-bg)', fg: 'var(--warning-fg)', label: 'Modéré' },
    eleve: { bg: 'var(--orange-bg)', fg: 'var(--orange-fg)', label: 'Élevé' },
    critique: { bg: 'var(--danger-bg)', fg: 'var(--danger-fg)', label: 'Critique' },
};
const PRIORITE: Record<string, 'rouge' | 'ambre' | 'gris'> = { haute: 'rouge', moyenne: 'ambre', basse: 'gris' };
const DECISION: Record<string, string> = { attendue: 'Attendue', ajournee: 'Ajournée', decidee: 'Décidée', mise_en_oeuvre: 'Mise en œuvre' };

export default function SynthesePage() {
    const [situation, setSituation] = useState('jour');
    const [s, setS] = useState<any>(null);
    const [nouvelle, setNouvelle] = useState(false);
    const toast = useToast();
    const { prompt } = useDialogs();
    const setMessage = (text: string) => toast.success(text);
    const [erreur, setErreur] = useState('');

    function charger() {
        api.get('/suivi/synthese-executive', { params: { situation } })
            .then((response) => { setS(response.data.data); setErreur(''); })
            .catch((error) => setErreur(errorMessage(error, 'Synthèse indisponible.')));
    }

    useEffect(charger, [situation]);

    async function decider(id: number, operation: 'decider' | 'ajourner' | 'mettre_en_oeuvre') {
        let note = '';
        if (operation === 'ajourner' || operation === 'decider') {
            const values = await prompt({
                title: operation === 'ajourner' ? 'Ajourner la décision' : 'Enregistrer la décision',
                confirmLabel: operation === 'ajourner' ? 'Ajourner' : 'Décider',
                tone: operation === 'ajourner' ? 'warning' : 'default',
                fields: [operation === 'ajourner'
                    ? { name: 'note', label: 'Motif de l’ajournement', type: 'textarea', required: true }
                    : { name: 'note', label: 'Décision prise', type: 'textarea', hint: 'Facultatif.' }],
            });
            if (!values) return;
            note = values.note;
        }
        try {
            await api.post(`/suivi/decisions/${id}/${operation}`, note ? { note } : {});
            setMessage('Décision enregistrée.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    async function proposer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        try {
            await api.post('/suivi/decisions', { description: form.get('description'), responsible_label: form.get('responsable'), due_on: form.get('echeance') || null, priority: form.get('priorite') });
            setNouvelle(false);
            setMessage('Décision inscrite à la revue.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    if (!s) {
        return erreur ? <PageError message={erreur} onRetry={charger} /> : <PageSkeleton variant="detail" />;
    }

    const k = s.kpis;
    const figee = s.situation.type === 'figee';
    const derniere = s.situations_figees[0];
    const appreciation = s.indice.appreciation;
    const niveauEcart = GAP_LEVEL[k.ecart_moyen_niveau] ?? GAP_LEVEL.normal;
    const kpis: { id?: string; label: string; value: string; hint: string; color?: string }[] = [
        { id: 'resultats', label: 'Exécution physique', value: pct(k.physique, 0), hint: 'PAP · pondéré', color: 'var(--green-600)' },
        { id: 'finances', label: 'Exécution financière', value: pct(k.financier), hint: 'engagé / révisé', color: 'var(--navy-900)' },
        { id: 'indicateurs', label: 'Indicateurs atteints', value: pct(k.taux_indicateurs, 0), hint: `${k.indicateurs_atteints} sur ${k.indicateurs_total}` },
        { label: 'Activités achevées', value: pct(k.taux_achevement, 0), hint: `${k.activites_achevees} sur ${k.activites_total}` },
        { id: 'delais', label: 'Activités en retard', value: pct(k.taux_retard, 0), hint: `${k.activites_en_retard} sur ${k.activites_total}`, color: k.activites_en_retard > 0 ? 'var(--orange-fg)' : undefined },
        { label: 'Recommandations réalisées', value: pct(k.taux_recommandations, 0), hint: `sur ${k.recommandations_emises} émises` },
        { label: 'Risques critiques', value: String(k.risques_critiques), hint: `dont ${k.risques_non_traites} non traités`, color: k.risques_critiques > 0 ? 'var(--danger-fg)' : undefined },
        { label: 'Écart moyen phys./fin.', value: `${fmt(k.ecart_moyen, 0)} pts`, hint: niveauEcart.label, color: niveauEcart.fg },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/suivi', label: 'Tableau de bord' }}
                eyebrow={<><span>Revue de performance</span><span>{figee ? `situation figée au ${fullDate(s.situation.au)}` : `données au ${fullDate(s.situation.au)}`}</span></>}
                title="Synthèse exécutive"
                actions={(
                    <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                        <div role="group" aria-label="Situation" className="seg">
                            <button type="button" className="seg-item" aria-pressed={!figee} onClick={() => setSituation('jour')}>Données du jour</button>
                            {s.situations_figees.length > 0 ? (
                                <select className={`seg-item${figee ? ' is-active' : ''}`} aria-label="Situation figée" value={figee ? String(s.situation.rapport_id) : ''} onChange={(event) => event.target.value && setSituation(event.target.value)}
                                    style={{ minHeight: 0, width: 'auto', border: 0, paddingRight: 30 }}>
                                    {!figee && <option value="">Situation figée au {fullDate(derniere.au)}</option>}
                                    {s.situations_figees.map((row: any) => <option key={row.rapport_id} value={row.rapport_id}>Situation figée au {fullDate(row.au)} · v{row.version}</option>)}
                                </select>
                            ) : (
                                <span className="seg-item" style={{ color: 'var(--slate-400)', cursor: 'not-allowed' }} title="Aucun rapport publié">Situation figée</span>
                            )}
                        </div>
                        {figee && (
                            <Button href={`/api/v1/suivi/rapports-performance/${s.situation.rapport_id}/pdf`} target="_blank" rel="noreferrer" icon={ICON.pdf}>{`PDF signé · v${s.situation.version}`}</Button>
                        )}
                        {!figee && derniere && (
                            <Button href={`/api/v1/suivi/rapports-performance/${derniere.rapport_id}/pdf`} target="_blank" rel="noreferrer" icon={ICON.pdf}>{`PDF signé · v${derniere.version}`}</Button>
                        )}
                    </div>
                )}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <nav aria-label="Étapes de la revue" className="card" style={{ padding: '10px 14px', display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {ETAPES.map(([ancre, libelle], index) => (
                    <a key={ancre} href={`#se-${ancre}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '6px 10px', borderRadius: 6, fontSize: 12.5, fontWeight: 600, color: 'var(--slate-800)' }}>
                        <span className="mono" style={{ width: 20, height: 20, borderRadius: 99, background: 'var(--navy-900)', color: 'var(--color-on-solid)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 10, fontWeight: 700 }}>{index + 1}</span>
                        {libelle}
                    </a>
                ))}
            </nav>

            <div className="se-grid-synthese">
                <section className="card" aria-label="Indice global de performance" style={{ padding: '18px 20px', display: 'flex', flexDirection: 'column', gap: 8, background: 'var(--navy-900)', color: 'var(--color-on-solid)', border: 0 }}>
                    <span style={{ fontSize: 11, fontWeight: 600, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--navy-200)' }}>Indice global de performance</span>
                    <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
                        <span className="serif" style={{ fontSize: 64, lineHeight: 1 }}>{fmt(s.indice.valeur, 0)}</span>
                        <span style={{ fontSize: 16, color: 'var(--navy-200)' }}>/ 100</span>
                    </div>
                    <span style={{ alignSelf: 'flex-start', padding: '3px 10px', borderRadius: 99, background: 'var(--color-surface)', color: APPRECIATION_COLOR[appreciation] ?? 'var(--navy-900)', fontSize: 12, fontWeight: 700 }}>{APPRECIATION[appreciation] ?? appreciation}</span>
                    <span style={{ fontSize: 11.5, color: 'var(--navy-200)', lineHeight: 1.5 }}>Physique, finances, délais, résultats, qualité et risques · formule paramétrable</span>
                </section>
                <section className="card se-grid-4" aria-label="Indicateurs clés" style={{ padding: 0, gap: 0 }}>
                    {kpis.map((kpi) => (
                        <div key={kpi.label} id={kpi.id ? `se-${kpi.id}` : undefined} style={{ padding: '14px 16px', borderRight: '1px solid var(--color-divider)', borderBottom: '1px solid var(--color-divider)', display: 'flex', flexDirection: 'column', gap: 3, scrollMarginTop: 80 }}>
                            <span style={{ fontSize: 11, color: 'var(--slate-500)' }}>{kpi.label}</span>
                            <span className="mono" style={{ fontSize: 22, fontWeight: 700, color: kpi.color ?? 'var(--slate-800)' }}>{kpi.value}</span>
                            <span style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>{kpi.hint}</span>
                        </div>
                    ))}
                </section>
            </div>

            <div className="se-grid-side-420">
                <Card label="Principaux écarts" padded={false}>
                    <CardHead padded title="Principaux écarts" tag={s.ecarts.some((row: any) => row.gravite >= 3) ? <Pill tone="rouge" icon="alert">Critique</Pill> : undefined} />
                    <div className="se-scroll">
                        <table className="tbl">
                            <thead><tr><th>Activité</th><th>Structure</th><th className="r">Phys. / fin. (%)</th><th>Constat</th></tr></thead>
                            <tbody>
                                {s.ecarts.length === 0 && <tr><td colSpan={4} className="muted">Aucun écart au-dessus des seuils paramétrés.</td></tr>}
                                {s.ecarts.map((row: any) => (
                                    <tr key={row.id}>
                                        <td><Link to={row.ecart_id ? `/suivi/ecarts/${row.ecart_id}` : `/suivi/activites/${row.id}`} style={{ fontWeight: 500 }}>{row.activite}</Link></td>
                                        <td style={{ fontSize: 12 }}>{row.structure}</td>
                                        <td className="r mono">{fmt(row.physique, 0)} / {fmt(row.financier, 0)}</td>
                                        <td style={{ fontSize: 12, color: row.gravite >= 3 ? 'var(--danger-fg)' : 'var(--orange-fg)' }}>{row.motif}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <section id="se-risques" className="card" aria-label="Risques majeurs" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 12, scrollMarginTop: 80 }}>
                    <CardHead title="Risques majeurs · probabilité × impact" right={<span style={{ fontSize: 12, color: 'var(--slate-500)' }}>{s.risques.ouverts} ouverts</span>} />
                    <div role="table" aria-label="Matrice des risques" style={{ display: 'grid', gridTemplateColumns: '80px repeat(4, minmax(0, 1fr))', gap: 4 }}>
                        {s.risques.lignes.map((ligne: any) => [
                            <span key={`l-${ligne.probabilite}`} role="rowheader" style={{ fontSize: 11, color: 'var(--slate-500)', alignSelf: 'center' }}>{ligne.probabilite}</span>,
                            ...ligne.cases.map((cell: any) => {
                                const style = CASE[cell.niveau] ?? CASE.faible;

                                return (
                                    <div key={`${cell.probabilite}-${cell.impact}`} role="cell" title={`${cell.nombre} risque(s) · ${style.label}`}
                                        style={{ height: 46, borderRadius: 6, background: style.bg, color: style.fg, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', opacity: cell.nombre > 0 ? 1 : 0.55 }}>
                                        <span className="mono" style={{ fontSize: 15, fontWeight: 700 }}>{cell.nombre > 0 ? cell.nombre : '·'}</span>
                                        <span style={{ fontSize: 9.5, fontWeight: 600 }}>{style.label}</span>
                                    </div>
                                );
                            }),
                        ])}
                        <span />
                        {s.risques.impacts.map((impact: string) => <span key={impact} style={{ fontSize: 11, color: 'var(--slate-500)', textAlign: 'center' }}>{impact}</span>)}
                    </div>
                </section>
            </div>

            <div className="se-grid-2">
                <section id="se-recommandations" className="card" aria-label="Principales recommandations" style={{ display: 'flex', flexDirection: 'column', gap: 12, scrollMarginTop: 80 }}>
                    <CardHead padded title="Principales recommandations" right={<Link to="/suivi/actions" style={{ fontSize: 12 }}>Toutes →</Link>} />
                    <div className="se-scroll">
                        <table className="tbl">
                            <thead><tr><th>Réf.</th><th>Recommandation</th><th>Responsable</th><th>Statut</th></tr></thead>
                            <tbody>
                                {s.recommandations.length === 0 && <tr><td colSpan={4} className="muted">Aucune recommandation émise.</td></tr>}
                                {s.recommandations.map((row: any) => (
                                    <tr key={row.id}>
                                        <td className="mono" style={{ fontSize: 11.5, fontWeight: 600 }}>{row.reference}</td>
                                        <td style={{ fontSize: 12.5 }}>{row.description}</td>
                                        <td style={{ fontSize: 12 }}>{row.responsable}</td>
                                        <td><PerformancePill status={row.statut} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="se-decisions" className="card" aria-label="Décisions de la revue" style={{ padding: '16px 18px', display: 'flex', flexDirection: 'column', gap: 12, scrollMarginTop: 80 }}>
                    <CardHead
                        title="Décisions de la revue"
                        tag={<Pill tone="marine">{s.decisions.attendues}</Pill>}
                        right={!figee ? <Button size="sm" variant="ghost" icon={ICON.create} onClick={() => setNouvelle(!nouvelle)} aria-expanded={nouvelle}>Inscrire une décision</Button> : undefined}
                    />
                    {nouvelle && (
                        <form onSubmit={proposer} className="se-grid-2" style={{ padding: 12, borderRadius: 8, background: 'var(--slate-50)' }}>
                            <label className="field" style={{ gridColumn: '1 / -1' }}><span className="lbl">Décision à prendre</span><input className="inp" name="description" required /></label>
                            <label className="field"><span className="lbl">Responsable</span><input className="inp" name="responsable" required /></label>
                            <label className="field"><span className="lbl">Échéance</span><input className="inp" type="date" name="echeance" /></label>
                            <label className="field"><span className="lbl">Priorité</span>
                                <select className="inp" name="priorite" defaultValue="haute"><option value="haute">Haute</option><option value="moyenne">Moyenne</option><option value="basse">Basse</option></select>
                            </label>
                            <div style={{ alignSelf: 'end' }}><Button variant="primary" type="submit" icon={ICON.save}>Inscrire</Button></div>
                        </form>
                    )}
                    {s.decisions.lignes.length === 0 && <span className="muted" style={{ fontSize: 12.5 }}>Aucune décision inscrite à la revue.</span>}
                    {s.decisions.lignes.map((row: any) => (
                        <div key={row.id} style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 150px 56px 72px auto', gap: 10, alignItems: 'center', fontSize: 12.5, borderTop: '1px solid var(--slate-100)', paddingTop: 10 }}>
                            <span style={{ fontWeight: 500 }}>
                                <span className="mono" style={{ display: 'block', fontSize: 11, color: 'var(--slate-500)' }}>{row.reference}</span>
                                {row.description}
                                <span style={{ display: 'block', fontSize: 11, color: 'var(--color-text-subtle)' }}>{DECISION[row.statut] ?? row.statut}{row.note ? ` · ${row.note}` : ''}</span>
                            </span>
                            <span style={{ fontSize: 12, color: 'var(--slate-500)' }}>{row.responsable}</span>
                            <span className="mono" style={{ fontSize: 12 }}>{dayMonth(row.echeance)}</span>
                            <Pill tone={PRIORITE[row.priorite] ?? 'gris'}>{row.priorite.charAt(0).toUpperCase() + row.priorite.slice(1)}</Pill>
                            {!figee && s.decisions.peut_decider && (
                                <span style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                    {row.statut === 'attendue' && <Button size="sm" variant="warning" onClick={() => decider(row.id, 'ajourner')}>Ajourner</Button>}
                                    {(row.statut === 'attendue' || row.statut === 'ajournee') && <Button size="sm" variant="brand" onClick={() => decider(row.id, 'decider')}>Décider</Button>}
                                    {row.statut === 'decidee' && <Button size="sm" onClick={() => decider(row.id, 'mettre_en_oeuvre')}>Mise en œuvre</Button>}
                                </span>
                            )}
                        </div>
                    ))}
                    <span style={{ fontSize: 11.5, color: 'var(--color-text-subtle)' }}>Chaque décision devient une action suivie : responsable, échéance, priorité et statut.</span>
                </section>
            </div>
        </main>
    );
}
