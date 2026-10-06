import { faPenRuler } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Alert,
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    PageHeader,
    SectionCard,
    useDialogs,
    useToast,
    type Column,
} from '../../../components/ui';
import { Pager } from '../components/se';

const PERIODE_COLLECTE: Record<string, { label: string; tone: 'success' | 'info' | 'neutral' | 'warning' }> = {
    ouverte: { label: 'Ouverte', tone: 'info' },
    consolidee: { label: 'Consolidée', tone: 'success' },
    cloturee: { label: 'Clôturée', tone: 'neutral' },
};

const STATUTS: Record<string, { label: string; tone: string }> = {
    brouillon: { label: 'Brouillon', tone: 'neutral' },
    soumis: { label: 'Soumis', tone: 'info' },
    a_corriger: { label: 'À corriger', tone: 'orange' },
    valide: { label: 'Validé', tone: 'success' },
    rejete: { label: 'Rejeté', tone: 'danger' },
    consolide: { label: 'Consolidé', tone: 'success' },
};

function messageErreur(error: any, defaut: string): string {
    const details = error?.response?.data?.errors;

    return details ? Object.values(details).flat().join(' ') : error?.response?.data?.message ?? defaut;
}

export default function SaisiePage() {
    const toast = useToast();
    const { prompt } = useDialogs();
    const [activites, setActivites] = useState<any[]>([]);
    const [periodes, setPeriodes] = useState<any[]>([]);
    const [indicateurs, setIndicateurs] = useState<any[]>([]);
    const [file, setFile] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number } | null>(null);
    const [page, setPage] = useState(1);
    const [params] = useSearchParams();
    const [activite, setActivite] = useState(params.get('activite') ?? '');
    const [erreur, setErreur] = useState('');
    const [pending, setPending] = useState(false);

    function charger(cible = page) {
        setLoading(true);
        api.get('/suivi/saisies', { params: { page: cible } })
            .then((response) => {
                setFile(response.data.data);
                setMeta(response.data.meta);
            })
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        api.get('/suivi/activites').then((response) => setActivites(response.data.data));
        api.get('/suivi/periodes').then((response) => setPeriodes(response.data.data));
        api.get('/suivi/indicateurs').then((response) => setIndicateurs(response.data.data));
    }, []);

    useEffect(() => {
        charger(page);
    }, [page]);

    async function consoliderPeriode(periode: { id: number; label: string }) {
        setPending(true);
        const ok = await executer(
            () => api.post(`/suivi/periodes/${periode.id}/consolider`),
            `Période ${periode.label} consolidée.`,
        );
        if (ok) {
            const response = await api.get('/suivi/periodes');
            setPeriodes(response.data.data);
        }
        setPending(false);
    }

    async function executer(action: () => Promise<unknown>, succes: string) {
        setErreur('');
        try {
            await action();
            toast.success(succes);
            charger();
            return true;
        } catch (error: any) {
            const message = messageErreur(error, 'Action refusée.');
            setErreur(message);
            toast.error(message);
            return false;
        }
    }

    async function enregistrerRealisation(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formulaire = event.currentTarget;
        const form = new FormData(formulaire);
        setPending(true);
        await executer(() => api.post('/suivi/realisations', {
            pap_enrichment_id: Number(form.get('activite')),
            pap_task_id: form.get('tache') ? Number(form.get('tache')) : null,
            monitoring_period_id: Number(form.get('periode')),
            method: form.get('methode'),
            quantity: Number(form.get('quantite')),
            planned: Number(form.get('prevu')),
            comment: form.get('commentaire'),
            difficulties: form.get('difficultes'),
            exception_motif: form.get('justification') || null,
        }).then(() => formulaire.reset()), 'Réalisation enregistrée en brouillon : joignez une preuve puis soumettez-la.');
        setPending(false);
    }

    function joindre(ligne: any, fichier: File | undefined) {
        if (!fichier) {
            return;
        }
        const form = new FormData();
        form.append('type', ligne.type);
        form.append('id', String(ligne.id));
        form.append('category', 'preuve');
        form.append('fichier', fichier);
        executer(() => api.post('/suivi/preuves', form), 'Preuve jointe et archivée.');
    }

    async function transition(ligne: any, action: string) {
        const base = ligne.type === 'mesure' ? '/suivi/mesures/' : '/suivi/realisations/';
        const besoinMotif = action === 'rejeter' || action === 'corriger';
        let motif = '';
        if (besoinMotif) {
            const values = await prompt({
                title: action === 'rejeter' ? 'Rejeter la saisie' : 'Retourner en correction',
                description: `${ligne.reference} · ${ligne.objet}`,
                confirmLabel: action === 'rejeter' ? 'Rejeter' : 'Retourner',
                tone: action === 'rejeter' ? 'danger' : 'warning',
                fields: [{ name: 'motif', label: 'Motif', type: 'textarea', required: true }],
            });
            if (!values) return;
            motif = values.motif;
        }
        executer(() => api.post(`${base}${ligne.id}/${action}`, besoinMotif ? { motif } : {}), 'Action enregistrée.');
    }

    async function rectifier(ligne: any) {
        const values = await prompt({
            title: 'Rectifier une valeur validée',
            description: `${ligne.reference} · valeur actuelle : ${ligne.valeur}. La rectification suit le circuit complet ; la valeur validée reste la référence d’ici là.`,
            confirmLabel: 'Créer la rectification',
            icon: faPenRuler,
            fields: [
                { name: 'valeur', label: 'Nouvelle valeur', type: 'number', required: true },
                { name: 'motif', label: 'Motif de rectification', type: 'textarea', required: true },
            ],
        });
        if (!values) return;
        executer(() => api.post(`/suivi/mesures/${ligne.id}/rectifier`, { value: Number(values.valeur), motif: values.motif }), 'Rectification créée : elle suit le circuit complet ; la valeur validée reste la référence d’ici là.');
    }

    const taches = activites.find((row) => String(row.id) === activite)?.taches ?? [];
    const indicateursVisibles = indicateurs.filter((row) => !activite || String(row.pap_enrichment_id) === activite);

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', render: (ligne) => <span className="mono strong">{ligne.reference}{ligne.version > 1 ? <Badge tone="brand" size="sm">v{ligne.version}</Badge> : null}</span> },
        { key: 'objet', header: 'Objet', render: (ligne) => <div style={{ minWidth: 200 }}><div>{ligne.objet}</div><div className="cell-sub">{ligne.activite}</div></div> },
        { key: 'periode', header: 'Période', render: (ligne) => ligne.periode },
        { key: 'valeur', header: 'Valeur', align: 'right', className: 'mono', render: (ligne) => ligne.valeur ?? '—' },
        { key: 'taux', header: 'Taux', align: 'right', className: 'mono', render: (ligne) => ligne.taux !== null && ligne.taux !== undefined ? `${ligne.taux} %` : '—' },
        {
            key: 'statut',
            header: 'Statut',
            render: (ligne) => {
                const statut = STATUTS[ligne.statut] ?? { label: ligne.statut, tone: 'neutral' };
                return <div><Badge tone={statut.tone} dot>{statut.label}</Badge>{ligne.motif && <div className="cell-sub">{ligne.motif}</div>}</div>;
            },
        },
        { key: 'auteur', header: 'Auteur', render: (ligne) => ligne.auteur },
        { key: 'preuves', header: 'Preuves', align: 'right', render: (ligne) => <Badge tone={ligne.preuves > 0 ? 'success' : 'neutral'} icon={ICON.attachment} size="sm">{ligne.preuves}</Badge> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            className: 'cell-actions',
            render: (ligne) => (
                <div className="btn-group" style={{ justifyContent: 'flex-end', flexWrap: 'nowrap' }}>
                    {ligne.actions.preuve && (
                        <label className="btn btn-secondary btn-sm" title="Joindre une preuve">
                            <FontAwesomeIcon icon={ICON.attachment} /><span>Preuve</span>
                            <input type="file" hidden onChange={(event) => joindre(ligne, event.target.files?.[0])} />
                        </label>
                    )}
                    {ligne.actions.soumettre && <Button size="sm" variant="primary" icon={ICON.submit} onClick={() => transition(ligne, 'soumettre')}>Soumettre</Button>}
                    {ligne.actions.valider && <Button size="sm" variant="primary" icon={ICON.validate} onClick={() => transition(ligne, 'valider')}>Valider</Button>}
                    <ActionMenu label={`Autres actions sur ${ligne.reference}`} actions={[
                        { label: 'Retourner en correction', icon: ICON.return, onSelect: () => transition(ligne, 'corriger'), hidden: !ligne.actions.corriger },
                        { label: 'Rectifier la valeur', icon: faPenRuler, onSelect: () => rectifier(ligne), hidden: !ligne.actions.rectifier },
                        { label: 'Rejeter', icon: ICON.reject, onSelect: () => transition(ligne, 'rejeter'), hidden: !ligne.actions.rejeter, danger: true },
                    ]} />
                </div>
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Saisie et validation"
                subtitle="Une saisie suit le circuit : brouillon → soumise par son auteur → validée par un autre acteur, avec preuve. Seules les valeurs validées alimentent les taux."
            />
            <Alert tone="info">Le budget, l’engagé, le liquidé, l’ordonnancé et le payé ne se ressaisissent pas : ils sont lus dans la chaîne de dépense.</Alert>
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <SectionCard title="Périodes de collecte" icon={ICON.calendar} subtitle="Le Directeur du Budget consolide une période lorsque toutes ses mesures sont validées, rejetées ou déjà consolidées." flush>
                <DataTable
                    columns={[
                        { key: 'label', header: 'Période', render: (row: any) => row.label },
                        { key: 'closes_on', header: 'Échéance', render: (row: any) => row.closes_on ?? '—' },
                        {
                            key: 'status',
                            header: 'Statut',
                            render: (row: any) => {
                                const statut = PERIODE_COLLECTE[row.status] ?? { label: row.status, tone: 'neutral' as const };

                                return <Badge tone={statut.tone} dot size="sm">{statut.label}</Badge>;
                            },
                        },
                        {
                            key: 'action',
                            header: 'Action',
                            srHeader: true,
                            className: 'cell-actions',
                            render: (row: any) => row.peut_consolider
                                ? <Button size="sm" variant="primary" icon={ICON.archive} loading={pending} onClick={() => consoliderPeriode(row)}>Consolider</Button>
                                : null,
                        },
                    ]}
                    rows={periodes}
                    rowKey={(row: any) => row.id}
                    empty={<EmptyState icon={ICON.calendar} title="Aucune période de collecte" compact />}
                />
            </SectionCard>

            <div className="layout-aside is-wide">
                <SectionCard title="Réalisation physique" icon={ICON.entry} subtitle="Enregistrée en brouillon, puis soumise avec sa preuve.">
                    <form onSubmit={enregistrerRealisation} className="stack">
                        <div className="form-grid">
                            <FormField label="Activité" required className="span-all">
                                <select className="inp" name="activite" value={activite} onChange={(event) => setActivite(event.target.value)}>
                                    <option value="" disabled>Choisir</option>
                                    {activites.map((row) => <option key={row.id} value={row.id}>{row.activite || `Activité ${row.id}`}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Tâche" optional>
                                <select className="inp" name="tache" defaultValue="">
                                    <option value="">Activité entière</option>
                                    {taches.map((row: any) => <option key={row.id} value={row.id}>{row.libelle} · poids {row.poids}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Période" required>
                                <select className="inp" name="periode" defaultValue="">
                                    <option value="" disabled>Choisir</option>
                                    {periodes.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Méthode">
                                <select className="inp" name="methode" defaultValue="quantitative">
                                    <option value="quantitative">Quantitative</option>
                                    <option value="jalon">Jalons</option>
                                    <option value="livrable">Livrables</option>
                                    <option value="binaire">Binaire</option>
                                </select>
                            </FormField>
                            <div className="form-grid">
                                <FormField label="Quantité réalisée" required><input className="inp num align-right" name="quantite" type="number" min="0" step="0.01" /></FormField>
                                <FormField label="Quantité prévue" required><input className="inp num align-right" name="prevu" type="number" min="0" step="0.01" /></FormField>
                            </div>
                            <FormField label="Commentaire"><textarea className="inp" rows={2} name="commentaire" /></FormField>
                            <FormField label="Difficultés"><textarea className="inp" rows={2} name="difficultes" /></FormField>
                            <FormField label="Justification si l’avancement dépasse 100 %" className="span-all"><input className="inp" name="justification" /></FormField>
                        </div>
                        <div className="form-actions">
                            <Button variant="primary" type="submit" icon={ICON.save} loading={pending}>Enregistrer la réalisation</Button>
                        </div>
                    </form>
                </SectionCard>

                <SectionCard title="Mesures d’indicateurs" icon={ICON.monitoring} subtitle="Valeur calculée, contrôles de qualité, preuve et circuit à quatre niveaux.">
                    {indicateursVisibles.length === 0 ? (
                        <EmptyState compact icon={ICON.monitoring} title="Aucun indicateur">{activite ? 'Aucun indicateur rattaché à cette activité.' : 'Aucun indicateur dans votre périmètre.'}</EmptyState>
                    ) : (
                        <ul className="list-rows">
                            {indicateursVisibles.map((row) => (
                                <li key={row.id}>
                                    <Link to={`/suivi/indicateurs/${row.id}/saisie`} className="list-row">
                                        <span className="list-row-main">
                                            <span className="list-row-title"><span className="mono">{row.code}</span> · {row.label}</span>
                                            {row.unit && <span className="list-row-sub">Unité : {row.unit}</span>}
                                        </span>
                                        <span className="btn btn-ghost btn-sm" aria-hidden="true"><span>Saisir</span><FontAwesomeIcon icon={ICON.next} /></span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <SectionCard title="Saisies et validations de mon périmètre" icon={ICON.tasks} flush>
                <DataTable
                    columns={columns}
                    rows={file}
                    rowKey={(ligne) => `${ligne.type}-${ligne.id}`}
                    loading={loading}
                    minWidth={1100}
                    empty={<EmptyState icon={ICON.entry} title="Aucune saisie dans votre périmètre">Les réalisations et mesures à soumettre ou à valider apparaîtront ici.</EmptyState>}
                />
                <Pager meta={meta} onPage={setPage} />
            </SectionCard>
        </main>
    );
}
