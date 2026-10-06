import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, EmptyState, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton } from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf } from '../../../utils/format';
import {
    APPRECIATION,
    Card,
    CardHead,
    Donut,
    FilterSelect,
    GapBox,
    HEAT,
    Icon,
    PERFORMANCE,
    Pager,
    Pill,
    StatTile,
    Tag,
    TONES,
    BarRow,
    pct,
    relativeDay,
} from '../components/se';

/** Écran 1 de docs/maquette-SE : tableau de bord Suivi-Évaluation. */
const STATUS_ORDER = ['atteint', 'en_bonne_voie', 'a_surveiller', 'en_retard', 'critique', 'non_renseigne'];
const STATUS_BAR: Record<string, string> = {
    atteint: 'var(--success-solid)',
    en_bonne_voie: 'var(--info-solid)',
    a_surveiller: 'var(--warning-solid)',
    en_retard: 'var(--orange-solid)',
    critique: 'var(--danger-solid)',
    non_renseigne: 'var(--slate-300)',
};
const STATUT_LIBELLE: Record<string, string> = {
    non_demarree: 'Non démarrée',
    planifiee: 'Planifiée',
    en_cours: 'En cours',
    en_retard: 'En retard',
    suspendue: 'Suspendue',
    bloquee: 'Bloquée',
    realisee: 'Réalisée',
    cloturee: 'Clôturée',
    annulee: 'Annulée',
};

type Filtres = Record<string, string>;

export default function SuiviDashboardPage() {
    const [filtres, setFiltres] = useState<Filtres>({});
    const [board, setBoard] = useState<any>(null);
    const [taches, setTaches] = useState<any[]>([]);
    const [tri, setTri] = useState<'score' | 'structure'>('score');
    const [attentionPage, setAttentionPage] = useState(1);
    const [erreur, setErreur] = useState('');
    const [exportError, setExportError] = useState('');
    const [exporting, setExporting] = useState(false);

    useEffect(() => {
        setAttentionPage(1);
    }, [filtres]);

    useEffect(() => {
        setErreur('');
        api.get('/suivi/pilotage', { params: { ...filtres, attention_page: attentionPage } })
            .then((response) => setBoard(response.data.data))
            .catch(() => setErreur('Le tableau de bord n’a pas pu être chargé.'));
    }, [filtres, attentionPage]);

    useEffect(() => {
        api.get('/taches', { params: { module: 'se', tri: 'echeance' } }).then((response) => setTaches(response.data.data.slice(0, 5))).catch(() => setTaches([]));
    }, []);

    const structures = useMemo(() => {
        const rows = [...(board?.structures ?? [])];

        return tri === 'score' ? rows.sort((a, b) => b.score - a.score) : rows.sort((a, b) => a.structure.localeCompare(b.structure));
    }, [board, tri]);

    async function exporter() {
        setExporting(true);
        setExportError('');
        try {
            await telecharger('/suivi/rapports', 'suivi-evaluation.xlsx', { format: 'xlsx' });
        } catch (caught) {
            setExportError(errorsOf(caught));
        } finally {
            setExporting(false);
        }
    }

    function filtrer(cle: string, valeur: string) {
        setFiltres((courant) => {
            const suivant = { ...courant, [cle]: valeur };
            if (!valeur) delete suivant[cle];

            return suivant;
        });
    }

    if (!board) {
        return erreur ? <PageError message={erreur} onRetry={() => setFiltres({ ...filtres })} /> : <PageSkeleton />;
    }

    const options = board.filtres;
    const bandeau = board.bandeau;
    const execution = board.execution;
    const suivie = board.execution_suivie;
    const perimetreSuivi = suivie.activites > 0 && suivie.activites < bandeau.activites;
    const affiche = perimetreSuivi ? suivie : execution;
    const indicateurs = board.indicateurs;
    const evolution: any[] = board.evolution;
    const valeur = (mois: any, cle: 'physique' | 'engage') => perimetreSuivi ? (mois[`${cle}_suivi`] ?? 0) : mois[cle];
    const max = Math.max(100, ...evolution.flatMap((mois) => [valeur(mois, 'physique'), valeur(mois, 'engage')]));
    const ganttCible = board.attention[0]?.id ?? board.activites[0]?.id;
    const critiques = board.attention.filter((row: any) => row.gravite >= 3).length;

    return (
        <main className="app-content">
            <PageHeader
                title="Suivi-Évaluation"
                subtitle="Les ressources mobilisées produisent-elles les résultats programmés, dans les délais et aux coûts prévus ?"
                actions={(
                    <>
                        <Button to="/suivi/synthese" icon={ICON.synthesis}>Synthèse exécutive</Button>
                        <Button to={ganttCible ? `/suivi/activites/${ganttCible}/gantt` : '/suivi/gantt'} icon={ICON.gantt}>Gantt</Button>
                        <Button to="/suivi/rapports" icon={ICON.report}>Rapports</Button>
                        <Button icon={ICON.export} loading={exporting} onClick={exporter}>Exporter Excel</Button>
                    </>
                )}
            />
            <ErrorMessage error={exportError} onClose={() => setExportError('')} />

            <div className="cluster" role="group" aria-label="Filtres">
                <FilterSelect label="Exercice" value={filtres.exercice ?? ''} onChange={(v) => filtrer('exercice', v)} options={options.exercices.map((annee: number) => ({ value: String(annee), label: String(annee) }))} allLabel="Tous" />
                <FilterSelect label="Période" value={filtres.periode ?? ''} onChange={(v) => filtrer('periode', v)} options={options.periodes.map((p: any) => ({ value: String(p.id), label: p.label }))} allLabel={`Au ${new Date().toLocaleDateString('fr-FR')}`} />
                <FilterSelect label="Département" value={filtres.structure ?? ''} onChange={(v) => filtrer('structure', v)} options={options.departements.map((d: any) => ({ value: String(d.id), label: d.sigle }))} />
                <FilterSelect label="Programme" value={filtres.programme ?? ''} onChange={(v) => filtrer('programme', v)} options={options.programmes.map((p: string) => ({ value: p, label: p }))} />
                <FilterSelect label="Pilier" value={filtres.pilier ?? ''} onChange={(v) => filtrer('pilier', v)} options={options.piliers.map((p: string) => ({ value: p, label: p }))} />
                <FilterSelect label="Type" value={filtres.type ?? ''} onChange={(v) => filtrer('type', v)} options={Object.entries(options.types).map(([value, label]) => ({ value, label: String(label) }))} allLabel="PAP + Hors PAP" />
                <FilterSelect label="Statut" value={filtres.statut ?? ''} onChange={(v) => filtrer('statut', v)} options={options.statuts.map((s: string) => ({ value: s, label: STATUT_LIBELLE[s] ?? s }))} />
                <FilterSelect label="Responsable" value={filtres.responsable ?? ''} onChange={(v) => filtrer('responsable', v)} options={options.responsables.map((r: any) => ({ value: String(r.id), label: r.nom }))} />
            </div>
            <ErrorMessage error={erreur} title="Actualisation impossible" />

            <div className="card se-strip">
                <StatTile label="Activités suivies" value={bandeau.activites} hint={`PAP ${bandeau.pap} · Hors PAP ${bandeau.hors_pap}`} color="var(--navy-900)" onClick={() => filtrer('statut', '')} active={!filtres.statut} />
                <StatTile label="En cours" value={bandeau.en_cours} color="var(--info-fg)" onClick={() => filtrer('statut', 'en_cours')} active={filtres.statut === 'en_cours'} />
                <StatTile label="Terminées" value={bandeau.terminees} color="var(--success-solid)" onClick={() => filtrer('statut', 'realisee')} active={filtres.statut === 'realisee'} />
                <StatTile label="En retard" value={bandeau.en_retard} hint={bandeau.retard_plus_30 ? `dont ${bandeau.retard_plus_30} > 30 jours` : undefined} color="var(--orange-solid)" onClick={() => filtrer('statut', 'en_retard')} active={filtres.statut === 'en_retard'} />
                <StatTile label="Bloquées" value={bandeau.bloquees} color="var(--danger-solid)" onClick={() => filtrer('statut', 'bloquee')} active={filtres.statut === 'bloquee'} />
                <StatTile label="Risques critiques" value={bandeau.risques_critiques} hint={bandeau.risques_non_traites ? `dont ${bandeau.risques_non_traites} non traités` : undefined} color="var(--danger-solid)" to="/suivi/actions?onglet=risques" />
                <StatTile label="Recommandations en retard" value={bandeau.recommandations_en_retard} hint={`sur ${bandeau.recommandations_ouvertes} ouvertes`} color="var(--orange-fg)" to="/suivi/actions?onglet=recommandations" />
            </div>

            <div className="se-grid-dash">
                <Card label={perimetreSuivi ? 'Physique et financier · activités suivies' : 'Physique et financier · PAP'}>
                    <CardHead title={perimetreSuivi ? 'Physique et financier · activités suivies' : 'Physique et financier · PAP'} tag={<Tag kind="chaine" />} />
                    <div style={{ display: 'flex', justifyContent: 'space-around', padding: '4px 0', flexWrap: 'wrap', gap: 8 }}>
                        <Donut value={affiche.physique} color="var(--green-600)" label="Physique" />
                        <Donut value={affiche.engage} color="var(--navy-900)" label="Engagé" />
                        <Donut value={affiche.paye} color="var(--gold-500)" label="Payé" />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                        <BarRow label="Exécution physique" value={affiche.physique} color="var(--green-600)" />
                        <BarRow label="Exécution financière (engagé)" value={affiche.engage} color="var(--navy-900)" />
                        <GapBox gap={affiche.ecart} level={affiche.niveau} thresholds={affiche.seuils} />
                    </div>
                    {perimetreSuivi && (
                        <span style={{ fontSize: 11.5, color: 'var(--slate-500)' }}>
                            {suivie.activites} activité{suivie.activites > 1 ? 's' : ''} déjà engagée{suivie.activites > 1 ? 's' : ''} ou réalisée{suivie.activites > 1 ? 's' : ''}, sur {bandeau.activites}. Le PAP complet reste à {pct(execution.physique, 2)} physique et {pct(execution.engage, 2)} engagé.
                        </span>
                    )}
                </Card>

                <Card label="Indicateurs">
                    <CardHead title="Indicateurs" right={<span className="mono" style={{ fontSize: 12, color: 'var(--slate-500)' }}>{indicateurs.total} suivis</span>} />
                    <div aria-hidden="true" style={{ display: 'flex', height: 12, borderRadius: 99, overflow: 'hidden', background: 'var(--slate-200)' }}>
                        {STATUS_ORDER.map((statut) => {
                            const nombre = indicateurs.repartition[statut] ?? 0;

                            return nombre > 0 ? <span key={statut} title={`${PERFORMANCE[statut].label} : ${nombre}`} style={{ width: `${(nombre / Math.max(1, indicateurs.total)) * 100}%`, background: STATUS_BAR[statut] }} /> : null;
                        })}
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {STATUS_ORDER.filter((statut) => statut !== 'non_renseigne' || (indicateurs.repartition[statut] ?? 0) > 0).map((statut) => {
                            const meta = PERFORMANCE[statut];

                            return (
                                <div key={statut} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: 12.5 }}>
                                    <Pill tone={meta.tone} icon={meta.icon}>{meta.label}</Pill>
                                    <span className="mono" style={{ fontWeight: 700 }}>{indicateurs.repartition[statut] ?? 0}</span>
                                </div>
                            );
                        })}
                    </div>
                    <span style={{ fontSize: 11.5, color: 'var(--slate-500)' }}>
                        Taux d’atteinte moyen <b className="mono" style={{ color: 'var(--navy-900)' }}>{pct(indicateurs.taux_moyen, 0)}</b> de la cible
                    </span>
                </Card>

                <Card label={`Évolution ${filtres.exercice ?? new Date().getFullYear()}`}>
                    <CardHead title={`Évolution ${filtres.exercice ?? new Date().getFullYear()}`} />
                    {evolution.length === 0 ? (
                        <EmptyState compact icon={ICON.monitoring} title="Aucune donnée pour l’exercice" />
                    ) : (
                        <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }} role="img" aria-label={perimetreSuivi ? 'Évolution des activités déjà suivies' : 'Évolution mensuelle de l’avancement physique cumulé et de l’engagé cumulé'}>
                            {evolution.map((mois) => (
                                <div key={mois.mois} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                                    <div style={{ height: 120, width: '100%', display: 'flex', alignItems: 'flex-end', justifyContent: 'center', gap: 3 }}>
                                        <span title={`${mois.libelle} · physique ${pct(valeur(mois, 'physique'), 0)}`} style={{ width: 12, height: `${(valeur(mois, 'physique') / max) * 120}px`, minHeight: valeur(mois, 'physique') > 0 ? 2 : 0, borderRadius: '3px 3px 0 0', background: 'var(--green-600)' }} />
                                        <span title={`${mois.libelle} · engagé ${pct(valeur(mois, 'engage'), 0)}`} style={{ width: 12, height: `${(valeur(mois, 'engage') / max) * 120}px`, minHeight: valeur(mois, 'engage') > 0 ? 2 : 0, borderRadius: '3px 3px 0 0', background: 'var(--navy-900)' }} />
                                    </div>
                                    <span style={{ fontSize: 10.5, color: 'var(--slate-500)' }}>{mois.libelle}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    <div style={{ display: 'flex', gap: 14, fontSize: 11.5, color: 'var(--slate-500)' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}><span style={{ width: 8, height: 8, borderRadius: 2, background: 'var(--green-600)' }} />{perimetreSuivi ? 'Physique des activités suivies' : 'Physique cumulé'}</span>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}><span style={{ width: 8, height: 8, borderRadius: 2, background: 'var(--navy-900)' }} />{perimetreSuivi ? 'Engagé des activités suivies' : 'Engagé cumulé'}</span>
                    </div>
                </Card>
            </div>

            <div className="se-grid-side is-narrow">
                <Card label="Performance des structures" padded={false}>
                    <CardHead
                        padded
                        title="Performance des structures"
                        tag={<Tag kind="suivi" />}
                        right={<button type="button" className="btn btn-ghost btn-sm" onClick={() => setTri(tri === 'score' ? 'structure' : 'score')}>{tri === 'score' ? 'Classer par structure' : 'Classer par score'}</button>}
                    />
                    <div className="se-scroll">
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>#</th><th>Structure</th><th style={{ textAlign: 'center' }}>Physique</th><th style={{ textAlign: 'center' }}>Financier · écart</th>
                                    <th style={{ textAlign: 'center' }}>Indicateurs</th><th style={{ textAlign: 'center' }}>Délais</th><th className="r">Score /100</th>
                                </tr>
                            </thead>
                            <tbody>
                                {structures.length === 0 && <tr><td colSpan={7} className="muted">Aucune activité dans le périmètre.</td></tr>}
                                {structures.map((row: any, index: number) => (
                                    <tr key={row.structure_id ?? row.structure}>
                                        <td className="mono" style={{ color: 'var(--color-text-subtle)' }}>{index + 1}</td>
                                        <td style={{ fontWeight: 600 }}>{row.structure}</td>
                                        <HeatCell level={row.physique_niveau}>{pct(row.physique, 0)}</HeatCell>
                                        <HeatCell level={row.ecart_niveau === 'normal' ? 'conforme' : row.ecart_niveau} sub={`écart ${Math.round(row.ecart)} pts`}>{pct(row.financier, 0)}</HeatCell>
                                        <HeatCell level={row.indicateurs_niveau}>{pct(row.indicateurs, 0)}</HeatCell>
                                        <HeatCell level={row.delais_niveau}>{row.retards === 0 ? 'Conforme' : `${row.retards} retard${row.retards > 1 ? 's' : ''}`}</HeatCell>
                                        <td className="r">
                                            <span className="mono" style={{ fontSize: 14, fontWeight: 700 }}>{Math.round(row.score)}</span>
                                            <div style={{ fontSize: 10.5, color: 'var(--slate-500)' }}>{APPRECIATION[row.appreciation]}</div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div style={{ padding: '0 18px 14px', fontSize: 11.5, color: 'var(--slate-500)' }}>Carte de chaleur : chaque niveau porte aussi une icône et sa valeur, jamais la couleur seule. Score composite paramétrable.</div>
                </Card>

                <section className="card" aria-labelledby="h-mt" style={{ padding: '14px 18px', background: 'var(--gold-100)', borderColor: 'var(--warning-border)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--warning-fg)' }}>
                        <Icon name="tasks" />
                        <h2 id="h-mt" style={{ margin: 0, fontSize: 13.5, fontWeight: 700 }}>Mes tâches Suivi-Évaluation</h2>
                    </div>
                    <div style={{ marginTop: 6 }}>
                        {taches.length === 0 && <EmptyState compact icon={ICON.success} title="Aucune action attendue">Vous êtes à jour sur le suivi-évaluation.</EmptyState>}
                        {taches.map((tache) => {
                            const echeance = relativeDay(tache.echeance);

                            return (
                                <Link key={tache.id} to={tache.lien || `/taches/${tache.id}`} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderBottom: '1px solid var(--warning-border)', color: 'var(--slate-800)' }}>
                                    <div style={{ flexGrow: 1, display: 'flex', flexDirection: 'column', gap: 1 }}>
                                        <span style={{ fontSize: 12.5, fontWeight: 700 }}>{tache.sujet}</span>
                                        <span style={{ fontSize: 11.5, color: 'var(--slate-500)' }}>{tache.objet}{tache.structure ? ` · ${tache.structure}` : ''}</span>
                                    </div>
                                    {echeance && <span className="mono" style={{ fontSize: 11.5, fontWeight: 700, color: echeance.late ? 'var(--danger-fg)' : 'var(--warning-fg)' }}>{echeance.label}{echeance.late ? ' · retard' : ''}</span>}
                                </Link>
                            );
                        })}
                    </div>
                </section>
            </div>

            <Card label="Activités nécessitant une attention">
                <CardHead
                    title="Activités nécessitant une attention"
                    tag={critiques > 0 ? <Pill tone="rouge">{critiques} critique{critiques > 1 ? 's' : ''}</Pill> : undefined}
                    right={board.activites[0] ? <Link to={`/suivi/activites/${board.activites[0].id}`} style={{ fontSize: 12.5 }}>Voir une fiche activité</Link> : undefined}
                />
                <div>
                    {board.attention.length === 0 && <EmptyState compact icon={ICON.success} title="Aucune activité à surveiller">Aucune activité en écart critique, en retard ou sous les seuils.</EmptyState>}
                    {board.attention.map((row: any) => (
                        <Link
                            key={row.id}
                            to={row.ecart_id ? `/suivi/ecarts/${row.ecart_id}` : `/suivi/activites/${row.id}`}
                            className="se-link-row"
                            style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 70px 70px minmax(150px, 210px)', gap: 12, alignItems: 'center', padding: '10px 0', borderBottom: '1px solid var(--slate-100)' }}
                        >
                            <span>
                                <span style={{ fontSize: 13, fontWeight: 600 }}>{row.activite}</span>
                                <span style={{ fontSize: 11.5, color: 'var(--color-text-subtle)' }}> · {row.structure}</span>
                            </span>
                            <span className="mono" style={{ fontSize: 12 }}><span style={{ color: 'var(--slate-500)' }}>Phy. </span>{pct(row.physique, 0)}</span>
                            <span className="mono" style={{ fontSize: 12 }}><span style={{ color: 'var(--slate-500)' }}>Fin. </span>{pct(row.financier, 0)}</span>
                            <span style={{ fontSize: 11.5, fontWeight: 600, color: 'var(--danger-fg)', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                <Icon name={row.type === 'retard' ? 'retard' : 'alert'} size={13} color="var(--danger-fg)" /> {row.motif}
                            </span>
                        </Link>
                    ))}
                </div>
                <Pager meta={board.attention_meta} onPage={setAttentionPage} />
            </Card>
        </main>
    );
}

function HeatCell({ level, children, sub }: { level: string; children: React.ReactNode; sub?: string }) {
    const meta = HEAT[level] ?? HEAT.non_renseigne;
    const palette = TONES[meta.tone];

    return (
        <td style={{ background: palette.bg, color: palette.fg, textAlign: 'center' }}>
            <span className="mono" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontWeight: 700 }}>
                <Icon name={meta.icon} size={12} color={palette.fg} />
                {children}
            </span>
            {sub && <div style={{ fontSize: 10 }}>{sub}</div>}
        </td>
    );
}
