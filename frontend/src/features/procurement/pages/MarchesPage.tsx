import { FormEvent, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FilterBar,
    FilterSelect,
    FormField,
    ICON,
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
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';
import MarcheForm, { MARCHE_VIDE, PROCEDURES, type MarcheSaisie } from '../components/MarcheForm';

type Marche = {
    id: number;
    reference: string;
    objet: string;
    montant: number;
    procedure: string;
    statut: string;
    statut_libelle: string;
    titulaire: string | null;
    engagement: string | null;
    annee: number | null;
};

export default function MarchesPage() {
    const toast = useToast();
    const { confirm } = useDialogs();
    const navigate = useNavigate();
    const [portrait, setPortrait] = useState<any>(null);
    const [error, setError] = useState('');
    const [formError, setFormError] = useState('');
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const terme = useDebouncedValue(search, 300);
    const [statut, setStatut] = useState('');
    const [procedure, setProcedure] = useState('');
    const [page, setPage] = useState(1);
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState<MarcheSaisie & { exercice_id: string; engagement_id: string }>({ ...MARCHE_VIDE, exercice_id: '', engagement_id: '' });
    const [pending, setPending] = useState(false);

    function load() {
        setLoading(true);
        api.get('/marches', { params: { q: terme || undefined, statut: statut || undefined, procedure: procedure || undefined, page } })
            .then((response) => { setPortrait(response.data); setError(''); })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => { setPage(1); }, [terme, statut, procedure]);
    useEffect(() => { load(); }, [terme, statut, procedure, page]);

    function ouvrirCreation() {
        setForm({ ...MARCHE_VIDE, exercice_id: String(portrait?.exercices?.[0]?.id ?? ''), engagement_id: '' });
        setFormError('');
        setModal(true);
    }

    async function creer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setFormError('');
        try {
            const created = await api.post('/marches', {
                exercice_id: Number(form.exercice_id),
                objet: form.objet,
                montant: Number(form.montant),
                procedure: form.procedure,
                tiers_id: form.tiers_id ? Number(form.tiers_id) : null,
            });
            const id = created.data.data.id;
            if (form.engagement_id) {
                await api.post(`/marches/${id}/rattacher`, { engagement_id: Number(form.engagement_id) });
            }
            setModal(false);
            toast.success(`Marché ${created.data.data.reference} enregistré.`);
            navigate(`/marches/${id}`);
        } catch (caught) {
            setFormError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    async function supprimer(row: Marche) {
        const ok = await confirm({
            title: 'Supprimer ce projet de marché ?',
            description: `${row.reference} · ${row.objet}. La suppression est tracée dans le journal d’audit.`,
            confirmLabel: 'Supprimer',
            tone: 'danger',
        });
        if (!ok) return;
        try {
            await api.delete(`/marches/${row.id}`);
            toast.success(`Marché ${row.reference} supprimé.`);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    const editeur = Boolean(portrait?.peut_creer);
    const statuts: Record<string, string> = portrait?.statuts ?? {};
    const filtres = Boolean(terme || statut || procedure);

    const columns: Column<Marche>[] = [
        { key: 'reference', header: 'Référence', render: (row) => <span className="cell-ref">{row.reference}</span> },
        { key: 'objet', header: 'Objet', render: (row) => <span>{row.objet}<span className="cell-sub">{PROCEDURES[row.procedure] ?? row.procedure}{row.annee ? ` · exercice ${row.annee}` : ''}</span></span> },
        { key: 'titulaire', header: 'Titulaire', render: (row) => row.titulaire ?? <span className="subtle">Non désigné</span> },
        { key: 'engagement', header: 'Engagement', render: (row) => (row.engagement ? <span className="mono" style={{ whiteSpace: 'nowrap' }}>{row.engagement}</span> : <span className="subtle">Non rattaché</span>) },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} size="sm" /> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            align: 'right',
            width: 56,
            render: (row) => (
                <ActionMenu
                    label={`Actions sur ${row.reference}`}
                    actions={[
                        { label: 'Voir la fiche', icon: ICON.open, onSelect: () => navigate(`/marches/${row.id}`) },
                        { label: 'Modifier', icon: ICON.edit, onSelect: () => navigate(`/marches/${row.id}?modifier=1`), hidden: !editeur || row.statut !== 'projet' },
                        { label: 'Changer le statut', icon: ICON.check, onSelect: () => navigate(`/marches/${row.id}`), hidden: !editeur || row.statut === 'clos' || row.statut === 'resilie' },
                        { label: 'Supprimer', icon: ICON.delete, onSelect: () => supprimer(row), hidden: !editeur || row.statut !== 'projet' || Boolean(row.engagement), danger: true },
                    ]}
                />
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Référentiels"
                title="Marchés et contrats"
                subtitle="Registre des marchés : un marché se rattache à un engagement du même exercice et apparaît sur son ordonnancement."
                actions={portrait?.peut_creer && <Button variant="primary" icon={ICON.create} onClick={ouvrirCreation}>Nouveau marché</Button>}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <FilterBar onReset={filtres ? () => { setSearch(''); setStatut(''); setProcedure(''); } : null}>
                    <SearchInput value={search} onChange={setSearch} placeholder="Référence, objet ou titulaire" />
                    <FilterSelect label="Statut" value={statut} onChange={setStatut} options={Object.entries(statuts).map(([value, label]) => ({ value, label }))} />
                    <FilterSelect label="Procédure" value={procedure} onChange={setProcedure} allLabel="Toutes" options={Object.entries(PROCEDURES).map(([value, label]) => ({ value, label }))} />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={portrait?.data ?? []}
                    rowKey={(row) => row.id}
                    loading={loading}
                    onRowClick={(row) => navigate(`/marches/${row.id}`)}
                    rowLabel={(row) => `Ouvrir le marché ${row.reference}`}
                    empty={(
                        <EmptyState icon={ICON.commitment} title={filtres ? 'Aucun marché trouvé' : 'Aucun marché'}>
                            {filtres ? 'Modifiez la recherche ou les filtres.' : 'Les marchés créés ici apparaissent sur l’ordonnancement de l’engagement rattaché.'}
                        </EmptyState>
                    )}
                />
                <Pagination meta={portrait?.meta as PageMeta | undefined} onPage={setPage} noun="marché" />
            </SectionCard>

            {modal && (
                <Modal
                    title="Nouveau marché"
                    icon={ICON.commitment}
                    onClose={() => setModal(false)}
                    footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="marche-form" icon={ICON.save} loading={pending}>Enregistrer</Button></>}
                >
                    <form id="marche-form" className="form-grid" onSubmit={creer}>
                        <FormField label="Exercice" required>
                            <select className="inp" required value={form.exercice_id} onChange={(event) => setForm({ ...form, exercice_id: event.target.value })}>
                                <option value="">Choisir</option>
                                {(portrait?.exercices ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.annee}</option>)}
                            </select>
                        </FormField>
                        <MarcheForm value={form} onChange={(valeur) => setForm({ ...form, ...valeur })} tiers={portrait?.tiers ?? []} />
                        <FormField label="Engagement à rattacher" className="span-all" hint="Facultatif : le rattachement notifie le marché.">
                            <select className="inp" value={form.engagement_id} onChange={(event) => setForm({ ...form, engagement_id: event.target.value })}>
                                <option value="">Plus tard</option>
                                {(portrait?.engagements ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.reference}</option>)}
                            </select>
                        </FormField>
                    </form>
                    <ErrorMessage error={formError} title="Enregistrement refusé" />
                </Modal>
            )}
        </main>
    );
}
