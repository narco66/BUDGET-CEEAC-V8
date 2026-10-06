import { faPeopleArrows, faScaleUnbalanced } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    Modal,
    PageHeader,
    SectionCard,
    TableSkeleton,
    useToast,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

const EMPTY = { delegant_id: '', delegataire_id: '', fonction: '', starts_on: '', ends_on: '', motif: '' };
const EMPTY_INTERIM = { titulaire_id: '', interim_id: '', starts_on: '', ends_on: '' };

export default function AdminAccess() {
    const toast = useToast();
    const { hash } = useLocation();
    const [matrix, setMatrix] = useState<any>(null);
    const [users, setUsers] = useState<any[]>([]);
    const [form, setForm] = useState(EMPTY);
    const [delegations, setDelegations] = useState<any[]>([]);
    const [interims, setInterims] = useState<any[]>([]);
    const [interimForm, setInterimForm] = useState(EMPTY_INTERIM);
    const [openingInterim, setOpeningInterim] = useState(false);
    const [loading, setLoading] = useState(true);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const [registre, setRegistre] = useState<any>(null);
    const [catalogue, setCatalogue] = useState<Array<{ code: string; module: string; label: string; appliquee?: boolean }>>([]);
    const [verification, setVerification] = useState({ user_id: '', action: 'engagement.viser', organization_unit_id: '', montant: '' });
    const [explication, setExplication] = useState<{ autorise: boolean; raisons: string[] } | null>(null);
    const [structures, setStructures] = useState<Array<{ id: number; label: string }>>([]);
    const [recherche, setRecherche] = useState('');
    const [droit, setDroit] = useState({ role: '', permission: 'eb.creer', accorder: '1', motif: '' });
    const [roleForm, setRoleForm] = useState({ code: '', label: '', active: '1', motif: '' });
    const [sod, setSod] = useState({ role_a: '', role_b: '', label: '', motif: '' });
    const [revocation, setRevocation] = useState({ id: '', motif: '' });

    function chargerRevue() {
        api.get('/habilitations/revues').then((response) => setRegistre(response.data.data)).catch(() => setRegistre(null));
    }

    function load(q?: string) {
        const terme = (q ?? recherche).trim();
        setLoading(true);
        Promise.all([api.get('/admin/matrice', { params: terme ? { q: terme } : {} }), api.get('/admin/utilisateurs'), api.get('/admin/delegations'), api.get('/admin/interims'), api.get('/admin/habilitations/catalogue'), api.get('/admin/structures')])
            .then(([matrixResponse, usersResponse, delegationsResponse, interimsResponse, catalogueResponse, structuresResponse]) => {
                setMatrix(matrixResponse.data);
                setUsers(usersResponse.data.data);
                setDelegations(delegationsResponse.data.data);
                setInterims(interimsResponse.data.data);
                setCatalogue(catalogueResponse.data.data);
                setStructures(structuresResponse.data.data);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        load();
        chargerRevue();
    }, []);

    useEffect(() => {
        const id = hash.replace('#', '');
        if (id === '') {
            return;
        }
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [hash, loading]);

    async function enregistrerDroit(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post(`/admin/roles/${droit.role}/permissions`, {
                permission: droit.permission,
                accorder: droit.accorder === '1',
                motif: droit.motif,
            });
            setDroit({ ...droit, motif: '' });
            toast.success(droit.accorder === '1' ? 'Permission accordée.' : 'Permission retirée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function enregistrerRole(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.put(`/admin/roles/${roleForm.code}`, {
                label: roleForm.label,
                active: roleForm.active === '1',
                motif: roleForm.motif,
            });
            setRoleForm({ ...roleForm, motif: '' });
            toast.success('Rôle enregistré.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function enregistrerSod(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/admin/incompatibilites', sod);
            setSod({ role_a: '', role_b: '', label: '', motif: '' });
            toast.success('Incompatibilité enregistrée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function desactiverSod(id: number) {
        const motif = sod.motif.trim();
        if (motif === '') {
            setError('Indiquez le motif avant de désactiver une incompatibilité.');
            return;
        }
        setPending(true);
        try {
            await api.post(`/admin/incompatibilites/${id}/desactiver`, { motif });
            toast.success('Incompatibilité désactivée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function revoquerDelegation(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post(`/admin/delegations/${revocation.id}/revoquer`, { motif: revocation.motif });
            setRevocation({ id: '', motif: '' });
            toast.success('Délégation révoquée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function ouvrirRevue() {
        setError('');
        setPending(true);
        try {
            const response = await api.post('/habilitations/revues');
            toast.success(`Revue ${response.data.data.reference} ouverte.`);
            chargerRevue();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function confirmerRevue() {
        setError('');
        setPending(true);
        try {
            await api.post(`/habilitations/revues/${registre.reference}/confirmer`);
            toast.success('Accès confirmé.');
            chargerRevue();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function delegate(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/admin/delegations', {
                ...form,
                delegant_id: Number(form.delegant_id),
                delegataire_id: Number(form.delegataire_id),
            });
            setForm(EMPTY);
            setCreating(false);
            toast.success('Délégation enregistrée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function ouvrirInterim(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post('/admin/interims', {
                titulaire_id: Number(interimForm.titulaire_id),
                interim_id: Number(interimForm.interim_id),
                starts_on: interimForm.starts_on,
                ends_on: interimForm.ends_on,
            });
            setInterimForm(EMPTY_INTERIM);
            setOpeningInterim(false);
            toast.success('Intérim ouvert : l’intérimaire tient le rôle du titulaire.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function cloturerInterim(id: number) {
        setError('');
        setPending(true);
        try {
            await api.post(`/admin/interims/${id}/cloturer`);
            toast.success('Intérim clos : le rôle du titulaire n’est plus tenu.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function verifier(event: FormEvent) {
        event.preventDefault();
        setError('');
        setExplication(null);
        setPending(true);
        try {
            const response = await api.post('/admin/habilitations/verifier', {
                user_id: Number(verification.user_id),
                action: verification.action,
                organization_unit_id: verification.organization_unit_id ? Number(verification.organization_unit_id) : null,
                montant: verification.montant === '' ? null : Number(verification.montant),
            });
            setExplication(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const set = (key: keyof typeof EMPTY) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });
    const setInterim = (key: keyof typeof EMPTY_INTERIM) => (event: { target: { value: string } }) => setInterimForm({ ...interimForm, [key]: event.target.value });

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Rôles et habilitations"
                subtitle="La séparation des fonctions bloque l’attribution de rôles incompatibles. Une délégation échue ne produit plus de droit."
                actions={(
                    <>
                        <Button icon={ICON.roles} onClick={() => { setError(''); setOpeningInterim(true); }}>Nouvel intérim</Button>
                        <Button variant="primary" icon={ICON.create} onClick={() => { setError(''); setCreating(true); }}>Nouvelle délégation</Button>
                    </>
                )}
            />

            {!creating && <ErrorMessage error={error} onClose={() => setError('')} />}

            <SectionCard title="Vérifier les droits" icon={ICON.roles} subtitle="La simulation explique un droit déjà appliqué par les workflows. Elle ne connecte pas le compte et n’exécute pas l’action.">
                <form className="cluster" style={{ alignItems: 'flex-end' }} onSubmit={verifier}>
                    <FormField label="Compte" required style={{ flex: '1 1 220px' }}>
                        <select className="inp" value={verification.user_id} onChange={(event) => setVerification({ ...verification, user_id: event.target.value })} required>
                            <option value="">Choisir</option>
                            {users.map((user) => <option key={user.id} value={user.id}>{user.nom} · {user.role}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Action" required style={{ flex: '1 1 240px' }}>
                        <select className="inp" value={verification.action} onChange={(event) => setVerification({ ...verification, action: event.target.value })}>
                            {catalogue.map((item) => <option key={item.code} value={item.code}>{item.module} · {item.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Structure" style={{ flex: '1 1 200px' }}>
                        <select className="inp" value={verification.organization_unit_id} onChange={(event) => setVerification({ ...verification, organization_unit_id: event.target.value })}>
                            <option value="">Non demandée</option>
                            {structures.map((item) => <option key={item.id} value={item.id}>{item.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Montant XAF" style={{ flex: '0 1 140px' }}>
                        <input className="inp" inputMode="numeric" value={verification.montant} onChange={(event) => setVerification({ ...verification, montant: event.target.value.replace(/\D/g, '') })} />
                    </FormField>
                    <Button type="submit" icon={ICON.roles} loading={pending} disabled={!verification.user_id}>Vérifier</Button>
                </form>
                {explication && (
                    <Alert tone={explication.autorise ? 'success' : 'warning'} title={explication.autorise ? 'Droit ouvert au regard de cette règle' : 'Droit refusé'}>
                        {explication.raisons.join(' ')}
                    </Alert>
                )}
            </SectionCard>

            <div className="layout-aside is-wide">
                <SectionCard id="matrice" title="Matrice des rôles" icon={ICON.roles} subtitle="Les cinq permissions de la chaîne s’appliquent au rôle qui les porte. Les autres actions suivent le rôle, le périmètre ou le seuil." flush>
                    <form className="cluster" style={{ padding: '12px 16px 0', alignItems: 'flex-end' }} onSubmit={(event) => { event.preventDefault(); load(recherche); }}>
                        <FormField label="Recherche" style={{ flex: '1 1 220px' }}>
                            <input className="inp" value={recherche} onChange={(event) => setRecherche(event.target.value)} placeholder="Code ou libellé" />
                        </FormField>
                        <Button type="submit" icon={ICON.roles}>Filtrer</Button>
                    </form>
                    {loading && !matrix ? <TableSkeleton rows={5} columns={2} /> : (
                        <DataTable
                            columns={[
                                { key: 'role', header: 'Rôle', width: '30%', render: (role: any) => <div><div className="cell-primary">{role.label}</div><div className="cell-sub mono">{role.code}</div></div> },
                                {
                                    key: 'permissions',
                                    header: 'Permissions atomiques',
                                    render: (role: any) => role.permissions.length === 0
                                        ? <span className="subtle">Aucune permission atomique</span>
                                        : <div className="cluster" style={{ gap: 4 }}>{role.permissions.map((permission: string) => <Badge key={permission} tone="neutral" size="sm"><span className="mono">{permission}</span></Badge>)}</div>,
                                },
                            ]}
                            rows={matrix?.roles ?? []}
                            rowKey={(role: any) => role.code}
                        />
                    )}
                </SectionCard>
                <SectionCard title="Séparation des fonctions" icon={faScaleUnbalanced} subtitle="Couples de rôles incompatibles">
                    {(matrix?.conflits ?? []).length === 0 ? (
                        <EmptyState icon={faScaleUnbalanced} title="Aucune règle" compact />
                    ) : (
                        <ul className="list-rows">
                            {matrix.conflits.map((rule) => (
                                <li key={rule.id} className="list-row" style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 6 }}>
                                    <span className="list-row-title">{rule.label}</span>
                                    <span className="cluster" style={{ gap: 6 }}>
                                        <Badge tone="brand" size="sm">{rule.role_a}</Badge>
                                        <span className="subtle">incompatible avec</span>
                                        <Badge tone="brand" size="sm">{rule.role_b}</Badge>
                                        <Badge tone={rule.active === false ? 'neutral' : 'success'} size="sm">{rule.active === false ? 'Inactive' : 'Active'}</Badge>
                                        {rule.active !== false && (
                                            <Button size="sm" variant="danger-outline" loading={pending} onClick={() => desactiverSod(rule.id)}>Désactiver</Button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <SectionCard id="droits" title="Droits de la chaîne" icon={ICON.roles} subtitle="Accorder ou retirer une permission change le contrôle serveur. Le motif est conservé dans l’audit. Aucune case n’accorde tout.">
                <form className="form-grid" onSubmit={enregistrerDroit}>
                    <FormField label="Rôle" required>
                        <select className="inp" value={droit.role} onChange={(event) => setDroit({ ...droit, role: event.target.value })} required>
                            <option value="">Choisir</option>
                            {(matrix?.roles ?? []).map((role: any) => <option key={role.code} value={role.code}>{role.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Permission appliquée" required>
                        <select className="inp" value={droit.permission} onChange={(event) => setDroit({ ...droit, permission: event.target.value })}>
                            {(matrix?.permissions_appliquees ?? catalogue.filter((item) => item.appliquee)).map((item: any) => <option key={item.code} value={item.code}>{item.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Effet" required>
                        <select className="inp" value={droit.accorder} onChange={(event) => setDroit({ ...droit, accorder: event.target.value })}>
                            <option value="1">Accorder</option>
                            <option value="0">Retirer</option>
                        </select>
                    </FormField>
                    <FormField label="Motif" required><input className="inp" value={droit.motif} onChange={(event) => setDroit({ ...droit, motif: event.target.value })} required /></FormField>
                    <div className="span-all"><Button type="submit" variant="primary" icon={ICON.save} loading={pending}>Enregistrer le droit</Button></div>
                </form>
            </SectionCard>

            <div className="layout-aside is-wide">
                <SectionCard title="Rôle" icon={ICON.roles} subtitle="Le code ne change pas. Un rôle système ne se supprime pas. Le désactiver retire son effet sur les comptes.">
                    <form className="form-grid" onSubmit={enregistrerRole}>
                        <FormField label="Rôle" required>
                            <select className="inp" value={roleForm.code} onChange={(event) => {
                                const code = event.target.value;
                                const role = (matrix?.roles ?? []).find((item: any) => item.code === code);
                                setRoleForm({ code, label: role?.label ?? '', active: role?.actif === false ? '0' : '1', motif: roleForm.motif });
                            }} required>
                                <option value="">Choisir</option>
                                {(matrix?.roles ?? []).map((role: any) => <option key={role.code} value={role.code}>{role.label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Libellé" required><input className="inp" value={roleForm.label} onChange={(event) => setRoleForm({ ...roleForm, label: event.target.value })} required /></FormField>
                        <FormField label="Statut" required>
                            <select className="inp" value={roleForm.active} onChange={(event) => setRoleForm({ ...roleForm, active: event.target.value })}>
                                <option value="1">Actif</option>
                                <option value="0">Inactif</option>
                            </select>
                        </FormField>
                        <FormField label="Motif" required><input className="inp" value={roleForm.motif} onChange={(event) => setRoleForm({ ...roleForm, motif: event.target.value })} required /></FormField>
                        <div className="span-all"><Button type="submit" icon={ICON.save} loading={pending}>Enregistrer le rôle</Button></div>
                    </form>
                </SectionCard>
                <SectionCard id="incompatibilites" title="Incompatibilité" icon={faScaleUnbalanced} subtitle="La règle bloque l’attribution des deux rôles. La désactiver utilise le motif saisi ci-dessous.">
                    <form className="form-grid" onSubmit={enregistrerSod}>
                        <FormField label="Premier rôle" required>
                            <select className="inp" value={sod.role_a} onChange={(event) => setSod({ ...sod, role_a: event.target.value })} required>
                                <option value="">Choisir</option>
                                {(matrix?.roles ?? []).map((role: any) => <option key={role.code} value={role.code}>{role.label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Second rôle" required>
                            <select className="inp" value={sod.role_b} onChange={(event) => setSod({ ...sod, role_b: event.target.value })} required>
                                <option value="">Choisir</option>
                                {(matrix?.roles ?? []).map((role: any) => <option key={role.code} value={role.code}>{role.label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Libellé" required className="span-all"><input className="inp" value={sod.label} onChange={(event) => setSod({ ...sod, label: event.target.value })} required /></FormField>
                        <FormField label="Motif" required className="span-all"><input className="inp" value={sod.motif} onChange={(event) => setSod({ ...sod, motif: event.target.value })} required /></FormField>
                        <div className="span-all"><Button type="submit" icon={ICON.save} loading={pending}>Enregistrer l’incompatibilité</Button></div>
                    </form>
                </SectionCard>
            </div>

            <SectionCard title="Revue périodique des accès" icon={ICON.roles} subtitle="Chaque compte confirme son habilitation. L’authentification à deux facteurs n’est pas imposée par cette revue.">
                <div className="btn-group">
                    {registre?.peut_ouvrir && <Button icon={ICON.validate} loading={pending} onClick={ouvrirRevue}>Ouvrir une revue</Button>}
                    {registre?.peut_confirmer && <Button variant="secondary" loading={pending} onClick={confirmerRevue}>Confirmer mon accès</Button>}
                </div>
                {registre?.reference ? (
                    <p className="subtle">Revue {registre.reference}, ouverte le {registre.ouverte_le}. {registre.confirmees} confirmé(s), {registre.attendues} en attente.</p>
                ) : (
                    <p className="subtle">Aucune revue ouverte.</p>
                )}
                <DataTable
                    columns={[
                        { key: 'nom', header: 'Compte', render: (row: any) => row.nom },
                        { key: 'role', header: 'Rôle', render: (row: any) => row.role },
                        { key: 'decision', header: 'Décision', render: (row: any) => <Badge tone={row.decision === 'confirme' ? 'success' : 'warning'} size="sm">{row.decision === 'confirme' ? 'Confirmé' : 'En attente'}</Badge> },
                    ]}
                    rows={registre?.lignes ?? []}
                    rowKey={(row: any) => row.id}
                    empty={<EmptyState icon={ICON.roles} title="Aucune ligne de revue" compact />}
                />
            </SectionCard>

            <SectionCard id="interims" title="Intérims" icon={ICON.roles} subtitle="Pendant la période, l’intérimaire tient le rôle du titulaire. La clôture retire ce rôle." flush>
                <DataTable
                    columns={[
                        { key: 'titulaire', header: 'Titulaire', render: (row: any) => row.titulaire },
                        { key: 'interim', header: 'Intérimaire', render: (row: any) => row.interim },
                        { key: 'fonction', header: 'Rôle tenu', className: 'mono', render: (row: any) => row.fonction },
                        { key: 'periode', header: 'Période', className: 'mono', render: (row: any) => `${row.debut} → ${row.fin}` },
                        { key: 'effectif', header: 'En cours', render: (row: any) => <Badge tone={row.effectif ? 'success' : 'neutral'} size="sm">{row.effectif ? 'Oui' : 'Non'}</Badge> },
                        { key: 'cloture', header: '', render: (row: any) => row.statut === 'active' ? <Button size="sm" variant="danger-outline" loading={pending} onClick={() => cloturerInterim(row.id)}>Clôturer</Button> : null },
                    ]}
                    rows={interims}
                    rowKey={(row: any) => row.id}
                    loading={loading}
                    empty={<EmptyState icon={ICON.roles} title="Aucun intérim" compact>Un intérim ouvert apparaîtra ici.</EmptyState>}
                />
            </SectionCard>

            <SectionCard id="delegations" title="Délégations" icon={faPeopleArrows} subtitle="La révocation prend effet tout de suite. Elle ne réécrit pas les décisions déjà signées.">
                <form className="cluster" style={{ padding: '12px 16px 0', alignItems: 'flex-end' }} onSubmit={revoquerDelegation}>
                    <FormField label="Délégation active" style={{ flex: '1 1 240px' }}>
                        <select className="inp" value={revocation.id} onChange={(event) => setRevocation({ ...revocation, id: event.target.value })} required>
                            <option value="">Choisir</option>
                            {delegations.filter((row) => row.statut === 'active').map((row) => <option key={row.id} value={row.id}>{row.delegant} → {row.delegataire}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Motif" style={{ flex: '1 1 220px' }}>
                        <input className="inp" value={revocation.motif} onChange={(event) => setRevocation({ ...revocation, motif: event.target.value })} required />
                    </FormField>
                    <Button type="submit" variant="danger-outline" loading={pending}>Révoquer</Button>
                </form>
                <DataTable
                    columns={[
                        { key: 'delegant', header: 'Délégant', render: (row: any) => row.delegant },
                        { key: 'delegataire', header: 'Délégataire', render: (row: any) => row.delegataire },
                        { key: 'fonction', header: 'Fonction', render: (row: any) => row.fonction },
                        { key: 'periode', header: 'Période', className: 'mono', render: (row: any) => `${row.debut} → ${row.fin}` },
                        { key: 'effective', header: 'Effective', render: (row: any) => <Badge tone={row.effective ? 'success' : 'neutral'} icon={row.effective ? ICON.success : ICON.cancel} size="sm">{row.effective ? 'Oui' : 'Non'}</Badge> },
                    ]}
                    rows={delegations}
                    rowKey={(row: any) => row.id}
                    loading={loading}
                    empty={<EmptyState icon={faPeopleArrows} title="Aucune délégation" compact>Les délégations de fonction enregistrées apparaîtront ici.</EmptyState>}
                />
            </SectionCard>

            {openingInterim && (
                <Modal
                    title="Nouvel intérim"
                    description="L’intérimaire tient le rôle du titulaire jusqu’à la date de fin, ou jusqu’à la clôture."
                    icon={ICON.roles}
                    onClose={() => setOpeningInterim(false)}
                    footer={(
                        <>
                            <Button onClick={() => setOpeningInterim(false)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="interim-admin-form" icon={ICON.save} loading={pending}>Ouvrir l’intérim</Button>
                        </>
                    )}
                >
                    <form id="interim-admin-form" className="form-grid" onSubmit={ouvrirInterim}>
                        <FormField label="Titulaire" required>
                            <select className="inp" value={interimForm.titulaire_id} onChange={setInterim('titulaire_id')} required>
                                <option value="">Choisir</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.nom}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Intérimaire" required>
                            <select className="inp" value={interimForm.interim_id} onChange={setInterim('interim_id')} required>
                                <option value="">Choisir</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.nom}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Début" required><input className="inp" type="date" value={interimForm.starts_on} onChange={setInterim('starts_on')} required /></FormField>
                        <FormField label="Fin" required><input className="inp" type="date" value={interimForm.ends_on} onChange={setInterim('ends_on')} required /></FormField>
                    </form>
                    <ErrorMessage error={error} title="Intérim refusé" />
                </Modal>
            )}

            {creating && (
                <Modal
                    title="Nouvelle délégation"
                    description="Le délégataire exerce la fonction déléguée pendant la période indiquée."
                    icon={faPeopleArrows}
                    onClose={() => setCreating(false)}
                    footer={(
                        <>
                            <Button onClick={() => setCreating(false)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="delegation-admin-form" icon={ICON.save} loading={pending}>Enregistrer</Button>
                        </>
                    )}
                >
                    <form id="delegation-admin-form" className="form-grid" onSubmit={delegate}>
                        <FormField label="Délégant" required>
                            <select className="inp" value={form.delegant_id} onChange={set('delegant_id')} required>
                                <option value="">Choisir</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.nom}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Délégataire" required>
                            <select className="inp" value={form.delegataire_id} onChange={set('delegataire_id')} required>
                                <option value="">Choisir</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.nom}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Fonction déléguée" required className="span-all"><input className="inp" value={form.fonction} onChange={set('fonction')} required /></FormField>
                        <FormField label="Début" required><input className="inp" type="date" value={form.starts_on} onChange={set('starts_on')} required /></FormField>
                        <FormField label="Fin" required><input className="inp" type="date" value={form.ends_on} onChange={set('ends_on')} required /></FormField>
                        <FormField label="Motif" required className="span-all"><input className="inp" value={form.motif} onChange={set('motif')} required /></FormField>
                    </form>
                    <Alert tone="info">Une délégation échue ne produit plus de droit.</Alert>
                    <ErrorMessage error={error} title="Délégation refusée" />
                </Modal>
            )}
        </main>
    );
}
