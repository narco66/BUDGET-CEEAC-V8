import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, OpenCell, PageError, PageHeader, PageSkeleton, SectionCard, useToast, type Column } from '../../../components/ui';
import { ACTIVITY_STATUS, BarRow, Card, CardHead, GAP_LEVEL, GapBox, Icon, Pager, Pill, Tag, dayMonth, errorMessage, fullDate, money, pct } from '../components/se';

/** Écran 5 de docs/maquette-SE : écart critique et action corrective. */
const ETAT: Record<string, { bg: string; border: string; icon: string | null; label?: string }> = {
    fait: { bg: 'var(--green-600)', border: 'var(--green-600)', icon: 'check' },
    en_cours: { bg: 'var(--color-surface)', border: 'var(--warning-solid)', icon: null, label: 'en cours' },
    en_retard: { bg: 'var(--orange-solid)', border: 'var(--orange-solid)', icon: 'clock', label: 'en retard' },
    attendu: { bg: 'var(--color-surface)', border: 'var(--slate-300)', icon: null, label: 'attendu' },
    a_venir: { bg: 'var(--color-surface)', border: 'var(--slate-300)', icon: null, label: 'à venir' },
};
const ECART_STATUT: Record<string, string> = { ouvert: 'Ouvert', critique: 'Critique', a_surveiller: 'À surveiller', explique: 'Expliqué', en_traitement: 'En traitement', clos: 'Clos' };
const ACTION_STATUT: Record<string, { label: string; tone: 'gris' | 'bleu' | 'vert' | 'orange' | 'rouge' }> = {
    ouverte: { label: 'Ouverte', tone: 'gris' },
    en_cours: { label: 'En cours', tone: 'bleu' },
    realisee: { label: 'Réalisée', tone: 'vert' },
    cloturee: { label: 'Clôturée', tone: 'vert' },
    abandonnee: { label: 'Abandonnée', tone: 'gris' },
};

export default function EcartPage() {
    const { id } = useParams();

    return id ? <DossierEcart id={id} /> : <ListeEcarts />;
}

function ListeEcarts() {
    const [ecarts, setEcarts] = useState<any[]>([]);
    const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number } | null>(null);
    const [page, setPage] = useState(1);
    const [erreur, setErreur] = useState('');
    const [chargement, setChargement] = useState(true);

    useEffect(() => {
        setChargement(true);
        api.get('/suivi/ecarts', { params: { page } })
            .then((response) => { setEcarts(response.data.data); setMeta(response.data.meta); })
            .catch((error) => setErreur(errorMessage(error)))
            .finally(() => setChargement(false));
    }, [page]);

    const navigate = useNavigate();
    const colonnes: Column<any>[] = [
        { key: 'reference', header: 'Référence', render: (row) => <span className="cell-ref">{row.reference ?? `#${row.id}`}</span> },
        {
            key: 'activite',
            header: 'Activité',
            render: (row) => (
                <span>
                    {row.activity?.activite ?? 'Activité'}
                    <span className="cell-sub mono">{row.activity?.code ?? `#${row.pap_enrichment_id}`}</span>
                </span>
            ),
        },
        { key: 'physique', header: 'Physique', align: 'right', render: (row) => <span className="num">{pct(row.physical_rate)}</span> },
        { key: 'financier', header: 'Financier', align: 'right', render: (row) => <span className="num">{pct(row.financial_rate)}</span> },
        { key: 'statut', header: 'Statut', render: (row) => <Pill tone={row.status === 'critique' ? 'rouge' : row.status === 'clos' ? 'vert' : 'ambre'}>{ECART_STATUT[row.status] ?? row.status}</Pill> },
        { key: 'detecte', header: 'Détecté le', render: (row) => <span className="num">{fullDate(row.created_at)}</span> },
        { key: 'ouvrir', header: 'Ouvrir', srHeader: true, align: 'right', width: 48, render: () => <OpenCell>Ouvrir le dossier</OpenCell> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/suivi', label: 'Tableau de bord' }}
                eyebrow="Suivi & évaluation"
                title="Écarts et actions correctives"
                subtitle="Les écarts sont générés automatiquement par le contrôle quotidien des seuils ; chaque écart critique exige une explication et une action corrective."
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />
            <SectionCard title="Écarts" icon={ICON.warning} subtitle={meta ? `${meta.total} écart(s) dans votre périmètre` : undefined} flush>
                <DataTable
                    columns={colonnes}
                    rows={ecarts}
                    rowKey={(row) => row.id}
                    loading={chargement}
                    onRowClick={(row) => navigate(`/suivi/ecarts/${row.id}`)}
                    rowLabel={(row) => `Ouvrir le dossier d’écart ${row.reference ?? row.id}`}
                    rowClassName={(row) => (row.status === 'critique' ? 'is-danger' : undefined)}
                    empty={<EmptyState icon={ICON.success} title="Aucun écart" compact>Aucun écart dans votre périmètre.</EmptyState>}
                />
                <Pager meta={meta} onPage={setPage} />
            </SectionCard>
        </main>
    );
}

function DossierEcart({ id }: { id: string }) {
    const [d, setD] = useState<any>(null);
    const [interpretations, setInterpretations] = useState<string[]>([]);
    const [causes, setCauses] = useState<string[]>([]);
    const toast = useToast();
    const setMessage = (text: string) => toast.success(text);
    const [erreur, setErreur] = useState('');

    function appliquer(data: any) {
        setD(data);
        setInterpretations(data.explication.interpretations ?? []);
        setCauses(data.explication.causes ?? []);
    }

    function charger() {
        api.get(`/suivi/ecarts/${id}/dossier`).then((response) => appliquer(response.data.data)).catch((error) => setErreur(errorMessage(error, 'Dossier inaccessible.')));
    }

    useEffect(charger, [id]);

    async function operation(nom: string, payload: Record<string, unknown> = {}, succes = 'Enregistré.') {
        setErreur('');
        try {
            const response = await api.post(`/suivi/ecarts/${id}/${nom}`, payload);
            setMessage(succes);
            if (response.data?.data?.ecart) appliquer(response.data.data); else charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    function expliquer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        operation('explication', { texte: form.get('texte'), interpretations, causes }, 'Explication enregistrée.');
    }

    function probleme(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        operation('probleme', { nature: form.get('nature'), impact: form.get('impact') || null, occurred_on: form.get('survenu'), se_risk_id: form.get('risque') ? Number(form.get('risque')) : null }, 'Problème ouvert.');
    }

    function action(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        operation('action-corrective', {
            anomaly: form.get('anomalie'), cause: form.get('cause'), description: form.get('action'), responsible_label: form.get('responsable'),
            decided_on: form.get('debut'), due_on: form.get('echeance'), expected_result: form.get('resultat') || null,
        }, 'Action corrective enregistrée.');
    }

    if (!d) {
        return erreur ? <PageError message={erreur} onRetry={charger} /> : <PageSkeleton variant="detail" />;
    }

    const activite = d.activite;
    const statut = ACTIVITY_STATUS[activite.statut] ?? ACTIVITY_STATUS.non_demarree;
    const niveau = GAP_LEVEL[activite.niveau_ecart] ?? GAP_LEVEL.normal;
    const financierSup = d.sens === 'financier_superieur';
    const toggle = (liste: string[], setter: (v: string[]) => void, code: string) => setter(liste.includes(code) ? liste.filter((c) => c !== code) : [...liste, code]);
    const f = d.finances;

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/suivi/ecarts', label: 'Écarts et actions correctives' }}
                eyebrow={(
                    <>
                        <Link to={`/suivi/activites/${activite.id}`} className="mono strong">{activite.code}</Link>
                        <Pill tone={statut.tone} size="md" dot>{statut.label}</Pill>
                        <Pill tone={activite.nature === 'pap' ? 'pap' : 'marine'}>{activite.nature === 'pap' ? 'PAP' : 'Hors PAP'}</Pill>
                        <Pill tone={niveau.tone} icon={niveau.icon}>{niveau.label}</Pill>
                        {d.ecart.reference && <span className="mono">{d.ecart.reference}</span>}
                    </>
                )}
                title={activite.activite}
                subtitle={<>
                    {[activite.structure, d.activite.departement, activite.responsable ? `responsable ${activite.responsable}` : null].filter(Boolean).join(' · ')}
                    {activite.ligne && <> · ligne <span className="mono">{activite.ligne}</span></>}
                </>}
                actions={<>
                    <Button icon={ICON.notifications} onClick={() => operation('relancer', {}, 'Relance envoyée au responsable.')}>Relancer le responsable</Button>
                    <Button variant="warning" icon={ICON.escalate} onClick={() => operation('escalader', {}, 'Escalade transmise au Directeur.')}>Escalader au Directeur</Button>
                </>}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <section role="alert" aria-labelledby="h-ecart" style={{ borderRadius: 12, border: `1px solid ${activite.niveau_ecart === 'critique' ? 'var(--danger-border)' : 'var(--warning-border)'}`, background: niveau.bg, padding: '18px 20px', display: 'flex', flexDirection: 'column', gap: 14 }}>
                <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start' }}>
                    <span style={{ width: 38, height: 38, flexShrink: 0, borderRadius: 10, background: activite.niveau_ecart === 'critique' ? 'var(--danger-solid)' : 'var(--warning-solid)', color: 'var(--color-on-solid)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Icon name="alert" size={18} /></span>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        <h2 id="h-ecart" style={{ margin: 0, fontSize: 16, fontWeight: 700, color: niveau.fg }}>Écart significatif entre exécution physique et financière</h2>
                        <span style={{ fontSize: 13, color: niveau.fg }}>
                            {pct(activite.financier, 0)} des crédits sont engagés pour {pct(activite.physique, 0)} de réalisation physique.
                            {activite.niveau_ecart === 'critique' ? ' L’explication du responsable et une action corrective sont obligatoires.' : ''}
                        </span>
                    </div>
                </div>
                <div style={{ background: 'var(--color-surface)', borderRadius: 8, padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                    <BarRow label="Exécution physique" value={activite.physique} color="var(--green-600)" />
                    <BarRow label="Exécution financière (engagé)" value={activite.financier} color="var(--navy-900)" />
                    <GapBox gap={activite.ecart} level={activite.niveau_ecart} thresholds={activite.seuils_ecart} />
                </div>
            </section>

            <section className="card" aria-label="Traitement de la sous-performance" style={{ padding: '18px 20px', overflowX: 'auto' }}>
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', minWidth: 760 }}>
                    {d.chronologie.map((etape: any, index: number) => {
                        const style = ETAT[etape.etat] ?? ETAT.attendu;
                        const suivant = d.chronologie[index + 1];

                        return (
                            <li key={etape.etape} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 5, textAlign: 'center', position: 'relative' }}>
                                {suivant && <span aria-hidden="true" style={{ position: 'absolute', top: 11, left: 'calc(50% + 14px)', right: 'calc(-50% + 14px)', height: 2, background: etape.etat === 'fait' && suivant.etat !== 'attendu' && suivant.etat !== 'a_venir' ? 'var(--green-600)' : 'var(--slate-200)' }} />}
                                <span style={{ width: 24, height: 24, borderRadius: 99, background: style.bg, border: `2px solid ${style.border}`, boxSizing: 'border-box', display: 'flex', alignItems: 'center', justifyContent: 'center', position: 'relative' }}>
                                    {style.icon && <Icon name={style.icon} size={12} color="var(--color-on-solid)" />}
                                </span>
                                <span style={{ fontSize: 11.5, fontWeight: 600 }}>{etape.etape}</span>
                                <span className="mono" style={{ fontSize: 10.5, color: etape.etat === 'en_retard' ? 'var(--orange-fg)' : 'var(--color-text-subtle)' }}>{etape.le ? dayMonth(etape.le) : style.label}</span>
                            </li>
                        );
                    })}
                </ol>
            </section>

            <div className="se-grid-360">
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 }}>
                    <Card label="Explication de l’écart">
                        <CardHead title="Explication de l’écart" right={d.explication.le ? <Pill tone="vert" icon="check">Reçue le {dayMonth(d.explication.le)}</Pill> : <Pill tone="ambre" icon="clock">Attendue</Pill>} />
                        <form onSubmit={expliquer} style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            {d.explication.texte && (
                                <blockquote style={{ margin: 0, padding: '12px 14px', borderLeft: '3px solid var(--slate-300)', background: 'var(--slate-50)', fontSize: 13, lineHeight: 1.55, color: 'var(--slate-700)' }}>
                                    « {d.explication.texte} »
                                    <div style={{ marginTop: 6, fontSize: 11.5, color: 'var(--color-text-subtle)' }}>{d.explication.auteur} · {fullDate(d.explication.le)}</div>
                                </blockquote>
                            )}
                            <label className="field"><span className="lbl">{d.explication.texte ? 'Compléter l’explication' : 'Explication du responsable'}</span>
                                <textarea className="inp" name="texte" required defaultValue={d.explication.texte ?? ''} style={{ height: 80, padding: '10px 12px', lineHeight: 1.5, resize: 'vertical' }} />
                            </label>
                            <fieldset style={{ border: 0, padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 8 }}>
                                <legend className="lbl" style={{ marginBottom: 8 }}>Interprétation · {financierSup ? 'financier > physique' : 'physique > financier'}</legend>
                                <div className="se-grid-2">
                                    {Object.entries(d.options.interpretations).map(([code, libelle]) => (
                                        <label key={code} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5 }}>
                                            <input type="checkbox" checked={interpretations.includes(code)} onChange={() => toggle(interpretations, setInterpretations, code)} />{String(libelle)}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                                <legend className="lbl" style={{ marginBottom: 8 }}>Catégories de causes</legend>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                                    {d.options.causes.map((cause: any) => {
                                        const actif = causes.includes(cause.code);

                                        return (
                                            <button key={cause.code} type="button" aria-pressed={actif} onClick={() => toggle(causes, setCauses, cause.code)}
                                                style={{ padding: '4px 11px', borderRadius: 99, fontSize: 12, fontWeight: 600, cursor: 'pointer', border: `1px solid ${actif ? 'var(--green-600)' : 'var(--slate-300)'}`, background: actif ? 'var(--green-100)' : 'var(--color-surface)', color: actif ? 'var(--green-800)' : 'var(--slate-700)' }}>
                                                {cause.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </fieldset>
                            <div><Button variant="primary" type="submit" icon={ICON.save} disabled={causes.length === 0}>Enregistrer l’explication</Button></div>
                        </form>
                    </Card>

                    <Card label="Problème ouvert">
                        {d.probleme ? (
                            <>
                                <CardHead title={`Problème ouvert · ${d.probleme.reference}`} right={<Pill tone="orange">{d.probleme.statut}</Pill>} />
                                <dl className="se-grid-2" style={{ margin: 0, fontSize: 12.5 }}>
                                    <div><dt className="lbl">Nature</dt><dd style={{ margin: '4px 0 0' }}>{d.probleme.nature}</dd></div>
                                    <div><dt className="lbl">Survenu</dt><dd className="mono" style={{ margin: '4px 0 0' }}>{fullDate(d.probleme.survenu_le)}</dd></div>
                                    <div><dt className="lbl">Impact</dt><dd style={{ margin: '4px 0 0' }}>{d.probleme.impact ?? '—'}</dd></div>
                                    <div><dt className="lbl">Lien</dt><dd style={{ margin: '4px 0 0' }}>{d.probleme.risque ? `Risque ${d.probleme.risque.reference} · désormais réalisé` : '—'}</dd></div>
                                </dl>
                                <span style={{ fontSize: 11.5, color: 'var(--color-text-subtle)' }}>Un risque est un événement potentiel ; un problème est déjà survenu.{d.probleme.risque ? ` Le risque ${d.probleme.risque.reference} a été converti en problème.` : ''}</span>
                            </>
                        ) : (
                            <form onSubmit={probleme} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                <CardHead title="Ouvrir un problème" />
                                <div className="se-grid-2">
                                    <label className="field"><span className="lbl">Nature</span><input className="inp" name="nature" required /></label>
                                    <label className="field"><span className="lbl">Survenu le</span><input className="inp" type="date" name="survenu" required /></label>
                                    <label className="field"><span className="lbl">Impact</span><input className="inp" name="impact" /></label>
                                    <label className="field"><span className="lbl">Risque réalisé</span>
                                        <select className="inp" name="risque" defaultValue="">
                                            <option value="">Aucun</option>
                                            {d.options.risques.map((r: any) => <option key={r.id} value={r.id}>{r.reference} · {r.description}</option>)}
                                        </select>
                                    </label>
                                </div>
                                <div><Button type="submit" icon={ICON.create}>Ouvrir le problème</Button></div>
                            </form>
                        )}
                    </Card>

                    <Card label="Action corrective">
                        {d.action ? (
                            <>
                                <CardHead title={d.action.reference ? `Action corrective · ${d.action.reference}` : 'Action corrective'} right={d.action.en_retard ? <Pill tone="orange" icon="clock">En retard</Pill> : <Pill tone={(ACTION_STATUT[d.action.statut] ?? ACTION_STATUT.ouverte).tone}>{(ACTION_STATUT[d.action.statut] ?? ACTION_STATUT.ouverte).label}</Pill>} />
                                <div className="se-grid-2" style={{ fontSize: 12.5 }}>
                                    {[['Anomalie', d.action.anomalie], ['Cause', d.action.cause], ['Action', d.action.description], ['Responsable', d.action.responsable], ['Début', fullDate(d.action.debut)], ['Échéance', fullDate(d.action.echeance)]].map(([libelle, valeur]) => (
                                        <div key={libelle} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}><span className="lbl">{libelle}</span><div className="ro">{valeur ?? '—'}</div></div>
                                    ))}
                                </div>
                                {d.action.en_retard && (
                                    <div style={{ display: 'flex', gap: 8, padding: '9px 12px', borderRadius: 8, background: 'var(--orange-bg)', color: 'var(--orange-fg)', fontSize: 12.5 }}>
                                        <Icon name="clock" />Échéance dépassée de {d.action.jours_retard} jour{d.action.jours_retard > 1 ? 's' : ''}{d.action.resultat_attendu ? ` · preuve de clôture attendue : ${d.action.resultat_attendu}` : ''}.
                                    </div>
                                )}
                                <Link to="/suivi/actions" style={{ fontSize: 12.5 }}>Mettre à jour l’avancement →</Link>
                            </>
                        ) : (
                            <form onSubmit={action} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                <CardHead title="Action corrective" right={<Pill tone="ambre">Obligatoire</Pill>} />
                                <div className="se-grid-2">
                                    <label className="field"><span className="lbl">Anomalie</span><input className="inp" name="anomalie" required defaultValue={`Écart physique / financier de ${Math.abs(Math.round(activite.ecart ?? 0))} points`} /></label>
                                    <label className="field"><span className="lbl">Cause</span><input className="inp" name="cause" required /></label>
                                    <label className="field" style={{ gridColumn: '1 / -1' }}><span className="lbl">Action</span><input className="inp" name="action" required /></label>
                                    <label className="field"><span className="lbl">Responsable</span><input className="inp" name="responsable" required defaultValue={activite.responsable ?? ''} /></label>
                                    <label className="field"><span className="lbl">Preuve de clôture attendue</span><input className="inp" name="resultat" /></label>
                                    <label className="field"><span className="lbl">Début</span><input className="inp" type="date" name="debut" required defaultValue={new Date().toISOString().slice(0, 10)} /></label>
                                    <label className="field"><span className="lbl">Échéance</span><input className="inp" type="date" name="echeance" required /></label>
                                </div>
                                <div><Button variant="primary" type="submit" icon={ICON.save}>Enregistrer l’action corrective</Button></div>
                            </form>
                        )}
                    </Card>
                </div>

                <aside style={{ display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 }}>
                    <Card label="Finances">
                        <CardHead title="Finances" tag={<Tag kind="chaine" />} />
                        {[['Budget révisé', f.budget_revise, null], ['Engagé', f.engage, f.taux_engagement], ['Liquidé', f.liquide, f.taux_liquide], ['Payé', f.paye, f.taux_paye]].map(([libelle, montant, taux]) => (
                            <div key={String(libelle)} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', fontSize: 12.5 }}>
                                <span style={{ color: 'var(--slate-500)' }}>{libelle}</span>
                                <span><b className="mono">{money(montant as number)}</b>{taux !== null && taux !== undefined ? <span className="mono" style={{ marginLeft: 8, color: 'var(--color-text-subtle)' }}>{pct(taux as number, 0)}</span> : null}</span>
                            </div>
                        ))}
                        <span style={{ fontSize: 11.5, color: 'var(--color-text-subtle)' }}>Données lues en temps réel dans la chaîne de dépense, jamais ressaisies.</span>
                    </Card>
                    <Card label="Notifications envoyées">
                        <CardHead title="Notifications envoyées" />
                        {d.notifications.length === 0 && <span className="muted" style={{ fontSize: 12.5 }}>Aucune notification.</span>}
                        <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 10 }}>
                            {d.notifications.map((row: any, index: number) => (
                                <li key={index} style={{ display: 'grid', gridTemplateColumns: '44px minmax(0, 1fr)', gap: 8, fontSize: 12.5 }}>
                                    <span className="mono" style={{ color: 'var(--color-text-subtle)' }}>{dayMonth(row.le)}</span>
                                    <span>{row.message}{row.destinataire && <span style={{ display: 'block', fontSize: 11, color: 'var(--color-text-subtle)' }}>{row.destinataire}</span>}</span>
                                </li>
                            ))}
                        </ol>
                    </Card>
                    <Card label="Prochain rapport">
                        <CardHead title="Prochain rapport" />
                        <span style={{ fontSize: 12.5, color: 'var(--slate-700)', lineHeight: 1.5 }}>
                            L’écart, son explication, le problème et l’état de l’action corrective seront repris automatiquement dans le{' '}
                            <Link to="/suivi/rapports">rapport {d.prochain_rapport ? `de la période ${d.prochain_rapport}` : 'périodique'}</Link> et la{' '}
                            <Link to="/suivi/synthese">synthèse exécutive</Link>.
                        </span>
                    </Card>
                </aside>
            </div>
        </main>
    );
}
