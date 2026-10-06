import { faBan, faBuildingColumns, faLock, faPause, faPlay, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useState } from 'react';
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
    FilterSelect,
    FormField,
    ICON,
    KeyValueList,
    Modal,
    PageHeader,
    Pagination,
    SearchInput,
    SectionCard,
    StatusBadge,
    useDialogs,
    useToast,
    type Column,
    type PageMeta,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const EMPTY_TIERS = { type: 'fournisseur', raison_sociale: '', nif: '', rccm: '', pays: '', adresse: '', email: '', telephone: '' };

const TYPES: Record<string, string> = {
    fournisseur: 'Fournisseur',
    consultant: 'Consultant',
    beneficiaire: 'Bénéficiaire',
    organisme: 'Organisme',
};

const STATUTS_TIERS: Record<string, string> = {
    actif: 'Actif',
    suspendu: 'Suspendu',
    bloque: 'Bloqué',
    archive: 'Archivé',
};
const EMPTY_ACCOUNT = { banque: '', agence: '', numero: '', titulaire: '', justificatif: '' };

const STATUTS: Record<string, string> = {
    en_attente: 'En attente de validation',
    valide: 'Validé',
    rejete: 'Rejeté',
    desactive: 'Désactivé',
};

const TIERS_STATUTS: Record<string, { label: string; icon: any }> = {
    actif: { label: 'Activer', icon: faPlay },
    suspendu: { label: 'Suspendre', icon: faPause },
    bloque: { label: 'Bloquer', icon: faLock },
};

export default function TiersPage() {
    const toast = useToast();
    const { confirm, prompt } = useDialogs();
    const [rows, setRows] = useState<any[]>([]);
    const [rights, setRights] = useState<any>({});
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const query = useDebouncedValue(search, 300);
    const [selected, setSelected] = useState<any>(null);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(false);
    const [cible, setCible] = useState<any>(null);
    const [statut, setStatut] = useState('');
    const [type, setType] = useState('');
    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [tiersForm, setTiersForm] = useState(EMPTY_TIERS);
    const [accountForm, setAccountForm] = useState(EMPTY_ACCOUNT);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [fusion, setFusion] = useState(false);
    const [cibleId, setCibleId] = useState('');

    function load(term = query) {
        setLoading(true);
        setLoadError(null);
        api.get('/tiers', { params: { q: term || undefined, statut: statut || undefined, type: type || undefined, page } })
            .then((response) => {
                setRows(response.data.data);
                setRights(response.data.droits);
                setMeta(response.data.meta);
            })
            .catch((caught) => setLoadError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => {
        setPage(1);
    }, [query, statut, type]);

    useEffect(() => {
        load(query);
    }, [query, statut, type, page]);

    async function run(key: string, request: () => Promise<any>, after?: () => void, success?: string) {
        setError('');
        setPending(key);
        try {
            const response = await request();
            if (response?.data?.data?.comptes) {
                // Met à jour la fiche ouverte, sans en ouvrir une depuis la liste.
                setSelected((courante: any) => (courante && courante.id === response.data.data.id ? response.data.data : courante));
            }
            after?.();
            if (success) toast.success(success);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    function open(id: number) {
        setError('');
        setFusion(false);
        setCibleId('');
        setAccountForm(EMPTY_ACCOUNT);
        api.get(`/tiers/${id}`).then((response) => setSelected(response.data.data)).catch((caught) => toast.error(errorsOf(caught)));
    }

    async function ajouterConformite() {
        if (!selected) return;
        const values = await prompt({
            title: 'Pièce de conformité',
            confirmLabel: 'Enregistrer',
            icon: ICON.document,
            fields: [
                { name: 'kind', label: 'Nature', required: true },
                { name: 'reference', label: 'Référence', required: true },
                { name: 'expires_on', label: 'Expire le', type: 'date', required: true },
            ],
        });
        if (!values) return;
        run('conformite', () => api.post(`/tiers/${selected.id}/conformite`, values), undefined, 'Pièce de conformité enregistrée.');
    }

    async function ajouterIncident() {
        if (!selected) return;
        const values = await prompt({
            title: 'Incident',
            confirmLabel: 'Consigner',
            icon: ICON.warning,
            fields: [
                { name: 'occurred_on', label: 'Date', type: 'date', required: true },
                { name: 'nature', label: 'Nature', required: true },
                { name: 'suite', label: 'Suite donnée', type: 'textarea', required: true },
            ],
        });
        if (!values) return;
        run('incident', () => api.post(`/tiers/${selected.id}/incidents`, values), undefined, 'Incident consigné.');
    }

    function fusionner(event: FormEvent) {
        event.preventDefault();
        if (!selected || !cibleId) return;
        run('fusion', () => api.post(`/tiers/${selected.id}/fusionner`, { cible_id: Number(cibleId) }), () => { setFusion(false); setCibleId(''); }, 'Tiers fusionné.');
    }

    function createTiers(event: FormEvent) {
        event.preventDefault();
        if (editing && cible) {
            run('create', () => api.put(`/tiers/${cible.id}`, tiersForm), () => { setTiersForm(EMPTY_TIERS); setCreating(false); setEditing(false); }, 'Fiche du tiers modifiée.');
            return;
        }
        run('create', () => api.post('/tiers', tiersForm), () => { setTiersForm(EMPTY_TIERS); setCreating(false); }, 'Tiers créé.');
    }

    function ouvrirCreation() {
        setError('');
        setEditing(false);
        setTiersForm(EMPTY_TIERS);
        setCreating(true);
    }

    function ouvrirEdition(fiche: any = selected) {
        if (!fiche) return;
        setError('');
        setCible(fiche);
        setEditing(true);
        setTiersForm({
            type: fiche.type ?? 'fournisseur',
            raison_sociale: fiche.raison_sociale ?? '',
            nif: fiche.nif ?? '',
            rccm: fiche.rccm ?? '',
            pays: fiche.pays ?? '',
            adresse: fiche.adresse ?? '',
            email: fiche.email ?? '',
            telephone: fiche.telephone ?? '',
        });
        setCreating(true);
    }

    /** Modification lancée depuis la liste : la fiche complète est chargée d’abord. */
    function modifierDepuisListe(id: number) {
        api.get(`/tiers/${id}`)
            .then((response) => ouvrirEdition(response.data.data))
            .catch((caught) => toast.error(errorsOf(caught)));
    }

    async function supprimerTiers(fiche: any = selected) {
        if (!fiche) return;
        const ok = await confirm({
            title: 'Supprimer ce tiers ?',
            description: `${fiche.code} · ${fiche.raison_sociale}. Seule une fiche jamais utilisée peut être supprimée ; la suppression est tracée.`,
            confirmLabel: 'Supprimer',
            tone: 'danger',
        });
        if (!ok) return;
        setPending('supprimer');
        setError('');
        try {
            await api.delete(`/tiers/${fiche.id}`);
            toast.success(`Tiers ${fiche.code} supprimé.`);
            if (selected?.id === fiche.id) setSelected(null);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    function addAccount(event: FormEvent) {
        event.preventDefault();
        run('account', () => api.post(`/tiers/${selected.id}/comptes`, accountForm), () => setAccountForm(EMPTY_ACCOUNT), 'Compte ajouté : il devra être validé par un second acteur.');
    }

    async function accountAction(account: any, action: 'valider' | 'rejeter' | 'desactiver') {
        if (action === 'valider') {
            run(`acc-${account.id}`, () => api.post(`/tiers/comptes/${account.id}/valider`, {}), undefined, 'Compte bancaire validé.');
            return;
        }
        const values = await prompt({
            title: action === 'rejeter' ? 'Rejeter le compte bancaire' : 'Désactiver le compte bancaire',
            description: `${account.banque} · ${account.numero}`,
            confirmLabel: action === 'rejeter' ? 'Rejeter' : 'Désactiver',
            tone: 'danger',
            fields: [{ name: 'motif', label: 'Motif', required: true }],
        });
        if (values) {
            run(`acc-${account.id}`, () => api.post(`/tiers/comptes/${account.id}/${action}`, { motif: values.motif }), undefined, action === 'rejeter' ? 'Compte rejeté.' : 'Compte désactivé.');
        }
    }

    async function changeStatus(statut: string) {
        const values = await prompt({
            title: `${TIERS_STATUTS[statut]?.label ?? 'Changer le statut de'} le tiers`,
            description: `${selected.code} · ${selected.raison_sociale}`,
            confirmLabel: TIERS_STATUTS[statut]?.label ?? 'Confirmer',
            tone: statut === 'actif' ? 'default' : 'warning',
            fields: [{ name: 'motif', label: 'Motif', required: true }],
        });
        if (values) {
            run('statut', () => api.post(`/tiers/${selected.id}/statut`, { statut, motif: values.motif }), undefined, 'Statut du tiers modifié.');
        }
    }

    const columns: Column<any>[] = [
        { key: 'code', header: 'Code', render: (row) => <span className="cell-ref">{row.code}</span> },
        { key: 'raison', header: 'Raison sociale', render: (row) => <span><span className="strong">{row.raison_sociale}</span><span className="cell-sub">{TYPES[row.type] ?? row.type}{row.pays ? ` · ${row.pays}` : ''}</span></span> },
        { key: 'nif', header: 'NIF', className: 'mono', render: (row) => row.nif || '—' },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={STATUTS_TIERS[row.statut] ?? row.statut} size="sm" /> },
        { key: 'valides', header: 'Comptes validés', align: 'right', className: 'num', render: (row) => row.comptes_valides },
        { key: 'attente', header: 'En attente', align: 'right', render: (row) => row.comptes_en_attente > 0 ? <Badge tone="warning" size="sm">{row.comptes_en_attente}</Badge> : <span className="subtle">0</span> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            align: 'right',
            width: 56,
            render: (row) => (
                <ActionMenu
                    label={`Actions sur ${row.code}`}
                    actions={[
                        { label: 'Voir la fiche', icon: ICON.open, onSelect: () => open(row.id) },
                        { label: 'Modifier', icon: ICON.edit, onSelect: () => modifierDepuisListe(row.id), hidden: !rights.modifier || row.statut === 'archive' },
                        { label: 'Supprimer', icon: ICON.delete, onSelect: () => supprimerTiers(row), hidden: !rights.supprimer, danger: true },
                    ]}
                />
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Référentiels"
                title="Tiers et comptes bancaires"
                subtitle="Fournisseurs et bénéficiaires. Un compte bancaire n’est utilisable qu’après validation par un second acteur."
                actions={rights.creer && <Button variant="primary" icon={ICON.create} onClick={ouvrirCreation}>Nouveau tiers</Button>}
            />

            <SectionCard flush>
                <FilterBar onReset={search || statut || type ? () => { setSearch(''); setStatut(''); setType(''); } : null}>
                    <SearchInput value={search} onChange={setSearch} placeholder="Rechercher : nom, code, NIF" />
                    <FilterSelect label="Type" value={type} onChange={setType} options={Object.entries(TYPES).map(([value, label]) => ({ value, label }))} />
                    <FilterSelect label="Statut" value={statut} onChange={setStatut} options={Object.entries(STATUTS_TIERS).map(([value, label]) => ({ value, label }))} />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={loadError}
                    onRowClick={(row) => open(row.id)}
                    rowLabel={(row) => `Ouvrir la fiche du tiers ${row.raison_sociale}`}
                    empty={(
                        <EmptyState icon={ICON.suppliers} title={search ? 'Aucun tiers trouvé' : 'Aucun tiers enregistré'} action={rights.creer && !search ? <Button variant="primary" icon={ICON.create} onClick={ouvrirCreation}>Créer un tiers</Button> : undefined}>
                            {search ? 'Modifiez la recherche.' : 'Les fournisseurs et bénéficiaires enregistrés apparaîtront ici.'}
                        </EmptyState>
                    )}
                />
                <Pagination meta={meta} onPage={setPage} noun="fiche" />
            </SectionCard>

            {creating && (
                <Modal
                    title={editing ? `Modifier ${cible?.code ?? 'le tiers'}` : 'Nouveau tiers'}
                    icon={editing ? ICON.edit : ICON.suppliers}
                    onClose={() => { setCreating(false); setEditing(false); }}
                    footer={(
                        <>
                            <Button onClick={() => { setCreating(false); setEditing(false); }}>Annuler</Button>
                            <Button variant="primary" type="submit" form="tiers-form" icon={ICON.save} disabled={!tiersForm.raison_sociale} loading={pending === 'create'}>{editing ? 'Enregistrer' : 'Créer le tiers'}</Button>
                        </>
                    )}
                >
                    <form id="tiers-form" className="form-grid" onSubmit={createTiers}>
                        <FormField label="Type" required>
                            <select className="inp" value={tiersForm.type} onChange={(event) => setTiersForm({ ...tiersForm, type: event.target.value })}>
                                {Object.entries(TYPES).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Pays"><input className="inp" value={tiersForm.pays} onChange={(event) => setTiersForm({ ...tiersForm, pays: event.target.value })} /></FormField>
                        <FormField label="Raison sociale" required className="span-all"><input className="inp" value={tiersForm.raison_sociale} onChange={(event) => setTiersForm({ ...tiersForm, raison_sociale: event.target.value })} /></FormField>
                        <FormField label="NIF"><input className="inp mono" value={tiersForm.nif} onChange={(event) => setTiersForm({ ...tiersForm, nif: event.target.value })} /></FormField>
                        <FormField label="RCCM"><input className="inp mono" value={tiersForm.rccm} onChange={(event) => setTiersForm({ ...tiersForm, rccm: event.target.value })} /></FormField>
                        <FormField label="Adresse" className="span-all"><input className="inp" value={tiersForm.adresse} onChange={(event) => setTiersForm({ ...tiersForm, adresse: event.target.value })} /></FormField>
                        <FormField label="Courriel"><input className="inp" type="email" value={tiersForm.email} onChange={(event) => setTiersForm({ ...tiersForm, email: event.target.value })} /></FormField>
                        <FormField label="Téléphone"><input className="inp" type="tel" value={tiersForm.telephone} onChange={(event) => setTiersForm({ ...tiersForm, telephone: event.target.value })} /></FormField>
                    </form>
                    <ErrorMessage error={error} title={editing ? 'Modification refusée' : 'Création refusée'} />
                </Modal>
            )}

            {selected && (
                <Drawer
                    width={680}
                    title={selected.raison_sociale}
                    description={<><span className="mono">{selected.code}</span> · <StatusBadge statut={selected.statut} libelle={STATUTS_TIERS[selected.statut] ?? selected.statut} size="sm" /></>}
                    icon={ICON.suppliers}
                    onClose={() => setSelected(null)}
                    footer={<Button onClick={() => setSelected(null)}>Fermer</Button>}
                >
                    {!creating && <ErrorMessage error={error} onClose={() => setError('')} />}
                    <SectionCard
                        title="Identité"
                        icon={ICON.suppliers}
                        actions={(
                            <div className="btn-group">
                                {selected.droits?.modifier && selected.statut !== 'archive' && <Button size="sm" icon={ICON.edit} onClick={() => ouvrirEdition()}>Modifier</Button>}
                                {selected.droits?.supprimer && <Button size="sm" variant="danger-outline" icon={ICON.delete} loading={pending === 'supprimer'} onClick={() => supprimerTiers()}>Supprimer</Button>}
                            </div>
                        )}
                    >
                        <KeyValueList items={[
                            { label: 'Type', value: TYPES[selected.type] ?? selected.type },
                            { label: 'NIF', value: selected.nif || '—', mono: true },
                            { label: 'RCCM', value: selected.rccm || '—', mono: true },
                            { label: 'Pays', value: selected.pays || '—' },
                            { label: 'Adresse', value: selected.adresse || '—' },
                            { label: 'Courriel', value: selected.email || '—' },
                            { label: 'Téléphone', value: selected.telephone || '—' },
                            { label: 'Motif du statut', value: selected.motif_statut, hidden: !selected.motif_statut },
                        ]} />
                    </SectionCard>
                    {selected.droits?.changer_statut && (
                        <SectionCard title="Statut du tiers" icon={ICON.security}>
                            <div className="btn-group">
                                {['actif', 'suspendu', 'bloque'].filter((statut) => statut !== selected.statut).map((statut) => (
                                    <Button key={statut} size="sm" variant={statut === 'actif' ? 'secondary' : 'warning'} icon={TIERS_STATUTS[statut].icon} loading={pending === 'statut'} onClick={() => changeStatus(statut)}>
                                        {TIERS_STATUTS[statut].label}
                                    </Button>
                                ))}
                            </div>
                            <span className="subtle">Tout changement de statut exige un motif et reste tracé.</span>
                        </SectionCard>
                    )}

                    <SectionCard
                        title="Conformité et incidents"
                        icon={ICON.document}
                        actions={(
                            <div className="btn-group">
                                {selected.droits?.conformite && <Button size="sm" icon={ICON.create} loading={pending === 'conformite'} onClick={ajouterConformite}>Pièce</Button>}
                                {selected.droits?.incident && <Button size="sm" icon={ICON.warning} loading={pending === 'incident'} onClick={ajouterIncident}>Incident</Button>}
                                {selected.droits?.fusionner && selected.statut === 'actif' && <Button size="sm" variant="danger-outline" loading={pending === 'fusion'} onClick={() => setFusion((value) => !value)} aria-expanded={fusion}>Fusionner</Button>}
                            </div>
                        )}
                    >
                        {(selected.conformites ?? []).length === 0 && (selected.incidents ?? []).length === 0 && <p className="subtle">Aucune pièce de conformité ni incident.</p>}
                        {(selected.conformites ?? []).map((row: any) => (
                            <p key={`c-${row.id}`} className="cell-sub">
                                {row.kind} · <span className="mono">{row.reference}</span> · expire le {row.expires_on}
                                {row.expiree && <> · <Badge tone="danger" size="sm">Expirée</Badge></>}
                            </p>
                        ))}
                        {(selected.incidents ?? []).map((row: any) => <p key={`i-${row.id}`} className="cell-sub">{row.occurred_on} · {row.nature} — {row.suite}</p>)}
                        {fusion && (
                            <form className="stack" onSubmit={fusionner}>
                                <Alert tone="warning">Ce dossier est archivé. Les comptes, les pièces et les incidents sont rattachés au tiers conservé.</Alert>
                                <FormField label="Tiers conservé" required>
                                    <select className="inp" value={cibleId} onChange={(event) => setCibleId(event.target.value)} required>
                                        <option value="">Choisir</option>
                                        {(selected.cibles ?? []).map((cible: any) => <option key={cible.id} value={cible.id}>{cible.code} · {cible.raison_sociale}</option>)}
                                    </select>
                                </FormField>
                                <div className="form-actions"><Button variant="danger" type="submit" icon={ICON.suppliers} loading={pending === 'fusion'} disabled={!cibleId}>Fusionner</Button></div>
                            </form>
                        )}
                    </SectionCard>

                    <SectionCard title="Comptes bancaires" icon={faBuildingColumns} flush subtitle={`${selected.comptes.length} compte(s)`}>
                        <DataTable
                            columns={[
                                { key: 'banque', header: 'Banque', render: (account: any) => <div><div style={{ fontWeight: 500 }}>{account.banque}</div>{account.agence && <div className="cell-sub">{account.agence}</div>}</div> },
                                { key: 'numero', header: 'Compte · titulaire', render: (account: any) => <div><div className="mono">{account.numero}</div><div className="cell-sub">{account.titulaire}</div></div> },
                                {
                                    key: 'statut',
                                    header: 'Statut',
                                    render: (account: any) => (
                                        <div className="stack-sm" style={{ gap: 4, alignItems: 'flex-start' }}>
                                            <StatusBadge statut={account.statut} libelle={STATUTS[account.statut] ?? account.statut} size="sm" />
                                            {account.vigilance && <Badge tone="orange" icon={faTriangleExclamation} size="sm">Vigilance</Badge>}
                                            {account.motif && <span className="cell-sub">{account.motif}</span>}
                                        </div>
                                    ),
                                },
                                { key: 'acteurs', header: 'Saisi / validé par', render: (account: any) => <span className="cell-sub">{account.saisi_par || '—'} / {account.valide_par || '—'}</span> },
                                {
                                    key: 'actions',
                                    header: 'Actions',
                                    srHeader: true,
                                    className: 'cell-actions',
                                    render: (account: any) => (
                                        <div className="btn-group" style={{ justifyContent: 'flex-end', flexWrap: 'nowrap' }}>
                                            {account.actions.valider && <Button size="sm" variant="primary" icon={ICON.validate} loading={pending === `acc-${account.id}`} onClick={() => accountAction(account, 'valider')}>Valider</Button>}
                                            <ActionMenu actions={[
                                                { label: 'Rejeter le compte', icon: ICON.reject, onSelect: () => accountAction(account, 'rejeter'), hidden: !account.actions.rejeter, danger: true },
                                                { label: 'Désactiver le compte', icon: faBan, onSelect: () => accountAction(account, 'desactiver'), hidden: !account.actions.desactiver, danger: true },
                                            ]} />
                                        </div>
                                    ),
                                },
                            ]}
                            rows={selected.comptes}
                            rowKey={(account: any) => account.id}
                            compact
                            empty={<EmptyState icon={faBuildingColumns} title="Aucun compte bancaire" compact>Ajoutez un compte pour permettre les virements.</EmptyState>}
                        />
                    </SectionCard>

                    {selected.droits?.ajouter_compte && (
                        <SectionCard title="Ajouter un compte bancaire" icon={ICON.create}>
                            <form className="form-grid" onSubmit={addAccount}>
                                <FormField label="Banque" required><input className="inp" value={accountForm.banque} onChange={(event) => setAccountForm({ ...accountForm, banque: event.target.value })} /></FormField>
                                <FormField label="Agence"><input className="inp" value={accountForm.agence} onChange={(event) => setAccountForm({ ...accountForm, agence: event.target.value })} /></FormField>
                                <FormField label="Numéro / IBAN" required><input className="inp mono" value={accountForm.numero} onChange={(event) => setAccountForm({ ...accountForm, numero: event.target.value })} /></FormField>
                                <FormField label="Titulaire" required><input className="inp" value={accountForm.titulaire} onChange={(event) => setAccountForm({ ...accountForm, titulaire: event.target.value })} /></FormField>
                                <FormField label="Justificatif" className="span-all" hint="RIB, attestation bancaire…"><input className="inp" value={accountForm.justificatif} onChange={(event) => setAccountForm({ ...accountForm, justificatif: event.target.value })} /></FormField>
                                <div className="form-actions span-all">
                                    <Button variant="primary" type="submit" icon={ICON.create} disabled={!accountForm.banque || !accountForm.numero || !accountForm.titulaire} loading={pending === 'account'}>Ajouter le compte</Button>
                                </div>
                            </form>
                            <Alert tone="info">Le compte sera utilisable après validation par un second acteur.</Alert>
                        </SectionCard>
                    )}
                </Drawer>
            )}
        </main>
    );
}
