import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, EmptyState, ErrorMessage, ICON, PageHeader, PageSkeleton, Segmented, useDialogs, useToast } from '../../../components/ui';
import { Card, CardHead, Icon, PerformancePill, dayMonth, errorMessage, fullDate, money, pct } from '../components/se';

/** Écran 3 de docs/maquette-SE : Gantt planning initial / planning réel. */
const GRID = '44px 210px 96px 78px 52px minmax(560px, 1fr)';
const REEL: Record<string, string> = { atteint: 'var(--green-600)', en_bonne_voie: 'var(--green-600)', a_surveiller: 'var(--warning-solid)', en_retard: 'var(--orange-solid)', critique: 'var(--danger-solid)' };
const ECHELLES = [['semaines', 'Semaines'], ['mois', 'Mois'], ['trimestres', 'Trimestres']] as const;

export default function GanttPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [activites, setActivites] = useState<any[]>([]);
    const [echelle, setEchelle] = useState('mois');
    const [plan, setPlan] = useState<any>(null);
    const [proposer, setProposer] = useState(false);
    const [constater, setConstater] = useState(false);
    const [historique, setHistorique] = useState(false);
    const [erreur, setErreur] = useState('');
    const toast = useToast();
    const { prompt } = useDialogs();
    const setMessage = (text: string) => toast.success(text);

    useEffect(() => {
        api.get('/suivi/pilotage').then((response) => {
            const rows = response.data.data.activites ?? [];
            setActivites(rows);
            if (!id && rows.length > 0) navigate(`/suivi/activites/${rows[0].id}/gantt`, { replace: true });
        }).catch(() => setActivites([]));
    }, []);

    function charger() {
        if (!id) return;
        api.get(`/suivi/activites/${id}/gantt`, { params: { echelle } })
            .then((response) => { setPlan(response.data.data); setErreur(''); })
            .catch((error) => setErreur(errorMessage(error, 'Planning inaccessible.')));
    }

    useEffect(charger, [id, echelle]);

    async function constaterFin(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const tacheId = String(form.get('tache') || '');
        const fin = String(form.get('fin_reelle') || '');
        const tache = plan.taches.find((row: any) => String(row.id) === tacheId);
        try {
            await api.patch(`/suivi/taches/${tacheId}`, { actual_end: fin });
            const prevue = tache?.fin_courante || tache?.fin_initiale;
            const ecart = prevue && fin > prevue ? joursEntre(prevue, fin) : 0;
            const dependantes = plan.taches.filter((row: { depend_de?: string }) => tache?.code && row.depend_de === tache.code).length;
            setConstater(false);
            setMessage(ecart > 0 && dependantes > 0
                ? `Fin réelle enregistrée. ${dependantes} tâche${dependantes > 1 ? 's' : ''} dépendante${dependantes > 1 ? 's sont décalées' : ' est décalée'} de ${ecart} jour${ecart > 1 ? 's' : ''}.`
                : 'Fin réelle enregistrée.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    async function soumettrePlanning(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const taches = plan.taches
            .map((tache: any) => ({ id: tache.id, starts_on: String(form.get(`debut_${tache.id}`) || ''), ends_on: String(form.get(`fin_${tache.id}`) || '') }))
            .filter((tache: any) => tache.starts_on && tache.ends_on);
        try {
            await api.post(`/suivi/activites/${id}/plannings`, { motif: form.get('motif'), taches });
            setProposer(false);
            setMessage('Proposition de planning transmise pour validation.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    async function decider(revision: number, choix: 'valider' | 'rejeter') {
        let motif = '';
        if (choix === 'rejeter') {
            const values = await prompt({
                title: 'Rejeter la proposition de planning',
                confirmLabel: 'Rejeter',
                tone: 'danger',
                fields: [{ name: 'motif', label: 'Motif du rejet', type: 'textarea', required: true }],
            });
            if (!values) return;
            motif = values.motif;
        }
        try {
            await api.post(`/suivi/plannings/${revision}/${choix}`, { motif });
            setMessage(choix === 'valider' ? 'Planning validé.' : 'Planning rejeté.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    const selecteur = (
        <label className="field" style={{ minWidth: 280 }}>
            <span className="lbl">Activité</span>
            <select className="inp" value={id ?? ''} onChange={(event) => navigate(`/suivi/activites/${event.target.value}/gantt`)}>
                {!id && <option value="">Choisir une activité</option>}
                {activites.map((row) => <option key={row.id} value={row.id}>{row.code ?? `ACT-${row.id}`} · {row.activite}</option>)}
            </select>
        </label>
    );

    if (!id || !plan) {
        return (
            <main className="app-content">
                <PageHeader
                    back={{ to: '/suivi', label: 'Tableau de bord' }}
                    eyebrow="Suivi & évaluation"
                    title="Gantt · planning initial et planning réel"
                    subtitle="Choisissez une activité suivie pour afficher son planning."
                    actions={selecteur}
                />
                {erreur
                    ? <ErrorMessage error={erreur} title="Planning inaccessible" />
                    : activites.length === 0
                        ? <div className="card"><EmptyState icon={ICON.gantt} title="Aucune activité suivie">Le Gantt s’affiche dès qu’une activité est rattachée au suivi.</EmptyState></div>
                        : <PageSkeleton variant="detail" />}
            </main>
        );
    }

    const activite = plan.activite;
    const revisions = plan.revisions;
    const enAttente = revisions.historique.find((row: any) => row.id === revisions.en_attente);

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: `/suivi/activites/${activite.id}`, label: 'Fiche activité' }}
                eyebrow={<><span className="mono strong">{activite.code}</span><span>Suivi & évaluation</span></>}
                title="Gantt · planning initial et planning réel"
                subtitle={`${activite.libelle}${activite.budget !== null ? ` · budget ${money(activite.budget)}` : ''}`}
                actions={<>
                    {selecteur}
                    <Segmented label="Échelle" value={echelle} onChange={setEchelle} items={ECHELLES.map(([cle, libelle]) => ({ value: cle, label: libelle }))} />
                    <Button icon={ICON.clock} onClick={() => setConstater(!constater)} aria-expanded={constater} disabled={plan.taches.length === 0}>Constater la fin réelle</Button>
                    <Button icon={ICON.gantt} onClick={() => setProposer(!proposer)} aria-expanded={proposer} disabled={!!revisions.en_attente || plan.taches.length === 0}>Proposer un nouveau planning</Button>
                </>}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            {constater && (
                <form className="card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10 }} onSubmit={constaterFin}>
                    <h2 style={{ margin: 0, fontSize: 15, color: 'var(--navy-900)' }}>Fin réelle d’une tâche</h2>
                    <p style={{ margin: 0, fontSize: 12.5, color: 'var(--slate-700)', lineHeight: 1.5 }}>
                        Si la fin réelle dépasse la fin prévue, les tâches qui en dépendent sont décalées d’autant. Le planning initial validé reste en place.
                    </p>
                    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                        <label className="field" style={{ minWidth: 280, flex: 1 }}>
                            <span className="lbl">Tâche</span>
                            <select className="inp" name="tache" required defaultValue={plan.taches[0]?.id ?? ''}>
                                {plan.taches.map((tache: { id: number; code: string; libelle: string; fin_courante?: string; fin_initiale?: string }) => (
                                    <option key={tache.id} value={tache.id}>{tache.code} · {tache.libelle} · prévue le {fullDate(tache.fin_courante || tache.fin_initiale)}</option>
                                ))}
                            </select>
                        </label>
                        <label className="field">
                            <span className="lbl">Fin réelle</span>
                            <input className="inp" type="date" name="fin_reelle" required aria-label="Fin réelle" />
                        </label>
                    </div>
                    <div><Button variant="primary" type="submit" icon={ICON.calendar}>Enregistrer la fin réelle</Button></div>
                </form>
            )}

            {proposer && (
                <form className="card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10 }} onSubmit={soumettrePlanning}>
                    <h2 style={{ margin: 0, fontSize: 15, color: 'var(--navy-900)' }}>Proposition de planning · version {revisions.versions + 1}</h2>
                    <div className="se-scroll">
                        <table className="tbl">
                            <thead><tr><th>Code</th><th>Tâche</th><th>Début initial</th><th>Fin initiale</th><th>Nouveau début</th><th>Nouvelle fin</th></tr></thead>
                            <tbody>
                                {plan.taches.map((tache: any) => (
                                    <tr key={tache.id}>
                                        <td className="mono" style={{ color: 'var(--warning-fg)', fontWeight: 700 }}>{tache.code}</td>
                                        <td>{tache.libelle}</td>
                                        <td className="mono">{fullDate(tache.debut_initial)}</td>
                                        <td className="mono">{fullDate(tache.fin_initiale)}</td>
                                        <td><input className="inp" type="date" name={`debut_${tache.id}`} defaultValue={tache.debut_initial ?? ''} aria-label={`Nouveau début ${tache.code}`} /></td>
                                        <td><input className="inp" type="date" name={`fin_${tache.id}`} defaultValue={tache.fin_projetee ?? tache.fin_initiale ?? ''} aria-label={`Nouvelle fin ${tache.code}`} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <label className="field"><span className="lbl">Motif de la révision</span><textarea className="inp" name="motif" required rows={2} style={{ height: 'auto', padding: 10 }} /></label>
                    <div><Button variant="primary" type="submit" icon={ICON.submit}>Soumettre à validation</Button></div>
                </form>
            )}

            <section className="card" aria-label="Diagramme de Gantt" style={{ padding: '16px 18px', overflowX: 'auto' }}>
                <div style={{ minWidth: 1040 }}>
                    <div style={{ display: 'grid', gridTemplateColumns: GRID, alignItems: 'end', borderBottom: '1px solid var(--slate-200)', height: 34 }}>
                        {['Code', 'Tâche · responsable', 'Dépend de', 'Statut', 'Avanc.'].map((titre) => <span key={titre} className="lbl" style={{ paddingBottom: 8 }}>{titre}</span>)}
                        <div style={{ position: 'relative', height: 34 }}>
                            {plan.colonnes.map((colonne: any) => (
                                <div key={colonne.debut} style={{ position: 'absolute', left: `${colonne.gauche}%`, top: 0, bottom: 0, borderLeft: '1px solid var(--slate-200)' }}>
                                    <span style={{ position: 'absolute', top: 8, left: 6, fontSize: 11, fontWeight: 600, color: 'var(--slate-500)', whiteSpace: 'nowrap' }}>{colonne.libelle}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div style={{ position: 'relative' }}>
                        {plan.taches.length === 0 && <p className="muted" style={{ padding: 16 }}>Aucune tâche programmée pour cette activité.</p>}
                        {plan.taches.map((tache: any) => {
                            const couleur = REEL[tache.statut] ?? 'var(--slate-400)';
                            const retard = tache.retard_fin > 0 ? tache.retard_fin : 0;

                            return (
                                <div key={tache.id} style={{ display: 'grid', gridTemplateColumns: GRID, alignItems: 'center', borderBottom: '1px solid var(--slate-100)', minHeight: 50 }}>
                                    <span className="mono" style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--warning-fg)' }}>{tache.code}</span>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, paddingRight: 8 }}>
                                        <span style={{ fontSize: 12.5, fontWeight: 600 }}>{tache.libelle}</span>
                                        <span style={{ fontSize: 10.5, color: 'var(--color-text-subtle)' }}>{tache.responsable ?? '—'}</span>
                                        {tache.non_demarree && tache.retard_demarrage > 0 && <span style={{ fontSize: 10.5, color: 'var(--orange-fg)' }}>non démarrée · {tache.retard_demarrage} j de retard au démarrage</span>}
                                        {tache.fin_courante && <span style={{ fontSize: 10.5, color: 'var(--slate-500)' }}>prévue jusqu’au {dayMonth(tache.fin_courante)}</span>}
                                        {tache.fin_courante && tache.fin_initiale && tache.fin_courante !== tache.fin_initiale && (
                                            <span style={{ fontSize: 10.5, color: 'var(--orange-fg)' }}>planning courant décalé au {dayMonth(tache.fin_courante)}</span>
                                        )}
                                    </div>
                                    <span className="mono" style={{ fontSize: 11, color: 'var(--slate-500)' }}>{tache.depend_de ? `${tache.depend_de} · ${tache.type_dependance}` : '—'}</span>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, alignItems: 'flex-start' }}>
                                        <PerformancePill status={tache.statut} />
                                        {retard > 0 && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--orange-fg)' }}>+{retard} j</span>}
                                    </div>
                                    <span className="mono" style={{ fontSize: 12, fontWeight: 700, textAlign: 'right', paddingRight: 8 }}>{pct(tache.avancement, 0)}</span>
                                    <div style={{ position: 'relative', height: 50 }}>
                                        {tache.initial && (
                                            <div title={`Prévu ${dayMonth(tache.debut_initial)} → ${dayMonth(tache.fin_initiale)}`}
                                                style={{ position: 'absolute', left: `${tache.initial.gauche}%`, width: `${tache.initial.largeur}%`, top: 9, height: 10, borderRadius: 3, border: '1.5px dashed var(--slate-400)', boxSizing: 'border-box' }} />
                                        )}
                                        {tache.reel && (
                                            <div title={`Réel ${dayMonth(tache.debut_reel)} → ${tache.fin_reelle ? dayMonth(tache.fin_reelle) : 'en cours'}`}
                                                style={{ position: 'absolute', left: `${tache.reel.gauche}%`, width: `${tache.reel.largeur}%`, top: 24, height: 14, borderRadius: tache.projection ? '3px 0 0 3px' : 3, background: couleur }} />
                                        )}
                                        {tache.projection && (
                                            <div title={`Projection ${dayMonth(tache.fin_projetee)}`}
                                                style={{ position: 'absolute', left: `${tache.projection.gauche}%`, width: `${tache.projection.largeur}%`, top: 24, height: 14, borderRadius: tache.reel ? '0 3px 3px 0' : 3, background: `repeating-linear-gradient(45deg,${couleur} 0 3px,var(--color-surface) 3px 7px)`, border: `1px solid ${couleur}`, boxSizing: 'border-box' }} />
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                        <div style={{ display: 'grid', gridTemplateColumns: GRID, alignItems: 'center', minHeight: 96, background: 'var(--slate-50)' }}>
                            <div style={{ gridColumn: '1 / 6', display: 'flex', alignItems: 'center', gap: 8 }}><Icon name="target" /><span style={{ fontSize: 12.5, fontWeight: 700 }}>Jalons</span></div>
                            <div style={{ position: 'relative', height: 96 }}>
                                {plan.jalons.length === 0 && <span className="muted" style={{ fontSize: 12, position: 'absolute', top: 36, left: 8 }}>Aucun jalon.</span>}
                                {plan.jalons.filter((jalon: any) => jalon.position !== null).map((jalon: any) => {
                                    const franchi = jalon.statut === 'franchi';
                                    const retard = jalon.statut === 'en_retard';

                                    return (
                                        <div key={jalon.id} title={jalon.libelle} style={{ position: 'absolute', left: `calc(${jalon.position}% - 7px)`, top: 10, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4, width: 14 }}>
                                            <span aria-hidden="true" style={{ width: 12, height: 12, transform: 'rotate(45deg)', background: franchi ? 'var(--green-600)' : 'var(--color-surface)', border: `2px solid ${franchi ? 'var(--green-600)' : retard ? 'var(--orange-solid)' : 'var(--slate-400)'}` }} />
                                            <span style={{ fontSize: 10, color: 'var(--slate-700)', whiteSpace: 'nowrap' }}>{jalon.libelle}</span>
                                            <span className="mono" style={{ fontSize: 9.5, color: 'var(--color-text-subtle)', whiteSpace: 'nowrap' }}>{dayMonth(jalon.date)}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                        {plan.aujourdhui !== null && plan.aujourdhui !== undefined && (
                            <div aria-hidden="true" style={{ position: 'absolute', inset: 0, display: 'grid', gridTemplateColumns: GRID, pointerEvents: 'none' }}>
                                <div style={{ gridColumn: 6, position: 'relative' }}>
                                    <div style={{ position: 'absolute', left: `${plan.aujourdhui}%`, top: 0, bottom: 0, width: 2, background: 'var(--danger-solid)', zIndex: 2 }} />
                                    <span style={{ position: 'absolute', left: `calc(${plan.aujourdhui}% - 38px)`, top: -26, padding: '2px 6px', borderRadius: 4, background: 'var(--danger-solid)', color: 'var(--color-on-solid)', fontSize: 10, fontWeight: 700, zIndex: 3, whiteSpace: 'nowrap' }}>
                                        {dayMonth(new Date().toISOString().slice(0, 10))} · aujourd’hui
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </section>

            <div style={{ display: 'flex', alignItems: 'center', gap: 22, flexWrap: 'wrap', fontSize: 12, color: 'var(--slate-700)' }}>
                <Legende style={{ border: '1.5px dashed var(--slate-400)' }}>Planning initial (validé)</Legende>
                <Legende style={{ background: 'var(--green-600)' }}>Réel · conforme</Legende>
                <Legende style={{ background: 'var(--warning-solid)' }}>Réel · à surveiller</Legende>
                <Legende style={{ background: 'var(--orange-solid)' }}>Réel · en retard</Legende>
                <Legende style={{ background: 'repeating-linear-gradient(45deg,var(--orange-solid) 0 3px,var(--color-surface) 3px 7px)', border: '1px solid var(--orange-solid)' }}>Projection</Legende>
                <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><span style={{ width: 10, height: 10, transform: 'rotate(45deg)', background: 'var(--green-600)' }} />Jalon franchi</span>
                <span>FD = fin → début</span>
            </div>

            <div className="se-grid-3">
                <Card label="Impact des dépendances">
                    <CardHead title="Impact des dépendances" />
                    {plan.impacts.length === 0 && <span style={{ fontSize: 12.5, color: 'var(--slate-700)' }}>Aucun glissement propagé par les dépendances.</span>}
                    {plan.impacts.map((texte: string) => (
                        <div key={texte} style={{ display: 'flex', gap: 10, fontSize: 12.5, color: 'var(--orange-fg)', lineHeight: 1.5 }}><Icon name="alert" color="var(--orange-solid)" size={16} /><span>{texte}</span></div>
                    ))}
                    {plan.fin_projetee && <span style={{ fontSize: 12, color: 'var(--slate-500)' }}>Fin projetée de l’activité : <b className="mono">{fullDate(plan.fin_projetee)}</b></span>}
                </Card>
                <Card label="Retards constatés">
                    <CardHead title="Retards constatés" />
                    {plan.retards.length === 0 && <span style={{ fontSize: 12.5, color: 'var(--slate-700)' }}>Aucun retard constaté.</span>}
                    {plan.retards.map((row: any) => (
                        <div key={row.libelle} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5 }}>
                            <span>{row.libelle}</span>
                            <b className="mono" style={{ color: 'var(--orange-fg)' }}>+{row.jours} j</b>
                        </div>
                    ))}
                </Card>
                <Card label="Modification du planning">
                    <CardHead title="Modification du planning" />
                    <span style={{ fontSize: 12.5, color: 'var(--slate-700)', lineHeight: 1.5 }}>Toute révision est soumise à validation, motivée et tracée : le planning initial reste visible pour comparaison.</span>
                    {enAttente && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, padding: '10px 12px', borderRadius: 8, background: 'var(--warning-bg)', fontSize: 12.5 }}>
                            <span>Version {enAttente.version} proposée par <b>{enAttente.propose_par ?? '—'}</b> : {enAttente.motif}</span>
                            <div style={{ display: 'flex', gap: 6 }}>
                                <Button size="sm" variant="primary" icon={ICON.validate} onClick={() => decider(enAttente.id, 'valider')}>Valider</Button>
                                <Button size="sm" variant="danger-outline" icon={ICON.reject} onClick={() => decider(enAttente.id, 'rejeter')}>Rejeter</Button>
                            </div>
                        </div>
                    )}
                    <button type="button" className="btn btn-o" style={{ alignSelf: 'flex-start' }} aria-expanded={historique} onClick={() => setHistorique(!historique)}>
                        Historique des plannings · {revisions.versions} version{revisions.versions > 1 ? 's' : ''}
                    </button>
                    {historique && revisions.historique.map((row: any) => (
                        <div key={row.id} style={{ fontSize: 12, color: 'var(--slate-700)', borderTop: '1px solid var(--slate-100)', paddingTop: 6 }}>
                            <b>V{row.version}</b> · {row.statut} · {row.motif}
                            {row.decide_par ? ` · ${row.decide_par} le ${fullDate(row.decide_le)}` : ''}
                            {row.motif_decision ? ` · ${row.motif_decision}` : ''}
                        </div>
                    ))}
                </Card>
            </div>
        </main>
    );
}

function joursEntre(debut: string, fin: string): number {
    const [anneeDebut, moisDebut, jourDebut] = debut.split('-').map(Number);
    const [anneeFin, moisFin, jourFin] = fin.split('-').map(Number);

    return Math.round((Date.UTC(anneeFin, moisFin - 1, jourFin) - Date.UTC(anneeDebut, moisDebut - 1, jourDebut)) / 86_400_000);
}

function Legende({ style, children }: { style: React.CSSProperties; children: React.ReactNode }) {
    return <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}><span style={{ width: 28, height: 10, borderRadius: 3, boxSizing: 'border-box', ...style }} />{children}</span>;
}
