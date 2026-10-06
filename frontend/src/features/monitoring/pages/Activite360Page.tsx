import { FormEvent, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton, useDialogs, useToast } from '../../../components/ui';
import { libelleCode } from '../../../utils/format';
import IndicatorEvolutionChart from '../components/IndicatorEvolutionChart';
import {
    ACTIVITY_STATUS,
    APPRECIATION,
    APPRECIATION_COLOR,
    BarRow,
    Card,
    CardHead,
    GapBox,
    Icon,
    MiniBar,
    PERFORMANCE,
    PerformancePill,
    Pill,
    StripCell,
    Tag,
    TONES,
    dayMonth,
    errorMessage,
    fmt,
    fullDate,
    money,
    pct,
} from '../components/se';

/** Écran 2 de docs/maquette-SE : fiche activité 360°. */
const JALON: Record<string, { label: string; tone: 'vert' | 'orange' | 'gris' }> = {
    franchi: { label: 'Franchi', tone: 'vert' },
    en_retard: { label: 'En retard', tone: 'orange' },
    non_renseigne: { label: 'Non renseigné', tone: 'gris' },
};
const TYPE_INDICATEUR: Record<string, string> = {
    intrant: 'Intrant', activite: 'Activité', produit: 'Produit', resultat: 'Résultat', effet: 'Effet', impact: 'Impact',
    financier: 'Financier', physique: 'Physique', delai: 'Délai', qualite: 'Qualité',
};
const SENS: Record<string, string> = { croissant: 'sens croissant', decroissant: 'sens décroissant', binaire: 'binaire', qualitatif: 'qualitatif' };

export default function Activite360Page() {
    const { id } = useParams();
    const [fiche, setFiche] = useState<any>(null);
    const [dossier, setDossier] = useState<any>(null);
    const [courbe, setCourbe] = useState<number | null>(null);
    const [risque, setRisque] = useState(false);
    const [jalon, setJalon] = useState(false);
    const [personnes, setPersonnes] = useState<Array<{ id: number; name: string }>>([]);
    const [responsableId, setResponsableId] = useState('');
    const [designation, setDesignation] = useState(false);
    const toast = useToast();
    const { prompt } = useDialogs();
    const setMessage = (text: string) => toast.success(text);
    const [erreur, setErreur] = useState('');

    function charger() {
        api.get(`/suivi/activites/${id}/fiche`).then((response) => setFiche(response.data.data)).catch((error) => setErreur(errorMessage(error, 'Fiche inaccessible.')));
        api.get(`/suivi/activites/${id}`).then((response) => setDossier(response.data.data)).catch(() => setDossier(null));
    }

    useEffect(charger, [id]);

    useEffect(() => {
        if (!id) {
            return;
        }
        api.get(`/suivi/activites/${id}/responsables`)
            .then((response) => setPersonnes(response.data.data))
            .catch(() => setPersonnes([]));
    }, [id]);

    useEffect(() => {
        const courant = fiche?.activite?.responsable_id;
        if (courant) {
            setResponsableId(String(courant));
        }
    }, [fiche]);

    async function designer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setDesignation(true);
        setErreur('');
        try {
            await api.patch(`/suivi/activites/${id}/pilotage`, { responsible_user_id: Number(responsableId) });
            toast.success('Responsable désigné.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        } finally {
            setDesignation(false);
        }
    }

    async function signalerRisque(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        try {
            await api.post('/suivi/risques', {
                pap_enrichment_id: Number(id),
                description: form.get('description'),
                category: form.get('categorie'),
                probability: Number(form.get('probabilite')),
                impact: Number(form.get('impact')),
                responsible_role: form.get('responsable'),
                prevention: form.get('prevention') || null,
            });
            setRisque(false);
            setMessage('Risque enregistré.');
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    async function ajouterJalon(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        try {
            await api.post(`/suivi/activites/${id}/jalons`, { label: form.get('libelle'), planned_on: form.get('prevu') });
            setJalon(false);
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    async function franchir(row: any) {
        const values = await prompt({
            title: 'Déclarer le jalon franchi',
            description: row.libelle,
            confirmLabel: 'Déclarer franchi',
            tone: 'success',
            fields: [
                { name: 'reel', label: 'Date réelle', type: 'date', required: true, defaultValue: new Date().toISOString().slice(0, 10) },
                { name: 'preuve', label: 'Preuve', required: true, placeholder: 'PV, rapport, référence d’acte…' },
            ],
        });
        if (!values) return;
        const { reel, preuve } = values;
        try {
            await api.patch(`/suivi/jalons/${row.id}`, { achieved_on: reel, proof_label: preuve });
            charger();
        } catch (error) {
            setErreur(errorMessage(error));
        }
    }

    if (!fiche) {
        return erreur ? <PageError message={erreur} onRetry={charger} /> : <PageSkeleton variant="detail" />;
    }

    const activite = fiche.activite;
    const bandeau = fiche.bandeau;
    const statut = ACTIVITY_STATUS[activite.statut] ?? ACTIVITY_STATUS.non_demarree;
    const appreciation = bandeau.appreciation;
    const indicateursEnRetard = bandeau.indicateurs_en_retard > 0;
    const choix = activite.responsable_id && !personnes.some((personne) => personne.id === activite.responsable_id)
        ? [{ id: activite.responsable_id, name: activite.responsable ?? 'Responsable actuel' }, ...personnes]
        : personnes;

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/suivi', label: 'Tableau de bord' }}
                eyebrow={(
                    <>
                        <span className="mono strong">{activite.code ?? `ACT-${activite.id}`}</span>
                        <Pill tone={statut.tone} size="md" dot>{statut.label}</Pill>
                        <Pill tone={activite.nature === 'pap' ? 'pap' : 'marine'}>{activite.nature === 'pap' ? 'PAP' : 'Hors PAP'}</Pill>
                        <Pill tone="marine">Vue 360°</Pill>
                    </>
                )}
                title={activite.activite}
                meta={(
                    <span className="cluster subtle" style={{ gap: '4px 18px' }}>
                        <span>Pilier <b>{activite.pilier ?? '—'}</b></span>
                        <span>Produit <b>{activite.produit ?? '—'}</b></span>
                        <span>Responsable <b>{activite.responsable ?? activite.unite_responsable ?? 'Non désigné'}{activite.structure ? ` · ${activite.structure}` : ''}</b></span>
                        <span>Ligne <b className="mono">{activite.ligne ?? '—'}</b></span>
                    </span>
                )}
                actions={<>
                    <Button to={`/suivi/activites/${activite.id}/gantt`} icon={ICON.gantt}>Gantt</Button>
                    <Button icon={ICON.warning} onClick={() => setRisque(!risque)} aria-expanded={risque}>Signaler un risque</Button>
                    <Button variant="primary" to={`/suivi/saisie?activite=${activite.id}`} icon={ICON.edit}>Saisir une réalisation</Button>
                </>}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <form className="card" onSubmit={designer} style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'end', flexWrap: 'wrap' }}>
                <label className="field" style={{ flex: '1 1 280px', margin: 0 }}>
                    <span className="lbl">Responsable de l’activité</span>
                    <select className="inp" value={responsableId} onChange={(event) => setResponsableId(event.target.value)} required>
                        <option value="">Choisir une personne</option>
                        {choix.map((personne) => <option key={personne.id} value={personne.id}>{personne.name}</option>)}
                    </select>
                </label>
                <Button variant="primary" type="submit" icon={ICON.users} loading={designation}>Désigner</Button>
            </form>

            {risque && (
                <form className="card se-grid-3" style={{ padding: 16 }} onSubmit={signalerRisque}>
                    <label className="field" style={{ gridColumn: '1 / -1' }}><span className="lbl">Description du risque</span><input className="inp" name="description" required /></label>
                    <label className="field"><span className="lbl">Catégorie</span>
                        <select className="inp" name="categorie" defaultValue="technique">
                            {['financiere', 'administrative', 'technique', 'contractuelle', 'rh', 'logistique', 'reglementaire', 'fournisseur', 'externe', 'autre'].map((cle) => <option key={cle} value={cle}>{cle}</option>)}
                        </select>
                    </label>
                    <label className="field"><span className="lbl">Probabilité</span>
                        <select className="inp" name="probabilite" defaultValue="2">{['Rare', 'Possible', 'Probable', 'Très probable'].map((libelle, index) => <option key={libelle} value={index + 1}>{libelle}</option>)}</select>
                    </label>
                    <label className="field"><span className="lbl">Impact</span>
                        <select className="inp" name="impact" defaultValue="2">{['Faible', 'Modéré', 'Fort', 'Majeur'].map((libelle, index) => <option key={libelle} value={index + 1}>{libelle}</option>)}</select>
                    </label>
                    <label className="field"><span className="lbl">Responsable (rôle)</span><input className="inp" name="responsable" defaultValue="directeur" required /></label>
                    <label className="field" style={{ gridColumn: 'span 2' }}><span className="lbl">Mesure préventive</span><input className="inp" name="prevention" /></label>
                    <div style={{ alignSelf: 'end' }}><Button variant="primary" type="submit" icon={ICON.save}>Enregistrer le risque</Button></div>
                </form>
            )}

            <section className="card se-strip-6">
                <StripCell
                    label="Début prévu · réel"
                    value={`${dayMonth(bandeau.debut_prevu)} · ${dayMonth(bandeau.debut_reel)}`}
                    hint={bandeau.retard_demarrage !== null ? `${bandeau.retard_demarrage > 0 ? '+' : ''}${bandeau.retard_demarrage} j` : 'non démarrée'}
                />
                <StripCell
                    label="Fin prévue"
                    value={fullDate(bandeau.fin_prevue)}
                    hint={bandeau.jours_restants !== null ? `${bandeau.jours_restants} j restants` : bandeau.retard_jours > 0 ? `${bandeau.retard_jours} j de retard` : undefined}
                />
                <StripCell label="Avancement physique" value={pct(bandeau.physique, 0)} hint={bandeau.methode_physique} color="var(--green-600)" />
                <StripCell label="Exécution financière" value={pct(bandeau.financier)} hint="engagé / révisé" color="var(--navy-900)" />
                <StripCell
                    label="Indicateurs"
                    value={`${bandeau.indicateurs_atteints} / ${bandeau.indicateurs_total} atteints`}
                    hint={indicateursEnRetard ? `${bandeau.indicateurs_en_retard} en retard` : undefined}
                    color={indicateursEnRetard ? 'var(--orange-fg)' : 'var(--success-fg)'}
                    mono={false}
                />
                <StripCell label="Score de performance" value={`${fmt(bandeau.score)} / 100`} hint={APPRECIATION[appreciation]} color={APPRECIATION_COLOR[appreciation]} />
            </section>

            <div className="se-grid-360">
                <div style={{ display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 }}>
                    <Card label="Physique et financier">
                        <CardHead title="Physique et financier" tag={<Tag kind="chaine" />} />
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            <BarRow label="Exécution physique" value={activite.physique} color="var(--green-600)" />
                            <BarRow label="Exécution financière (engagé)" value={activite.financier} color="var(--navy-900)" />
                            <GapBox gap={activite.ecart} level={activite.niveau_ecart} thresholds={activite.seuils_ecart} />
                        </div>
                        {fiche.explication ? (
                            <div style={{ display: 'flex', gap: 10, padding: '10px 12px', borderRadius: 8, background: 'var(--slate-50)', fontSize: 12.5, color: 'var(--slate-700)' }}>
                                <Icon name="comment" color="var(--slate-500)" />
                                <span>
                                    <b>Explication enregistrée</b> par {fiche.explication.auteur ?? '—'} le {dayMonth(fiche.explication.le)}
                                    {fiche.explication.causes?.length ? <> · cause <b>{fiche.explication.causes.join(', ')}</b></> : null} : {fiche.explication.texte}
                                </span>
                            </div>
                        ) : activite.niveau_ecart !== 'normal' && fiche.ecart_ouvert ? (
                            <Link to={`/suivi/ecarts/${fiche.ecart_ouvert}`} style={{ fontSize: 12.5 }}>Explication attendue · ouvrir le dossier d’écart</Link>
                        ) : null}
                    </Card>

                    <Card label="Tâches et pondération" padded={false}>
                        <CardHead padded title="Tâches et pondération" tag={<Tag kind="referentiel" />} right={<span style={{ fontSize: 11.5, color: 'var(--slate-500)' }}>méthode : moyenne pondérée</span>} />
                        <div className="se-scroll">
                            <table className="tbl">
                                <thead>
                                    <tr><th>Code</th><th>Tâche</th><th className="r">Poids</th><th>Unité</th><th>Réalisé</th><th>Avancement</th><th className="r">Contribution</th><th>Statut</th></tr>
                                </thead>
                                <tbody>
                                    {fiche.taches.length === 0 && <tr><td colSpan={8} className="muted">Aucune tâche programmée.</td></tr>}
                                    {fiche.taches.map((tache: any) => (
                                        <tr key={tache.id}>
                                            <td className="mono" style={{ fontSize: 11, color: 'var(--warning-fg)', fontWeight: 700 }}>{tache.code}</td>
                                            <td style={{ fontWeight: 500 }}>{tache.libelle}</td>
                                            <td className="r mono">{pct(tache.poids, 0)}</td>
                                            <td style={{ fontSize: 12, color: 'var(--slate-500)' }}>{[tache.prevu !== null ? fmt(tache.prevu) : null, tache.unite].filter(Boolean).join(' ') || '—'}</td>
                                            <td className="mono" style={{ fontSize: 12 }}>{tache.realise !== null ? `${fmt(tache.realise)} / ${fmt(tache.prevu)}` : '—'}</td>
                                            <td style={{ width: 160 }}><MiniBar value={tache.avancement} color={TONES[PERFORMANCE[tache.statut]?.tone ?? 'gris'].dot} /></td>
                                            <td className="r mono" style={{ fontWeight: 600 }}>{fmt(tache.contribution, 2)}</td>
                                            <td><PerformancePill status={tache.statut} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0 18px 14px', fontSize: 12.5 }}>
                            <span style={{ color: 'var(--slate-500)' }}>Avancement physique pondéré de l’activité</span>
                            <b className="mono">{pct(fiche.avancement_pondere, 2)}</b>
                        </div>
                    </Card>

                    <Card label="Indicateurs" padded={false}>
                        <CardHead padded title="Indicateurs" tag={<Tag kind="referentiel" />} />
                        <div className="se-scroll">
                            <table className="tbl">
                                <thead>
                                    <tr><th>Code</th><th>Indicateur</th><th>Référence</th><th>Cible {new Date().getFullYear()}</th><th>Réalisé</th><th>Taux</th><th>Statut</th></tr>
                                </thead>
                                <tbody>
                                    {fiche.indicateurs.length === 0 && <tr><td colSpan={7} className="muted">Aucun indicateur rattaché.</td></tr>}
                                    {fiche.indicateurs.map((row: any) => (
                                        <tr key={row.id}>
                                            <td className="mono" style={{ fontSize: 11.5, fontWeight: 600 }}>
                                                <Button variant="link" aria-expanded={courbe === row.id} onClick={() => setCourbe(courbe === row.id ? null : row.id)} title="Afficher l’évolution">{row.code}</Button>
                                            </td>
                                            <td>
                                                <div style={{ fontWeight: 500 }}>{row.libelle}</div>
                                                <div style={{ fontSize: 11, color: 'var(--color-text-subtle)' }}>{[TYPE_INDICATEUR[row.type] ?? row.type, row.frequence ? row.frequence.charAt(0).toUpperCase() + row.frequence.slice(1) : null, SENS[row.sens] ?? row.sens].filter(Boolean).join(' · ')}</div>
                                            </td>
                                            <td className="mono">{row.reference !== null ? `${fmt(row.reference)}${row.unite === '%' ? ' %' : ''}${row.reference_annee ? ` (${row.reference_annee})` : ''}` : '—'}</td>
                                            <td className="mono" style={{ fontWeight: 700 }}>{row.cible !== null ? `${fmt(row.cible)}${row.unite === '%' ? ' %' : ''}` : '—'}</td>
                                            <td className="mono" style={{ fontWeight: 700 }}>{row.realise !== null ? `${fmt(row.realise)}${row.unite === '%' ? ' %' : ''}` : '—'}</td>
                                            <td style={{ width: 130 }}><MiniBar value={row.taux} color={TONES[PERFORMANCE[row.statut]?.tone ?? 'gris'].dot} /></td>
                                            <td><PerformancePill status={row.statut} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {courbe !== null && <div style={{ padding: '0 18px 16px' }}><IndicatorEvolutionChart indicatorId={courbe} /></div>}
                    </Card>

                    <Card label="Jalons" padded={false}>
                        <CardHead padded title="Jalons" right={<Button size="sm" variant="ghost" icon={ICON.create} onClick={() => setJalon(!jalon)} aria-expanded={jalon}>Ajouter un jalon</Button>} />
                        {jalon && (
                            <form style={{ display: 'flex', gap: 8, padding: '0 18px', flexWrap: 'wrap' }} onSubmit={ajouterJalon}>
                                <input className="inp" name="libelle" placeholder="Libellé du jalon" required style={{ flex: '1 1 220px' }} />
                                <input className="inp" name="prevu" type="date" required style={{ width: 170 }} aria-label="Date prévue" />
                                <Button variant="primary" type="submit" icon={ICON.create}>Ajouter</Button>
                            </form>
                        )}
                        <div className="se-scroll">
                            <table className="tbl">
                                <thead><tr><th>Jalon</th><th>Prévu</th><th>Réel</th><th>Statut</th><th>Preuve</th></tr></thead>
                                <tbody>
                                    {fiche.jalons.length === 0 && <tr><td colSpan={5} className="muted">Aucun jalon défini.</td></tr>}
                                    {fiche.jalons.map((row: any) => {
                                        const meta = JALON[row.statut] ?? JALON.non_renseigne;

                                        return (
                                            <tr key={row.id}>
                                                <td style={{ fontWeight: 500 }}>{row.libelle}</td>
                                                <td className="mono">{dayMonth(row.prevu)}</td>
                                                <td className="mono">{dayMonth(row.reel)}</td>
                                                <td><Pill tone={meta.tone}>{meta.label}</Pill></td>
                                                <td style={{ fontSize: 12, color: 'var(--slate-700)' }}>
                                                    {row.preuve ?? (row.statut !== 'franchi' ? <Button size="sm" variant="ghost" icon={ICON.validate} onClick={() => franchir(row)}>Déclarer franchi</Button> : '—')}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </div>

                <aside style={{ display: 'flex', flexDirection: 'column', gap: 16, minWidth: 0 }} aria-label="Compléments de la fiche">
                    <Card label="Risques">
                        <CardHead title="Risques" right={<span className="mono" style={{ fontSize: 12, color: 'var(--slate-500)' }}>{fiche.risques.length}</span>} />
                        {fiche.risques.length === 0 && <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>Aucun risque enregistré.</p>}
                        {fiche.risques.map((row: any) => (
                            <div key={row.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, fontSize: 12.5 }}>
                                <span><span className="mono" style={{ fontWeight: 600 }}>{row.reference}</span> · {row.description}</span>
                                <Pill tone={row.criticite === 'critique' ? 'rouge' : row.criticite === 'eleve' ? 'orange' : row.criticite === 'modere' ? 'ambre' : 'vert'}>{row.criticite}</Pill>
                            </div>
                        ))}
                    </Card>
                    {dossier && (
                        <>
                            <Card label="Finances · temps réel">
                                <CardHead title="Finances · temps réel" tag={<Tag kind="chaine" />} />
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 16px', fontSize: 12.5 }}>
                                    {[
                                        ['Budget initial', dossier.finances?.budget_initial],
                                        ['Budget révisé', dossier.finances?.budget_revise],
                                        ['Engagé', dossier.finances?.engage],
                                        ['Liquidé', dossier.finances?.liquide],
                                        ['Ordonnancé', dossier.finances?.ordonnance],
                                        ['Payé', dossier.finances?.paye],
                                        ['Disponible', dossier.finances?.disponible],
                                    ].map(([libelle, montant]) => (
                                        <div key={String(libelle)} style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                                            <span style={{ color: 'var(--slate-500)' }}>{libelle}</span>
                                            <span className="mono" style={{ fontWeight: 700 }}>{money(montant)}</span>
                                        </div>
                                    ))}
                                </div>
                            </Card>
                            <Card label="Score de performance">
                                <CardHead title="Score de performance" right={<span className="mono" style={{ fontWeight: 700 }}>{fmt(dossier.score?.score, 1)} / 100</span>} />
                                {(dossier.score?.composantes ?? []).map((ligne: any) => (
                                    <div key={ligne.code} style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto auto', gap: 8, fontSize: 12 }}>
                                        <span>{ligne.libelle}</span>
                                        <span className="mono" style={{ color: 'var(--slate-500)' }}>{pct(ligne.taux, 0)} × {ligne.poids}</span>
                                        <span className="mono" style={{ fontWeight: 700 }}>{fmt(ligne.points, 1)}</span>
                                    </div>
                                ))}
                            </Card>
                            <Card label="Chaîne de dépense">
                                <CardHead title="Chaîne de dépense liée" tag={<Tag kind="chaine" />} />
                                {dossier.chaine.length === 0 && <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>Aucun acte sur la ligne.</p>}
                                {dossier.chaine.map((row: any) => (
                                    <div key={row.reference} style={{ display: 'grid', gridTemplateColumns: '40px minmax(0, 1fr) auto', gap: 8, fontSize: 12 }}>
                                        <span className="mono" style={{ fontWeight: 700, color: 'var(--navy-900)' }}>{row.maillon}</span>
                                        <span className="mono" style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{row.reference}</span>
                                        <span className="mono">{money(row.montant)}</span>
                                    </div>
                                ))}
                            </Card>
                            <Card label="Documents">
                                <CardHead title="Preuves · GED" />
                                {dossier.preuves.length === 0 && <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>Aucune preuve versée.</p>}
                                {dossier.preuves.map((row: any) => (
                                    <div key={row.id} style={{ fontSize: 12 }}><Icon name="file" size={13} color="var(--slate-500)" style={{ verticalAlign: 'middle' }} /> {row.category} · <span className="mono">{String(row.sha256).slice(0, 12)}</span> · {row.confidentiality}</div>
                                ))}
                            </Card>
                            <Card label="Historique">
                                <CardHead title="Historique" />
                                {dossier.historique.length === 0 && <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>Aucun événement.</p>}
                                {dossier.historique.slice(0, 12).map((row: any) => (
                                    <div key={row.id} style={{ fontSize: 12, color: 'var(--slate-700)' }}>{fullDate(row.created_at)} · {libelleCode(String(row.action).replace(/^se\./, ''))}{row.motif ? ` · ${row.motif}` : ''}</div>
                                ))}
                            </Card>
                        </>
                    )}
                </aside>
            </div>
        </main>
    );
}
