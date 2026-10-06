import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FilterBar,
    FormField,
    ICON,
    Modal,
    PageHeader,
    SearchInput,
    SectionCard,
    StatCard,
    type Column,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

const ORIGINES = [
    { value: 'nomination', label: 'Nomination' },
    { value: 'delegation', label: 'Délégation' },
    { value: 'interim', label: 'Intérim' },
    { value: 'decision', label: 'Décision' },
    { value: 'note', label: 'Note de service' },
    { value: 'autre', label: 'Autre' },
];

const STATUTS: Record<string, string> = {
    en_attente: 'En attente',
    active: 'Active',
    expire_bientot: 'Expire bientôt',
    suspendue: 'Suspendue',
    bloquee: 'Bloquée',
    rejetee: 'Rejetée',
    revoquee: 'Révoquée',
    expiree: 'Expirée',
};

const VIDE = {
    user_id: '', role: '', scope_unit_id: '', plafond_fcfa: '', starts_on: '', ends_on: '', origine: 'nomination', motif: '', derogation: false,
};

export default function HabilitationsPage() {
    const [lignes, setLignes] = useState<any[]>([]);
    const [meta, setMeta] = useState({ total: 0, page: 1, per_page: 25 });
    const [indicateurs, setIndicateurs] = useState({ actives: 0, expirent: 0, en_attente: 0, conflits: 0 });
    const [filtre, setFiltre] = useState({ q: '', role: '', statut: '' });
    const [roles, setRoles] = useState<any[]>([]);
    const [agents, setAgents] = useState<any[]>([]);
    const [structures, setStructures] = useState<any[]>([]);
    const [peutCreer, setPeutCreer] = useState(false);
    const [form, setForm] = useState(VIDE);
    const [ouvert, setOuvert] = useState(false);
    const [conflit, setConflit] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    function load(page = 1) {
        setLoading(true);
        api.get('/admin/habilitations', { params: { ...filtre, page } })
            .then((response) => {
                setLignes(response.data.data);
                setMeta(response.data.meta);
                setIndicateurs(response.data.indicateurs);
                setError('');
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => { load(1); }, [filtre.role, filtre.statut]);

    useEffect(() => {
        api.get('/acteurs').then((response) => {
            setPeutCreer(response.data.courant?.role === 'administrateur_habilitations');
        }).catch(() => setPeutCreer(false));
        Promise.all([
            api.get('/admin/roles'),
            api.get('/admin/utilisateurs'),
            api.get('/admin/structures'),
        ]).then(([rolesResponse, usersResponse, structuresResponse]) => {
            setRoles(rolesResponse.data.data.filter((role: any) => role.active));
            setAgents(usersResponse.data.data);
            setStructures(structuresResponse.data.data);
        }).catch(() => setPeutCreer(false));
    }, []);

    useEffect(() => {
        if (!form.user_id || !form.role) {
            setConflit(null);
            return;
        }
        api.get('/admin/habilitations/conflit', { params: { user_id: form.user_id, role: form.role } })
            .then((response) => setConflit(response.data.data.conflit))
            .catch(() => setConflit(null));
    }, [form.user_id, form.role]);

    async function soumettre(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError('');
        try {
            await api.post('/admin/habilitations', {
                user_id: Number(form.user_id),
                role: form.role,
                scope_unit_id: form.scope_unit_id ? Number(form.scope_unit_id) : null,
                plafond_fcfa: form.plafond_fcfa === '' ? null : Number(form.plafond_fcfa),
                starts_on: form.starts_on,
                ends_on: form.ends_on || null,
                origine: form.origine,
                derogation: form.derogation,
                motif: form.motif || null,
            });
            setOuvert(false);
            setForm(VIDE);
            load(1);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function decider(id: number, decision: string) {
        setPending(true);
        try {
            await api.post(`/admin/habilitations/${id}/decision`, { decision });
            load(meta.page);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const columns: Column<any>[] = [
        { key: 'agent', header: 'Agent', render: (row) => <div><Link to={`/administration/utilisateurs/${row.user_id}`}>{row.agent}</Link><div className="cell-sub">{row.structure || row.fonction}</div></div> },
        { key: 'role', header: 'Rôle', render: (row) => <div><span className="mono">{row.role}</span><div>{row.role_label}</div></div> },
        { key: 'perimetre', header: 'Périmètre', render: (row) => row.perimetre },
        { key: 'plafond', header: 'Plafond FCFA', className: 'num', render: (row) => row.plafond === null ? <span className="subtle">Sans plafond</span> : fcfa(row.plafond) },
        { key: 'validite', header: 'Validité · origine', render: (row) => <div>{row.debut || '—'} → {row.fin || '—'}<div className="cell-sub">{ORIGINES.find((item) => item.value === row.origine)?.label || row.origine || '—'}</div></div> },
        { key: 'statut', header: 'Statut', render: (row) => <Badge size="sm">{STATUTS[row.statut] || row.statut}</Badge> },
        {
            key: 'actions', header: 'Actions', srHeader: true, className: 'cell-actions',
            render: (row) => (
                <div className="btn-group">
                    {row.statut === 'en_attente' && <Button size="sm" icon={ICON.approve} loading={pending} onClick={() => decider(row.id, 'approuver')}>Valider</Button>}
                    {row.statut === 'en_attente' && <Button size="sm" icon={ICON.reject} loading={pending} onClick={() => decider(row.id, 'rejeter')}>Rejeter</Button>}
                    {(row.statut === 'active' || row.statut === 'expire_bientot') && <Button size="sm" icon={ICON.suspend} loading={pending} onClick={() => decider(row.id, 'suspendre')}>Suspendre</Button>}
                    {(row.statut === 'active' || row.statut === 'expire_bientot' || row.statut === 'suspendue') && <Button size="sm" variant="secondary" icon={ICON.cancel} loading={pending} onClick={() => decider(row.id, 'revoquer')}>Révoquer</Button>}
                </div>
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Habilitations"
                subtitle="Une habilitation attribue un rôle à un agent, sur un périmètre, avec un plafond et une période. Elle prend effet après validation."
                actions={peutCreer ? <Button variant="primary" icon={ICON.create} onClick={() => { setError(''); setOuvert(true); }}>Nouvelle habilitation</Button> : undefined}
            />
            <ErrorMessage error={!ouvert ? error : ''} onClose={() => setError('')} />
            <div className="grid-kpi cols-4">
                <StatCard label="Habilitations actives" value={indicateurs.actives} icon={ICON.roles} />
                <StatCard label="Expirent sous 30 jours" value={indicateurs.expirent} icon={ICON.calendar} />
                <StatCard label="En attente de validation" value={indicateurs.en_attente} icon={ICON.submit} />
                <StatCard label="Conflits de séparation des tâches" value={indicateurs.conflits} icon={ICON.warning} />
            </div>
            <SectionCard flush>
                <FilterBar end={<span className="subtle">{meta.total} habilitation(s)</span>}>
                    <form onSubmit={(event) => { event.preventDefault(); load(1); }}>
                        <SearchInput value={filtre.q} onChange={(q) => setFiltre({ ...filtre, q })} placeholder="Nom, matricule" />
                    </form>
                    <select className="inp" aria-label="Rôle" value={filtre.role} onChange={(event) => setFiltre({ ...filtre, role: event.target.value })}>
                        <option value="">Tous les rôles</option>
                        {roles.map((role) => <option key={role.code} value={role.code}>{role.label}</option>)}
                    </select>
                    <select className="inp" aria-label="Statut" value={filtre.statut} onChange={(event) => setFiltre({ ...filtre, statut: event.target.value })}>
                        <option value="">Tous les statuts</option>
                        {Object.entries(STATUTS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={lignes}
                    rowKey={(row) => row.id}
                    loading={loading}
                    minWidth={980}
                    empty={<EmptyState icon={ICON.roles} title="Aucune habilitation" compact>Les rôles principaux des comptes restent visibles dans Utilisateurs. Une habilitation ajoutée apparaît ici.</EmptyState>}
                />
                {meta.total > meta.per_page && (
                    <div className="btn-group" style={{ padding: '12px 16px' }}>
                        <Button size="sm" disabled={meta.page <= 1} onClick={() => load(meta.page - 1)}>Précédent</Button>
                        <span className="subtle">Page {meta.page}</span>
                        <Button size="sm" disabled={meta.page * meta.per_page >= meta.total} onClick={() => load(meta.page + 1)}>Suivant</Button>
                    </div>
                )}
            </SectionCard>
            <p className="subtle">Le suivi des intérims, des délégations administratives et des incompatibilités reste dans <Link to="/administration/suivi-acces">Suivi des accès</Link>.</p>

            {ouvert && (
                <Modal
                    size="lg"
                    title="Nouvelle habilitation"
                    description="À la soumission, l’habilitation passe en attente. Une tâche est adressée à l’ordonnateur."
                    icon={ICON.roles}
                    onClose={() => setOuvert(false)}
                    footer={(
                        <>
                            <Button onClick={() => setOuvert(false)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="habilitation-form" icon={ICON.submit} loading={pending} disabled={!form.user_id || !form.role || !form.starts_on || (Boolean(conflit) && !form.derogation)}>Soumettre pour validation</Button>
                        </>
                    )}
                >
                    <form id="habilitation-form" className="stack" onSubmit={soumettre}>
                        <div className="form-grid">
                            <FormField label="Agent" required>
                                <select className="inp" value={form.user_id} onChange={(event) => setForm({ ...form, user_id: event.target.value })}>
                                    <option value="">Choisir</option>
                                    {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.nom} · {agent.structure || agent.fonction}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Rôle attribué" required>
                                <select className="inp" value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value, derogation: false })}>
                                    <option value="">Choisir</option>
                                    {roles.map((role) => <option key={role.code} value={role.code}>{role.code} · {role.label}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Périmètre" optional hint="Vide : toute la Commission.">
                                <select className="inp" value={form.scope_unit_id} onChange={(event) => setForm({ ...form, scope_unit_id: event.target.value })}>
                                    <option value="">Toute la Commission</option>
                                    {structures.map((structure) => <option key={structure.id} value={structure.id}>{structure.label}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Plafond par opération (FCFA)" optional hint="Vide : sans plafond.">
                                <input className="inp" inputMode="numeric" value={form.plafond_fcfa} onChange={(event) => setForm({ ...form, plafond_fcfa: event.target.value.replace(/\D/g, '') })} />
                            </FormField>
                            <FormField label="Début" required>
                                <input className="inp" type="date" value={form.starts_on} onChange={(event) => setForm({ ...form, starts_on: event.target.value })} />
                            </FormField>
                            <FormField label="Fin" optional>
                                <input className="inp" type="date" value={form.ends_on} onChange={(event) => setForm({ ...form, ends_on: event.target.value })} />
                            </FormField>
                            <FormField label="Origine" required>
                                <select className="inp" value={form.origine} onChange={(event) => setForm({ ...form, origine: event.target.value })}>
                                    {ORIGINES.map((origine) => <option key={origine.value} value={origine.value}>{origine.label}</option>)}
                                </select>
                            </FormField>
                        </div>
                        {conflit && (
                            <Alert tone="danger" title="Conflit de séparation des tâches">
                                {conflit} Retirez l’habilitation existante ou demandez une dérogation motivée.
                                <label className="cluster" style={{ marginTop: 8 }}>
                                    <input type="checkbox" checked={form.derogation} onChange={(event) => setForm({ ...form, derogation: event.target.checked })} />
                                    Demander une dérogation
                                </label>
                            </Alert>
                        )}
                        {form.derogation && (
                            <FormField label="Motif de la dérogation" required>
                                <textarea className="inp" value={form.motif} onChange={(event) => setForm({ ...form, motif: event.target.value })} />
                            </FormField>
                        )}
                    </form>
                    <ErrorMessage error={error} title="Soumission refusée" />
                </Modal>
            )}
        </main>
    );
}
