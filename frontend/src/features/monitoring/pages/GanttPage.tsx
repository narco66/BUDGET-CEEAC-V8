import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    Drawer,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    KeyValueList,
    Modal,
    PageHeader,
    PageSkeleton,
    SectionCard,
    Segmented,
    StatStrip,
    StripCell,
    useDialogs,
    useToast,
    type Column,
} from '../../../components/ui';
import GanttChart, { type GanttJalon, type GanttPlan, type GanttTache } from '../components/GanttChart';
import { PerformancePill, errorMessage, fullDate, money, pct } from '../components/se';

/** Écran 3 de docs/maquette-SE : Gantt planning initial, planning courant, réel et projection. */
const ECHELLES = [
    { value: 'semaines', label: 'Semaines' },
    { value: 'mois', label: 'Mois' },
    { value: 'trimestres', label: 'Trimestres' },
];
const VUES = [
    { value: 'frise', label: 'Frise' },
    { value: 'tableau', label: 'Tableau' },
];
const STATUT_REVISION: Record<string, { libelle: string; ton: 'warning' | 'success' | 'danger' | 'neutral' }> = {
    proposee: { libelle: 'En attente de validation', ton: 'warning' },
    validee: { libelle: 'Validée', ton: 'success' },
    rejetee: { libelle: 'Rejetée', ton: 'danger' },
};

type Plan = GanttPlan & {
    activite: { id: number; code: string | null; libelle: string; budget: number | null; debut: string | null; fin: string | null; debut_reel: string | null; unite_responsable: string | null; gar_noeud_id: number | null };
    impacts: string[];
    retards: { libelle: string; jours: number }[];
    fin_projetee: string | null;
    synthese: { avancement: number | null; taches: number; taches_terminees: number; taches_en_retard: number; jalons: number; jalons_franchis: number; fin_prevue: string | null; fin_projetee: string | null; glissement: number };
    revisions: { versions: number; en_attente: number | null; historique: { id: number; version: number; statut: string; motif: string; propose_par: string | null; decide_par: string | null; decide_le: string | null; motif_decision: string | null }[] };
    droits: { planifier: boolean; constater: boolean; proposer: boolean; valider_revision: boolean };
};

export default function GanttPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { prompt } = useDialogs();
    const [activites, setActivites] = useState<any[]>([]);
    const [echelle, setEchelle] = useState(() => lirePreference('gantt.echelle', 'mois'));
    const [vue, setVue] = useState('frise');
    const [cheminCritique, setCheminCritique] = useState(true);
    const [plan, setPlan] = useState<Plan | null>(null);
    const [erreur, setErreur] = useState('');
    const [tache, setTache] = useState<GanttTache | null>(null);
    const [jalon, setJalon] = useState<GanttJalon | null>(null);
    const [proposition, setProposition] = useState(false);
    const [nouveauJalon, setNouveauJalon] = useState(false);
    const [pending, setPending] = useState<string | null>(null);

    useEffect(() => {
        api.get('/suivi/pilotage').then((response) => {
            const rows = response.data.data.activites ?? [];
            setActivites(rows);
            if (!id && rows.length > 0) navigate(`/suivi/activites/${rows[0].id}/gantt`, { replace: true });
        }).catch(() => setActivites([]));
    }, []);

    // Seule la réponse à la dernière demande s’affiche : un rechargement lent ne recouvre pas un plus récent.
    const derniereDemande = useRef(0);

    function charger() {
        if (!id) return;
        const numero = ++derniereDemande.current;
        api.get(`/suivi/activites/${id}/gantt`, { params: { echelle } })
            .then((response) => {
                if (numero !== derniereDemande.current) return;
                setPlan(response.data.data);
                setErreur('');
            })
            .catch((error) => { if (numero === derniereDemande.current) setErreur(errorMessage(error, 'Planning inaccessible.')); });
    }

    useEffect(charger, [id, echelle]);
    useEffect(() => ecrirePreference('gantt.echelle', echelle), [echelle]);

    async function agir(cle: string, requete: () => Promise<unknown>, message: string, apres?: () => void) {
        setPending(cle);
        setErreur('');
        try {
            await requete();
            toast.success(message);
            apres?.();
            charger();
            return true;
        } catch (error) {
            setErreur(errorMessage(error));
            return false;
        } finally {
            setPending(null);
        }
    }

    const selecteur = (
        <select className="inp" aria-label="Activité affichée" style={{ maxWidth: 360 }} value={id ?? ''} onChange={(event) => navigate(`/suivi/activites/${event.target.value}/gantt`)}>
            {!id && <option value="">Choisir une activité</option>}
            {activites.map((row) => <option key={row.id} value={row.id}>{row.code ?? `ACT-${row.id}`} · {row.activite}</option>)}
        </select>
    );

    if (!id || !plan) {
        return (
            <main className="app-content">
                <PageHeader back={{ to: '/suivi', label: 'Tableau de bord S&E' }} eyebrow="Suivi & évaluation" title="Gantt d’exécution" subtitle="Choisissez une activité suivie pour afficher son planning." actions={selecteur} />
                {erreur
                    ? <ErrorMessage error={erreur} title="Planning inaccessible" />
                    : activites.length === 0
                        ? <SectionCard><EmptyState icon={ICON.gantt} title="Aucune activité suivie">Le Gantt s’affiche dès qu’une activité est rattachée au suivi.</EmptyState></SectionCard>
                        : <PageSkeleton variant="detail" />}
            </main>
        );
    }

    const activite = plan.activite;
    const synthese = plan.synthese;
    const revisions = plan.revisions;
    const enAttente = revisions.historique.find((row) => row.id === revisions.en_attente);
    const code = activite.code ?? `ACT-${activite.id}`;
    const sansTache = plan.taches.length === 0;
    const nonPlanifiees = plan.taches.filter((row) => !row.planifiee).length;

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: `/suivi/activites/${activite.id}`, label: 'Fiche activité' }}
                eyebrow={<><span className="mono strong">{code}</span>{activite.unite_responsable && <span>{activite.unite_responsable}</span>}<span>Suivi & évaluation</span></>}
                title="Gantt d’exécution"
                subtitle={activite.libelle}
                meta={(
                    <span className="cluster subtle" style={{ gap: '4px 18px' }}>
                        <span>
                            Période prévue{' '}
                            <b>{activite.debut
                                ? `${fullDate(activite.debut)} → ${fullDate(activite.fin)}`
                                : plan.barre_activite?.debut ? `${fullDate(plan.barre_activite.debut)} → ${fullDate(plan.barre_activite.fin)} (d’après les tâches)` : 'non renseignée'}</b>
                        </span>
                        {activite.budget !== null && <span>Budget révisé <b className="mono">{money(activite.budget)}</b></span>}
                        <span>Planning <b>v{revisions.versions}</b></span>
                    </span>
                )}
                actions={<>
                    {selecteur}
                    <Button to="/suivi/gantt" icon={ICON.gantt}>Gantt global</Button>
                    {plan.droits.proposer && !sansTache && (
                        <Button variant="primary" icon={ICON.gantt} onClick={() => setProposition(true)} disabled={!!revisions.en_attente} title={revisions.en_attente ? 'Une proposition attend déjà sa validation.' : undefined}>
                            Proposer un nouveau planning
                        </Button>
                    )}
                </>}
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <StatStrip label="Synthèse du planning">
                <StripCell label="Avancement pondéré" value={synthese.avancement === null ? '—' : pct(synthese.avancement, 0)} hint="Réalisations validées, pondérées" mono />
                <StripCell label="Tâches terminées" value={`${synthese.taches_terminees} / ${synthese.taches}`} hint={nonPlanifiees > 0 ? `${nonPlanifiees} non planifiée(s)` : 'Toutes planifiées'} mono />
                <StripCell label="Tâches en retard" value={synthese.taches_en_retard} valueColor={synthese.taches_en_retard > 0 ? 'var(--orange-fg)' : undefined} hint="Démarrage ou fin projetée" mono />
                <StripCell label="Jalons franchis" value={`${synthese.jalons_franchis} / ${synthese.jalons}`} mono />
                <StripCell
                    label="Fin projetée"
                    value={synthese.fin_projetee ? fullDate(synthese.fin_projetee) : '—'}
                    valueColor={synthese.glissement > 0 ? 'var(--orange-fg)' : undefined}
                    hint={synthese.glissement > 0 ? `+${synthese.glissement} j sur la fin prévue (${fullDate(synthese.fin_prevue)})` : synthese.fin_prevue ? `Prévue le ${fullDate(synthese.fin_prevue)}` : 'Fin prévue non renseignée'}
                    mono
                />
            </StatStrip>

            {enAttente && (
                <Alert tone="warning" title={`Proposition de planning v${enAttente.version} en attente de validation`}>
                    <div className="cluster" style={{ justifyContent: 'space-between' }}>
                        <span>Proposée par {enAttente.propose_par ?? '—'} : {enAttente.motif}</span>
                        {plan.droits.valider_revision && (
                            <span className="cluster">
                                <Button size="sm" variant="primary" icon={ICON.validate} loading={pending === 'valider'} onClick={() => agir('valider', () => api.post(`/suivi/plannings/${enAttente.id}/valider`), 'Planning validé : les nouvelles dates s’appliquent.')}>Valider</Button>
                                <Button size="sm" variant="danger-outline" icon={ICON.reject} onClick={async () => {
                                    const values = await prompt({ title: 'Rejeter la proposition de planning', confirmLabel: 'Rejeter', tone: 'danger', fields: [{ name: 'motif', label: 'Motif du rejet', type: 'textarea', required: true }] });
                                    if (values) await agir('rejeter', () => api.post(`/suivi/plannings/${enAttente.id}/rejeter`, values), 'Planning rejeté.');
                                }}>Rejeter</Button>
                            </span>
                        )}
                    </div>
                </Alert>
            )}

            <SectionCard
                title="Planning de l’activité"
                icon={ICON.gantt}
                subtitle="Cliquez une tâche ou un jalon pour le détail et les actions."
                flush
                actions={(
                    <span className="cluster">
                        <Segmented label="Échelle de temps" value={echelle} onChange={setEchelle} items={ECHELLES} />
                        <Segmented label="Présentation" value={vue} onChange={setVue} items={VUES} />
                        <label className="cluster" style={{ gap: 6, fontSize: 'var(--text-sm)' }}>
                            <input type="checkbox" checked={cheminCritique} onChange={(event) => setCheminCritique(event.target.checked)} />
                            Chemin critique
                        </label>
                        <Button size="sm" icon={ICON.print} onClick={() => window.print()}>Imprimer</Button>
                    </span>
                )}
            >
                {sansTache && (
                    <div style={{ padding: 16 }}>
                        <Alert tone="info" title="Aucune tâche programmée pour cette activité">
                            <div className="stack-sm">
                                <span>
                                    Les tâches et leurs dates prévues viennent de la planification GAR (Pilier → Axe → Produit → Sous-produit → Activité → Tâche) :
                                    elles y sont saisies, validées et publiées, puis apparaissent ici automatiquement.
                                </span>
                                <span><Button size="sm" variant="primary" icon={ICON.open} to="/planification">Ouvrir la planification GAR</Button></span>
                                {!activite.gar_noeud_id && <span className="subtle">Cette activité provient du PAP importé et n’est pas encore rattachée à une activité de la planification GAR.</span>}
                            </div>
                        </Alert>
                    </div>
                )}

                {vue === 'frise' && plan.jours > 0 && (
                    <GanttChart plan={plan} echelle={echelle} cheminCritique={cheminCritique} onTache={setTache} onJalon={setJalon} />
                )}
                {vue === 'frise' && plan.jours === 0 && !sansTache && (
                    <EmptyState icon={ICON.calendar} title="Aucune date à représenter">Planifiez au moins une tâche pour afficher la frise.</EmptyState>
                )}
                {vue === 'tableau' && <TableauTaches taches={plan.taches} onTache={setTache} />}

                <div className="gantt-legende" aria-label="Légende">
                    <span><i style={{ border: '1.5px dashed var(--slate-400)' }} />Planning initial validé</span>
                    <span><i style={{ border: '1.5px solid var(--slate-400)', background: 'var(--slate-100)' }} />Planning courant (non démarré)</span>
                    <span><i style={{ border: '1.5px solid var(--green-600)', background: 'linear-gradient(90deg, var(--green-600) 60%, color-mix(in srgb, var(--green-600) 28%, white) 60%)' }} />Réel · part remplie = avancement</span>
                    <span><i style={{ background: 'var(--warning-solid)' }} />À surveiller</span>
                    <span><i style={{ background: 'var(--orange-solid)' }} />En retard</span>
                    <span><i style={{ border: '1px solid var(--slate-500)', background: 'repeating-linear-gradient(45deg, var(--slate-500) 0 3px, white 3px 7px)' }} />Projection</span>
                    <span><i style={{ boxShadow: '0 0 0 2px var(--danger-solid)', background: 'var(--color-surface)' }} />Chemin critique</span>
                    <span><span className="gantt-losange is-franchi" style={{ width: 10, height: 10 }} />Jalon franchi</span>
                    <span><span className="gantt-losange is-en_retard" style={{ width: 10, height: 10 }} />Jalon en retard</span>
                    <span><span style={{ width: 2, height: 12, background: 'var(--danger-solid)', display: 'inline-block' }} />Aujourd’hui</span>
                </div>
            </SectionCard>

            <div className="grid-2">
                <SectionCard title="Retards constatés" icon={ICON.clock} flush>
                    <DataTable
                        columns={[
                            { key: 'libelle', header: 'Élément', render: (row: { libelle: string }) => row.libelle },
                            { key: 'jours', header: 'Retard', align: 'right', render: (row: { jours: number }) => <span className="gantt-retard">+{row.jours} j</span> },
                        ] as Column<{ libelle: string; jours: number }>[]}
                        rows={plan.retards}
                        rowKey={(row) => row.libelle}
                        compact
                        empty={<EmptyState icon={ICON.success} title="Aucun retard constaté" compact />}
                    />
                </SectionCard>
                <SectionCard title="Impact des dépendances" icon={ICON.warning} subtitle="Une tâche « fin → début » ne démarre qu’après la fin de sa tâche préalable.">
                    {plan.impacts.length === 0
                        ? <p className="subtle">Aucun glissement propagé par les dépendances.</p>
                        : <div className="stack-sm">{plan.impacts.map((texte) => <Alert key={texte} tone="warning">{texte}</Alert>)}</div>}
                    {plan.fin_projetee && <p className="subtle" style={{ marginTop: 8 }}>Fin projetée de l’activité : <b className="mono">{fullDate(plan.fin_projetee)}</b></p>}
                </SectionCard>
            </div>

            <div className="grid-2">
                <SectionCard
                    title="Jalons"
                    icon={ICON.calendar}
                    subtitle="Étapes clés à franchir ; chaque franchissement exige une preuve."
                    flush
                    actions={<Button size="sm" icon={ICON.create} onClick={() => setNouveauJalon(true)}>Nouveau jalon</Button>}
                >
                    <DataTable
                        columns={[
                            { key: 'libelle', header: 'Jalon', render: (row: GanttJalon) => <span className="strong">{row.libelle}{row.tache && <span className="cell-sub">{row.tache}</span>}</span> },
                            { key: 'prevu', header: 'Prévu', render: (row: GanttJalon) => fullDate(row.prevu_le) },
                            { key: 'franchi', header: 'Franchi', render: (row: GanttJalon) => (row.franchi_le ? fullDate(row.franchi_le) : '—') },
                            { key: 'statut', header: 'Statut', render: (row: GanttJalon) => <JalonStatut jalon={row} /> },
                        ] as Column<GanttJalon>[]}
                        rows={plan.jalons}
                        rowKey={(row) => row.id}
                        onRowClick={setJalon}
                        rowLabel={(row) => `Ouvrir le jalon ${row.libelle}`}
                        compact
                        empty={<EmptyState icon={ICON.calendar} title="Aucun jalon" compact>Ajoutez les étapes clés (validation des TDR, signature du contrat, réception…).</EmptyState>}
                    />
                </SectionCard>
                <SectionCard title="Historique des plannings" icon={ICON.history} subtitle="Le planning initial reste visible ; chaque révision est motivée et validée par la hiérarchie." flush>
                    <DataTable
                        columns={[
                            { key: 'version', header: 'Version', render: (row: Plan['revisions']['historique'][number]) => <span className="mono strong">v{row.version}</span> },
                            { key: 'statut', header: 'Statut', render: (row: Plan['revisions']['historique'][number]) => <Badge tone={STATUT_REVISION[row.statut]?.ton ?? 'neutral'} size="sm">{STATUT_REVISION[row.statut]?.libelle ?? row.statut}</Badge> },
                            { key: 'motif', header: 'Motif', render: (row: Plan['revisions']['historique'][number]) => <span>{row.motif}{row.motif_decision && <span className="cell-sub">Décision : {row.motif_decision}</span>}</span> },
                            { key: 'acteurs', header: 'Proposée / décidée', render: (row: Plan['revisions']['historique'][number]) => <span className="cell-sub">{row.propose_par ?? '—'} / {row.decide_par ? `${row.decide_par} · ${fullDate(row.decide_le)}` : '—'}</span> },
                        ] as Column<Plan['revisions']['historique'][number]>[]}
                        rows={revisions.historique}
                        rowKey={(row) => row.id}
                        compact
                        empty={<EmptyState icon={ICON.history} title="Planning initial (v1) en vigueur" compact>Aucune révision proposée.</EmptyState>}
                    />
                </SectionCard>
            </div>

            {tache && (
                <TacheDrawer
                    tache={tache}
                    taches={plan.taches}
                    droits={plan.droits}
                    pending={pending}
                    onClose={() => setTache(null)}
                    onEnregistrer={(cle, donnees, message) => agir(cle, () => api.patch(`/suivi/taches/${tache.id}`, donnees), message, () => setTache(null))}
                />
            )}
            {jalon && (
                <JalonDrawer
                    jalon={jalon}
                    pending={pending}
                    onClose={() => setJalon(null)}
                    onFranchir={(donnees) => agir('franchir', () => api.patch(`/suivi/jalons/${jalon.id}`, donnees), 'Jalon franchi.', () => setJalon(null))}
                />
            )}
            {nouveauJalon && (
                <NouveauJalon
                    taches={plan.taches}
                    pending={pending === 'jalon'}
                    onClose={() => setNouveauJalon(false)}
                    onCreer={(donnees) => agir('jalon', () => api.post(`/suivi/activites/${activite.id}/jalons`, donnees), 'Jalon ajouté.', () => setNouveauJalon(false))}
                />
            )}
            {proposition && (
                <PropositionPlanning
                    plan={plan}
                    pending={pending === 'proposer'}
                    onClose={() => setProposition(false)}
                    onSoumettre={(donnees) => agir('proposer', () => api.post(`/suivi/activites/${activite.id}/plannings`, donnees), 'Proposition transmise à la hiérarchie pour validation.', () => setProposition(false))}
                />
            )}
        </main>
    );
}

function JalonStatut({ jalon }: { jalon: GanttJalon }) {
    if (jalon.statut === 'franchi') {
        return <Badge tone={jalon.retard > 0 ? 'warning' : 'success'} size="sm">{jalon.retard > 0 ? `Franchi avec ${jalon.retard} j de retard` : 'Franchi'}</Badge>;
    }
    if (jalon.statut === 'en_retard') {
        return <Badge tone="orange" size="sm">En retard · {jalon.retard} j</Badge>;
    }

    return <Badge tone="neutral" size="sm">À venir</Badge>;
}

/** Vue tableau : même information que la frise, lisible au clavier et par les lecteurs d’écran. */
function TableauTaches({ taches, onTache }: { taches: GanttTache[]; onTache: (tache: GanttTache) => void }) {
    const colonnes: Column<GanttTache>[] = [
        { key: 'code', header: 'Code', render: (row) => <span className="cell-ref">{row.code}</span> },
        { key: 'libelle', header: 'Tâche', render: (row) => <span>{row.libelle}<span className="cell-sub">{row.responsable ?? 'Responsable non désigné'}{row.depend_de ? ` · après ${row.depend_de}` : ''}</span></span> },
        { key: 'initial', header: 'Planning initial', render: (row) => (row.debut_initial ? `${fullDate(row.debut_initial)} → ${fullDate(row.fin_initiale)}` : 'Non planifiée') },
        { key: 'reel', header: 'Réel', render: (row) => (row.debut_reel ? `${fullDate(row.debut_reel)} → ${row.fin_reelle ? fullDate(row.fin_reelle) : 'en cours'}` : '—') },
        { key: 'projetee', header: 'Fin projetée', render: (row) => (row.fin_reelle ? '—' : fullDate(row.fin_projetee)) },
        { key: 'avancement', header: 'Avancement', align: 'right', render: (row) => <span className="num">{row.avancement === null ? '—' : pct(row.avancement, 0)}</span> },
        { key: 'statut', header: 'Statut', render: (row) => (row.planifiee ? <PerformancePill status={row.statut} /> : <Badge tone="neutral" size="sm">Non planifiée</Badge>) },
        { key: 'retard', header: 'Retard', align: 'right', render: (row) => (row.retard_fin > 0 ? <span className="gantt-retard">+{row.retard_fin} j</span> : '—') },
    ];

    return (
        <DataTable
            columns={colonnes}
            rows={taches}
            rowKey={(row) => row.id}
            onRowClick={onTache}
            rowLabel={(row) => `Ouvrir la tâche ${row.code}`}
            rowClassName={(row) => (row.critique ? 'is-danger' : undefined)}
            empty={<EmptyState icon={ICON.gantt} title="Aucune tâche" compact />}
        />
    );
}

function TacheDrawer({ tache, taches, droits, pending, onClose, onEnregistrer }: {
    tache: GanttTache;
    taches: GanttTache[];
    droits: Plan['droits'];
    pending: string | null;
    onClose: () => void;
    onEnregistrer: (cle: string, donnees: Record<string, unknown>, message: string) => Promise<boolean>;
}) {
    // Les dates prévues se saisissent tant qu’aucune référence n’est validée ; ensuite, seule la hiérarchie les modifie.
    const peutPlanifier = droits.planifier || !tache.reference_validee;
    const [prevu, setPrevu] = useState({ starts_on: tache.debut_courant ?? '', ends_on: tache.fin_courante ?? '', depends_on_id: tache.depend_de_id ? String(tache.depend_de_id) : '' });
    const [reel, setReel] = useState({ actual_start: tache.debut_reel ?? '', actual_end: tache.fin_reelle ?? '' });
    const predecesseurs = useMemo(() => taches.filter((row) => row.id !== tache.id), [taches, tache.id]);

    return (
        <Drawer width={560} title={`${tache.code} · ${tache.libelle}`} description={tache.responsable ?? 'Responsable non désigné'} icon={ICON.gantt} onClose={onClose} footer={<Button onClick={onClose}>Fermer</Button>}>
            <SectionCard title="Situation" icon={ICON.info}>
                <KeyValueList items={[
                    { label: 'Statut', value: tache.planifiee ? <PerformancePill status={tache.statut} /> : 'Non planifiée' },
                    { label: 'Avancement', value: tache.avancement === null ? 'Non renseigné' : pct(tache.avancement, 0), mono: true },
                    { label: 'Poids dans l’activité', value: tache.poids === null ? '—' : `${tache.poids} %`, mono: true },
                    { label: 'Planning initial', value: tache.debut_initial ? `${fullDate(tache.debut_initial)} → ${fullDate(tache.fin_initiale)}` : 'Non planifié' },
                    { label: 'Planning courant', value: tache.debut_courant ? `${fullDate(tache.debut_courant)} → ${fullDate(tache.fin_courante)}` : '—', hidden: tache.debut_courant === tache.debut_initial && tache.fin_courante === tache.fin_initiale },
                    { label: 'Réel', value: tache.debut_reel ? `${fullDate(tache.debut_reel)} → ${tache.fin_reelle ? fullDate(tache.fin_reelle) : 'en cours'}` : 'Non démarrée' },
                    { label: 'Fin projetée', value: fullDate(tache.fin_projetee), hidden: tache.fin_reelle !== null, warning: tache.retard_fin > 0 },
                    { label: 'Retard projeté', value: `+${tache.retard_fin} j`, hidden: tache.retard_fin === 0, warning: true },
                    { label: 'Retard au démarrage', value: `${tache.retard_demarrage} j`, hidden: !(tache.non_demarree && tache.retard_demarrage > 0), warning: true },
                    { label: 'Dépend de', value: tache.depend_de ? `${tache.depend_de} (fin → début)` : 'Aucune tâche préalable' },
                    { label: 'Chemin critique', value: 'Oui : tout retard retarde l’activité', hidden: !tache.critique, warning: true },
                ]} />
            </SectionCard>

            {droits.constater && (
                <SectionCard title="Constater le réel" icon={ICON.clock} subtitle="Si la fin réelle dépasse la fin prévue, les tâches qui en dépendent sont décalées d’autant.">
                    <form className="form-grid" onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        void onEnregistrer('reel', { actual_start: reel.actual_start || null, actual_end: reel.actual_end || null }, 'Dates réelles enregistrées.');
                    }}>
                        <FormField label="Début réel"><input className="inp" type="date" value={reel.actual_start} onChange={(event) => setReel({ ...reel, actual_start: event.target.value })} /></FormField>
                        <FormField label="Fin réelle"><input className="inp" type="date" value={reel.actual_end} min={reel.actual_start || undefined} onChange={(event) => setReel({ ...reel, actual_end: event.target.value })} /></FormField>
                        <div className="span-all"><Button type="submit" variant="primary" icon={ICON.save} loading={pending === 'reel'}>Enregistrer le réel</Button></div>
                    </form>
                </SectionCard>
            )}

            <SectionCard title="Dates prévues" icon={ICON.calendar} subtitle={peutPlanifier ? (tache.reference_validee ? 'Modification directe réservée à la hiérarchie ; elle est tracée.' : 'Planification initiale de la tâche.') : undefined}>
                {peutPlanifier ? (
                    <form className="form-grid" onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        void onEnregistrer('prevu', { starts_on: prevu.starts_on || null, ends_on: prevu.ends_on || null, depends_on_id: prevu.depends_on_id ? Number(prevu.depends_on_id) : null }, 'Planning de la tâche enregistré.');
                    }}>
                        <FormField label="Début prévu" required><input className="inp" type="date" required value={prevu.starts_on} onChange={(event) => setPrevu({ ...prevu, starts_on: event.target.value })} /></FormField>
                        <FormField label="Fin prévue" required><input className="inp" type="date" required min={prevu.starts_on || undefined} value={prevu.ends_on} onChange={(event) => setPrevu({ ...prevu, ends_on: event.target.value })} /></FormField>
                        <FormField label="Tâche préalable (fin → début)" className="span-all">
                            <select className="inp" value={prevu.depends_on_id} onChange={(event) => setPrevu({ ...prevu, depends_on_id: event.target.value })}>
                                <option value="">Aucune</option>
                                {predecesseurs.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.libelle}</option>)}
                            </select>
                        </FormField>
                        <div className="span-all"><Button type="submit" icon={ICON.save} loading={pending === 'prevu'}>Enregistrer les dates prévues</Button></div>
                    </form>
                ) : (
                    <Alert tone="info">Le planning initial de cette tâche est validé. Pour en changer les dates, utilisez « Proposer un nouveau planning » : la proposition est motivée et validée par la hiérarchie.</Alert>
                )}
            </SectionCard>
        </Drawer>
    );
}

function JalonDrawer({ jalon, pending, onClose, onFranchir }: {
    jalon: GanttJalon;
    pending: string | null;
    onClose: () => void;
    onFranchir: (donnees: { achieved_on: string; proof_label: string }) => Promise<boolean>;
}) {
    const [form, setForm] = useState({ achieved_on: jalon.franchi_le ?? new Date().toISOString().slice(0, 10), proof_label: '' });

    return (
        <Drawer width={480} title={jalon.libelle} description={jalon.tache ? `Rattaché à ${jalon.tache}` : 'Jalon de l’activité'} icon={ICON.calendar} onClose={onClose} footer={<Button onClick={onClose}>Fermer</Button>}>
            <KeyValueList items={[
                { label: 'Prévu le', value: fullDate(jalon.prevu_le) },
                { label: 'Franchi le', value: jalon.franchi_le ? fullDate(jalon.franchi_le) : '—' },
                { label: 'Statut', value: <JalonStatut jalon={jalon} /> },
            ]} />
            {!jalon.franchi_le && (
                <SectionCard title="Constater le franchissement" icon={ICON.check} subtitle="La preuve (PV, contrat signé, rapport…) est obligatoire.">
                    <form className="form-grid" onSubmit={(event: FormEvent) => { event.preventDefault(); void onFranchir(form); }}>
                        <FormField label="Date de franchissement" required><input className="inp" type="date" required value={form.achieved_on} onChange={(event) => setForm({ ...form, achieved_on: event.target.value })} /></FormField>
                        <FormField label="Preuve" required className="span-all"><input className="inp" required maxLength={255} placeholder="Référence du document probant" value={form.proof_label} onChange={(event) => setForm({ ...form, proof_label: event.target.value })} /></FormField>
                        <div className="span-all"><Button type="submit" variant="primary" icon={ICON.check} loading={pending === 'franchir'}>Marquer comme franchi</Button></div>
                    </form>
                </SectionCard>
            )}
        </Drawer>
    );
}

function NouveauJalon({ taches, pending, onClose, onCreer }: {
    taches: GanttTache[];
    pending: boolean;
    onClose: () => void;
    onCreer: (donnees: Record<string, unknown>) => Promise<boolean>;
}) {
    const [form, setForm] = useState({ label: '', planned_on: '', pap_task_id: '', responsible_label: '' });

    return (
        <Modal
            title="Nouveau jalon"
            icon={ICON.calendar}
            onClose={onClose}
            footer={<><Button onClick={onClose}>Annuler</Button><Button variant="primary" type="submit" form="jalon-form" icon={ICON.save} loading={pending}>Ajouter</Button></>}
        >
            <form id="jalon-form" className="form-grid" onSubmit={(event: FormEvent) => {
                event.preventDefault();
                void onCreer({ label: form.label, planned_on: form.planned_on, pap_task_id: form.pap_task_id ? Number(form.pap_task_id) : null, responsible_label: form.responsible_label || null });
            }}>
                <FormField label="Jalon" required className="span-all"><input className="inp" required maxLength={255} placeholder="Ex. validation des termes de référence" value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} /></FormField>
                <FormField label="Date prévue" required><input className="inp" type="date" required value={form.planned_on} onChange={(event) => setForm({ ...form, planned_on: event.target.value })} /></FormField>
                <FormField label="Tâche rattachée">
                    <select className="inp" value={form.pap_task_id} onChange={(event) => setForm({ ...form, pap_task_id: event.target.value })}>
                        <option value="">Aucune</option>
                        {taches.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.libelle}</option>)}
                    </select>
                </FormField>
                <FormField label="Responsable" className="span-all"><input className="inp" maxLength={120} value={form.responsible_label} onChange={(event) => setForm({ ...form, responsible_label: event.target.value })} /></FormField>
            </form>
        </Modal>
    );
}

function PropositionPlanning({ plan, pending, onClose, onSoumettre }: {
    plan: Plan;
    pending: boolean;
    onClose: () => void;
    onSoumettre: (donnees: { motif: string; taches: { id: number; starts_on: string; ends_on: string }[] }) => Promise<boolean>;
}) {
    const [motif, setMotif] = useState('');
    const [dates, setDates] = useState<Record<number, { starts_on: string; ends_on: string }>>(() => Object.fromEntries(
        plan.taches.map((row) => [row.id, { starts_on: row.debut_courant ?? row.debut_initial ?? '', ends_on: row.fin_projetee ?? row.fin_courante ?? row.fin_initiale ?? '' }]),
    ));
    const modifiees = plan.taches.filter((row) => dates[row.id]?.starts_on && dates[row.id]?.ends_on && (dates[row.id].starts_on !== (row.debut_courant ?? '') || dates[row.id].ends_on !== (row.fin_courante ?? '')));

    return (
        <Modal
            size="xl"
            title={`Proposer le planning v${plan.revisions.versions + 1}`}
            description="Seules les tâches dont les dates changent sont transmises. Le planning initial reste conservé pour comparaison."
            icon={ICON.gantt}
            onClose={onClose}
            footer={<><Button onClick={onClose}>Annuler</Button><Button variant="primary" type="submit" form="proposition-form" icon={ICON.submit} loading={pending} disabled={modifiees.length === 0 || !motif.trim()}>Soumettre à validation ({modifiees.length})</Button></>}
        >
            <form id="proposition-form" className="stack" onSubmit={(event: FormEvent) => {
                event.preventDefault();
                void onSoumettre({ motif, taches: modifiees.map((row) => ({ id: row.id, ...dates[row.id] })) });
            }}>
                <div className="table-wrap">
                    <table className="tbl">
                        <thead><tr><th>Tâche</th><th>Planning courant</th><th>Fin projetée</th><th>Nouveau début</th><th>Nouvelle fin</th></tr></thead>
                        <tbody>
                            {plan.taches.map((row) => (
                                <tr key={row.id} className={modifiees.includes(row) ? 'is-selected' : undefined}>
                                    <td><span className="cell-ref">{row.code}</span><span className="cell-sub">{row.libelle}</span></td>
                                    <td className="num">{row.debut_courant ? `${fullDate(row.debut_courant)} → ${fullDate(row.fin_courante)}` : 'Non planifiée'}</td>
                                    <td className="num">{fullDate(row.fin_projetee)}{row.retard_fin > 0 && <span className="gantt-retard"> +{row.retard_fin} j</span>}</td>
                                    <td><input className="inp inp-sm" type="date" aria-label={`Nouveau début ${row.code}`} value={dates[row.id]?.starts_on ?? ''} onChange={(event) => setDates({ ...dates, [row.id]: { ...dates[row.id], starts_on: event.target.value } })} /></td>
                                    <td><input className="inp inp-sm" type="date" aria-label={`Nouvelle fin ${row.code}`} min={dates[row.id]?.starts_on || undefined} value={dates[row.id]?.ends_on ?? ''} onChange={(event) => setDates({ ...dates, [row.id]: { ...dates[row.id], ends_on: event.target.value } })} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <FormField label="Motif de la révision" required>
                    <textarea className="inp" rows={3} required value={motif} onChange={(event) => setMotif(event.target.value)} placeholder="Ex. retard de livraison du prestataire, report de l’atelier par le bénéficiaire…" />
                </FormField>
            </form>
        </Modal>
    );
}

function lirePreference(cle: string, defaut: string): string {
    try {
        return window.localStorage.getItem(cle) ?? defaut;
    } catch {
        return defaut;
    }
}

function ecrirePreference(cle: string, valeur: string): void {
    try {
        window.localStorage.setItem(cle, valeur);
    } catch {
        // stockage indisponible : la préférence n’est simplement pas retenue
    }
}
