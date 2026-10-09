import { faUserPlus, faUserSlash } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Alert,
    Badge,
    Button,
    DataTable,
    Drawer,
    EmptyState,
    ErrorMessage,
    FilterBar,
    FormField,
    ICON,
    KeyValueList,
    Modal,
    PageHeader,
    SearchInput,
    SectionCard,
    StatusBadge,
    useDialogs,
    useToast,
    WorkflowTimeline,
    type Column,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

const EMPTY = {
    nom: '', prenom: '', email: '', matricule: '', telephone: '', organization_unit_id: '',
    fonction: '', role: '', initiales: '', password: '', mfa_required: false,
};

export default function AdminUsers() {
    const { id: userId } = useParams();
    const toast = useToast();
    const { prompt } = useDialogs();
    const [rows, setRows] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [roles, setRoles] = useState<any[]>([]);
    const [structures, setStructures] = useState<any[]>([]);
    const [form, setForm] = useState(EMPTY);
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState('');
    const [error, setError] = useState('');
    const [selected, setSelected] = useState<any>(null);
    const [loadingReferences, setLoadingReferences] = useState(true);
    const [pending, setPending] = useState(false);
    const [unite, setUnite] = useState('');
    const [droits, setDroits] = useState<any>(null);
    const [editing, setEditing] = useState(false);
    const [editForm, setEditForm] = useState<any>({});
    const [editingId, setEditingId] = useState<number | null>(null);

    function load() {
        setLoading(true);
        api.get('/admin/utilisateurs')
            .then((response) => { setRows(response.data.data); setLoadError(null); })
            .catch((caught) => setLoadError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    async function loadReferences() {
        setLoadingReferences(true);
        setError('');
        try {
            const [rolesResponse, structuresResponse] = await Promise.all([
                api.get('/admin/roles'),
                api.get('/admin/structures'),
            ]);
            setRoles(rolesResponse.data.data.filter((role) => role.active));
            setStructures(structuresResponse.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setLoadingReferences(false);
        }
    }

    useEffect(() => {
        load();
        loadReferences();
    }, []);

    async function createUser(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/admin/utilisateurs', { ...form, organization_unit_id: Number(form.organization_unit_id) });
            setForm(EMPTY);
            setCreating(false);
            toast.success('Compte créé.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function editer(user: { id: number }) {
        setError('');
        try {
            const response = await api.get(`/admin/utilisateurs/${user.id}`);
            const donnees = response.data.data;
            setEditForm({
                nom: donnees.nom ?? '',
                email: donnees.email ?? '',
                matricule: donnees.matricule ?? '',
                telephone: donnees.telephone ?? '',
                organization_unit_id: donnees.organization_unit_id ?? '',
                fonction: donnees.fonction ?? '',
                role: donnees.role ?? '',
                initiales: donnees.initiales ?? '',
                mfa_required: donnees.mfa ?? false,
            });
            setEditingId(user.id);
            setEditing(true);
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function modifierCompte(event?: FormEvent) {
        event?.preventDefault();
        if (!editingId) return;
        setPending(true);
        setError('');
        try {
            const response = await api.put(`/admin/utilisateurs/${editingId}`, {
                ...editForm,
                organization_unit_id: Number(editForm.organization_unit_id),
            });
            setEditForm(response.data.data);
            setEditing(false);
            setEditingId(null);
            toast.success('Compte modifié.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function deactivate(user) {
        const values = await prompt({
            title: 'Désactiver le compte',
            description: `${user.nom} ne pourra plus se connecter. Ses signatures et ses dossiers restent consultables.`,
            confirmLabel: 'Désactiver',
            tone: 'danger',
            icon: faUserSlash,
            fields: [{ name: 'motif', label: 'Motif de désactivation', required: true }],
        });
        if (!values) {
            return;
        }
        try {
            await api.post(`/admin/utilisateurs/${user.id}/desactiver`, { motif: values.motif });
            toast.success('Compte désactivé.');
            load();
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    async function enregistrerPerimetre(unites: number[]) {
        if (!selected) return;
        setPending(true);
        try {
            const response = await api.put(`/admin/utilisateurs/${selected.data.id}/perimetre`, { unites });
            setSelected({ ...selected, data: response.data.data });
            setUnite('');
            toast.success(unites.length === 0 ? 'Périmètre retiré : la chaîne entière redevient visible.' : 'Périmètre de consultation enregistré.');
        } catch (caught) {
            toast.error(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function retirerRole(code: string) {
        if (!selected) return;
        setPending(true);
        try {
            const response = await api.post(`/admin/utilisateurs/${selected.data.id}/roles/retirer`, { role: code });
            setSelected({ ...selected, data: response.data.data });
            toast.success('Rôle retiré.');
            load();
        } catch (caught) {
            toast.error(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function openUser(user: { id: number }) {
        try {
            const [response, droitsResponse] = await Promise.all([
                api.get(`/admin/utilisateurs/${user.id}`),
                api.get(`/admin/utilisateurs/${user.id}/droits`),
            ]);
            setSelected(response.data);
            setDroits(droitsResponse.data.data);
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    useEffect(() => {
        if (userId) {
            openUser({ id: Number(userId) });
        }
    }, [userId]);

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();
        return term ? rows.filter((row) => [row.nom, row.email, row.fonction, row.role, row.structure].some((value) => String(value ?? '').toLowerCase().includes(term))) : rows;
    }, [rows, search]);

    const referencesMissing = !loadingReferences && (!roles.length || !structures.length);
    const set = (key: keyof typeof EMPTY) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });
    const edit = (key: string) => (event: { target: { value: string } }) => setEditForm({ ...editForm, [key]: event.target.value });

    const columns: Column<any>[] = [
        {
            key: 'nom',
            header: 'Utilisateur',
            render: (row) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span className="avatar size-sm" aria-hidden="true">{String(row.nom ?? '?').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()}</span>
                    <div><div className="cell-primary">{row.nom}</div><div className="cell-sub">{row.email}</div></div>
                </div>
            ),
        },
        { key: 'fonction', header: 'Fonction', render: (row) => row.fonction },
        { key: 'role', header: 'Rôle', render: (row) => <Badge tone="brand" size="sm"><span className="mono">{row.role}</span></Badge> },
        { key: 'structure', header: 'Structure', render: (row) => row.structure },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut === 'actif' ? 'actif' : 'desactive'} libelle={row.statut} /> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            className: 'cell-actions',
            render: (row) => (
                <ActionMenu label={`Actions pour ${row.nom}`} actions={[
                    { label: 'Voir la fiche', icon: ICON.view, onSelect: () => openUser(row) },
                    { label: 'Modifier le compte', icon: ICON.edit, onSelect: () => editer(row), hidden: row.statut !== 'actif' },
                    { label: 'Désactiver le compte', icon: faUserSlash, onSelect: () => deactivate(row), hidden: row.statut !== 'actif', danger: true, separatorBefore: true },
                ]} />
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Utilisateurs"
                subtitle="Un compte désactivé ne se connecte plus. Ses signatures et ses dossiers restent consultables."
                actions={<Button variant="primary" icon={faUserPlus} onClick={() => { setError(''); setCreating(true); }} disabled={loadingReferences}>Nouveau compte</Button>}
            />

            {referencesMissing && (
                <Alert tone="warning" title="Référentiels indisponibles" actions={<Button size="sm" icon={ICON.retry} onClick={loadReferences}>Recharger les référentiels</Button>}>
                    Les rôles ou les structures n’ont pas pu être chargés : la création de compte est impossible.
                </Alert>
            )}
            {!creating && <ErrorMessage error={error} onClose={() => setError('')} />}

            <SectionCard flush>
                <FilterBar end={<span className="subtle">{filtered.length} compte(s)</span>}>
                    <SearchInput value={search} onChange={setSearch} placeholder="Nom, e-mail, fonction, rôle, structure…" />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={filtered}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={loadError}
                    onRowClick={openUser}
                    rowLabel={(row) => `Voir la fiche de ${(row as any).nom}`}
                    minWidth={860}
                    empty={<EmptyState icon={ICON.users} title={search ? 'Aucun compte trouvé' : 'Aucun compte'} />}
                />
            </SectionCard>

            {creating && (
                <Modal
                    size="lg"
                    title="Nouveau compte"
                    description="Le compte est créé actif avec le mot de passe initial communiqué à son titulaire."
                    icon={faUserPlus}
                    onClose={() => setCreating(false)}
                    footer={(
                        <>
                            <Button onClick={() => setCreating(false)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="user-form" icon={ICON.save} loading={pending} disabled={loadingReferences || !roles.length || !structures.length}>Créer le compte</Button>
                        </>
                    )}
                >
                    <form id="user-form" onSubmit={createUser} className="stack">
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">1</span>Identité</h3>
                            <div className="form-grid" style={{ ['--cols' as string]: 3 }}>
                                <FormField label="Prénom" required><input className="inp" value={form.prenom} onChange={set('prenom')} /></FormField>
                                <FormField label="Nom" required><input className="inp" value={form.nom} onChange={set('nom')} /></FormField>
                                <FormField label="Initiales" required><input className="inp" value={form.initiales} onChange={set('initiales')} /></FormField>
                                <FormField label="E-mail" required className="span-2"><input className="inp" type="email" value={form.email} onChange={set('email')} /></FormField>
                                <FormField label="Matricule" optional><input className="inp mono" value={form.matricule} onChange={set('matricule')} /></FormField>
                            </div>
                        </div>
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">2</span>Rattachement et habilitation</h3>
                            <div className="form-grid">
                                <FormField label="Fonction" required className="span-all"><input className="inp" value={form.fonction} onChange={set('fonction')} /></FormField>
                                <FormField label="Structure" required>
                                    <select className="inp" value={form.organization_unit_id} onChange={set('organization_unit_id')} disabled={loadingReferences || !structures.length}>
                                        <option value="">Choisir une structure</option>
                                        {structures.map((structure) => <option key={structure.id} value={structure.id}>{structure.label}</option>)}
                                    </select>
                                </FormField>
                                <FormField label="Rôle" required hint="La séparation des fonctions s’applique à l’attribution.">
                                    <select className="inp" value={form.role} onChange={set('role')} disabled={loadingReferences || !roles.length}>
                                        <option value="">Choisir un rôle</option>
                                        {roles.map((role) => <option key={role.id} value={role.code}>{role.label}</option>)}
                                    </select>
                                </FormField>
                            </div>
                        </div>
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">3</span>Sécurité</h3>
                            <FormField label="Mot de passe initial" required hint="8 caractères minimum.">
                                <input className="inp" type="password" autoComplete="new-password" value={form.password} onChange={set('password')} minLength={8} />
                            </FormField>
                        </div>
                    </form>
                    <ErrorMessage error={error} title="Création refusée" />
                </Modal>
            )}

            {editing && (
                <Modal
                    size="lg"
                    title="Modifier le compte"
                    description="Mettez à jour l’identité, le rattachement et le rôle principal du compte."
                    icon={ICON.edit}
                    onClose={() => { setEditing(false); setEditingId(null); }}
                    footer={(
                        <>
                            <Button onClick={() => { setEditing(false); setEditingId(null); }}>Annuler</Button>
                            <Button variant="primary" icon={ICON.save} loading={pending} disabled={loadingReferences || !roles.length || !structures.length} onClick={() => modifierCompte()}>Enregistrer</Button>
                        </>
                    )}
                >
                    <form id="edit-user-form" onSubmit={modifierCompte} className="stack">
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">1</span>Identité</h3>
                            <div className="form-grid" style={{ ['--cols' as string]: 3 }}>
                                <FormField label="Nom complet" required className="span-2"><input className="inp" value={editForm.nom ?? ''} onChange={edit('nom')} /></FormField>
                                <FormField label="Initiales" required><input className="inp" value={editForm.initiales ?? ''} onChange={edit('initiales')} /></FormField>
                                <FormField label="E-mail" required className="span-2"><input className="inp" type="email" value={editForm.email ?? ''} onChange={edit('email')} /></FormField>
                                <FormField label="Matricule" optional><input className="inp mono" value={editForm.matricule ?? ''} onChange={edit('matricule')} /></FormField>
                                <FormField label="Téléphone" optional><input className="inp" value={editForm.telephone ?? ''} onChange={edit('telephone')} /></FormField>
                            </div>
                        </div>
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">2</span>Rattachement et rôle</h3>
                            <div className="form-grid">
                                <FormField label="Fonction" required className="span-all"><input className="inp" value={editForm.fonction ?? ''} onChange={edit('fonction')} /></FormField>
                                <FormField label="Structure" required>
                                    <select className="inp" value={editForm.organization_unit_id ?? ''} onChange={edit('organization_unit_id')} disabled={loadingReferences || !structures.length}>
                                        <option value="">Choisir une structure</option>
                                        {structures.map((structure) => <option key={structure.id} value={structure.id}>{structure.label}</option>)}
                                    </select>
                                </FormField>
                                <FormField label="Rôle principal" required>
                                    <select className="inp" value={editForm.role ?? ''} onChange={edit('role')} disabled={loadingReferences || !roles.length}>
                                        <option value="">Choisir un rôle</option>
                                        {roles.map((role) => <option key={role.id} value={role.code}>{role.label}</option>)}
                                    </select>
                                </FormField>
                            </div>
                        </div>
                        <div className="form-section">
                            <h3 className="form-section-title"><span className="card-title-icon" aria-hidden="true">3</span>Sécurité</h3>
                            <FormField label="Exiger l’authentification forte (MFA)">
                                <input type="checkbox" checked={!!editForm.mfa_required} onChange={(event) => setEditForm({ ...editForm, mfa_required: event.target.checked })} />
                            </FormField>
                        </div>
                    </form>
                    <ErrorMessage error={error} title="Modification refusée" />
                </Modal>
            )}

            {selected && (
                <Drawer
                    title={selected.data.nom}
                    description={`${selected.data.email} · ${selected.data.fonction}`}
                    icon={ICON.user}
                    onClose={() => setSelected(null)}
                    footer={<Button onClick={() => setSelected(null)}>Fermer</Button>}
                >
                    <KeyValueList items={[
                        { label: 'E-mail', value: selected.data.email },
                        { label: 'Matricule', value: selected.data.matricule || '—' },
                        { label: 'Fonction', value: selected.data.fonction },
                        { label: 'Structure', value: selected.data.structure || '—' },
                        { label: 'Dernière connexion', value: selected.data.derniere_connexion || '—' },
                        { label: 'Authentification forte (MFA)', value: <Badge tone={selected.data.mfa ? 'success' : 'neutral'} size="sm">{selected.data.mfa ? 'Exigée' : 'Non exigée'}</Badge> },
                    ]} />
                    <SectionCard title="Habilitations en cours" icon={ICON.roles}>
                        {(droits?.habilitations ?? []).length === 0 && <p className="subtle">Aucune habilitation ajoutée. Le rôle principal du compte reste {selected.data.role}.</p>}
                        {(droits?.habilitations ?? []).map((item: any) => (
                            <p key={item.id}>
                                <span className="mono">{item.role}</span> {item.role_label} · {item.statut}
                                <span className="subtle"> — {item.perimetre}{item.plafond ? ` · plafond ${item.plafond} FCFA` : ''}{item.debut ? ` · ${item.debut} → ${item.fin || '—'}` : ''}</span>
                            </p>
                        ))}
                    </SectionCard>
                    <SectionCard title="Droits effectifs" icon={ICON.lock} subtitle="Union des rôles détenus. Une consultation globale n’élargit pas une validation limitée.">
                        {(droits?.droits ?? []).length === 0 && <p className="subtle">Aucun droit de matrice pour les rôles détenus.</p>}
                        {(droits?.droits ?? []).map((droit: any) => (
                            <p key={droit.permission}>
                                <strong>{droit.module}</strong> · {droit.action}
                                <span className="subtle"> — {droit.provenance.join(' + ') || '—'}{droit.applique ? ' · contrôlé' : ''}</span>
                            </p>
                        ))}
                    </SectionCard>
                    <SectionCard title="Rôles du compte" icon={ICON.roles} subtitle="Le rôle principal reste la fonction du compte. Un rôle ajouté peut être retiré.">
                        <p>Rôle principal : <span className="mono">{selected.data.role}</span></p>
                        <div className="btn-group">
                            {(selected.data.roles ?? []).filter((code: string) => code !== selected.data.role).map((code: string) => (
                                <Button key={code} size="sm" variant="secondary" icon={ICON.close} loading={pending} onClick={() => retirerRole(code)}>
                                    Retirer {code}
                                </Button>
                            ))}
                        </div>
                        {(selected.data.roles ?? []).filter((code: string) => code !== selected.data.role).length === 0 && <p className="subtle">Aucun rôle ajouté.</p>}
                    </SectionCard>
                    <SectionCard title="Périmètre de consultation" icon={ICON.lock} subtitle="Sans structure, le compte voit toute la chaîne. Une structure limite les dossiers de cette structure.">
                        {(selected.data.perimetre ?? []).length === 0 && <p className="subtle">Aucune restriction.</p>}
                        <div className="btn-group">
                            {(selected.data.perimetre ?? []).map((id: number) => {
                                const structure = structures.find((item) => item.id === id);

                                return (
                                    <Button key={id} size="sm" variant="secondary" icon={ICON.close} loading={pending} onClick={() => enregistrerPerimetre((selected.data.perimetre ?? []).filter((value: number) => value !== id))}>
                                        {structure?.label ?? `Structure ${id}`}
                                    </Button>
                                );
                            })}
                        </div>
                        <form className="cluster" style={{ alignItems: 'flex-end' }} onSubmit={(event) => { event.preventDefault(); enregistrerPerimetre([...(selected.data.perimetre ?? []), Number(unite)]); }}>
                            <FormField label="Ajouter une structure" style={{ flex: '1 1 240px' }}>
                                <select className="inp" value={unite} onChange={(event) => setUnite(event.target.value)}>
                                    <option value="">Choisir</option>
                                    {structures.filter((item) => !(selected.data.perimetre ?? []).includes(item.id)).map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
                                </select>
                            </FormField>
                            <Button type="submit" icon={ICON.save} loading={pending} disabled={!unite}>Ajouter</Button>
                        </form>
                    </SectionCard>
                    <SectionCard title="Historique d’administration" icon={ICON.history}>
                        <WorkflowTimeline
                            emptyText="Aucune action administrative enregistrée."
                            events={(selected.audit ?? []).map((event) => ({ action: event.action, note: event.motif || undefined }))}
                        />
                    </SectionCard>
                </Drawer>
            )}
        </main>
    );
}
