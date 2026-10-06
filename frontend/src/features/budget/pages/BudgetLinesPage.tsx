import { faArrowRightArrowLeft } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Alert,
    AmountInput,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FilterBar,
    FormField,
    ICON,
    Modal,
    PageHeader,
    Pagination,
    ProgressBar,
    SearchInput,
    SectionCard,
    useToast,
    type Column,
    type PageMeta,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const KINDS = [
    ['gel', 'Gel'],
    ['degel', 'Dégel'],
    ['ouverture', 'Ouverture'],
    ['report', 'Report'],
    ['annulation', 'Annulation'],
    ['virement', 'Virement'],
];

export default function BudgetLinesPage() {
    const toast = useToast();
    const [rows, setRows] = useState<any[]>([]);
    const [options, setOptions] = useState<any[]>([]);
    const [search, setSearch] = useState('');
    const query = useDebouncedValue(search, 300);
    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [loading, setLoading] = useState(false);
    const [modal, setModal] = useState(false);
    const [lineId, setLineId] = useState('');
    const [destinationId, setDestinationId] = useState('');
    const [kind, setKind] = useState('gel');
    const [montant, setMontant] = useState('');
    const [motif, setMotif] = useState('');
    const [acte, setActe] = useState('');
    const [error, setError] = useState('');
    const [loadError, setLoadError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);

    async function load(term = '', current = 1) {
        setLoading(true);
        setLoadError(null);
        try {
            const response = await api.get('/lignes-budgetaires', { params: { q: term, page: current } });
            setRows(response.data.data);
            setMeta(response.data.meta ?? null);
        } catch (caught) {
            setLoadError(errorsOf(caught));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        setPage(1);
    }, [query]);

    useEffect(() => {
        load(query, page);
    }, [query, page]);

    async function openMovement(id = '') {
        setLineId(id ? String(id) : '');
        setError('');
        setModal(true);
        if (options.length > 0) {
            return;
        }
        try {
            const response = await api.get('/lignes-budgetaires', { params: { per_page: 500 } });
            setOptions(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setError('');
        setPending(true);
        try {
            await api.post(`/lignes-budgetaires/${lineId}/mouvements`, {
                kind,
                montant: Number(montant),
                motif,
                acte,
                destination_id: kind === 'virement' ? Number(destinationId) : null,
            });
            setMontant('');
            setMotif('');
            setActe('');
            setModal(false);
            toast.success('Mouvement budgétaire enregistré.');
            setOptions([]);
            await load(query, page);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const amount = (key: string) => (row: any) => fcfa(row.soldes?.[key]);
    const columns: Column<any>[] = [
        { key: 'code', header: 'Code · libellé', render: (row) => <div style={{ minWidth: 220 }}><div className="cell-ref">{row.code}</div><div className="cell-sub">{row.libelle}</div></div> },
        { key: 'initial', header: 'Initial', align: 'right', className: 'mono', render: amount('initial') },
        { key: 'revise', header: 'Révisé', align: 'right', className: 'mono', render: amount('revise') },
        { key: 'gele', header: 'Gelé', align: 'right', className: 'mono', render: amount('gele') },
        { key: 'reserve', header: 'Réservé', align: 'right', className: 'mono', render: amount('reserve') },
        { key: 'engage', header: 'Engagé', align: 'right', className: 'mono', render: amount('engage') },
        { key: 'liquide', header: 'Liquidé', align: 'right', className: 'mono', render: amount('liquide') },
        { key: 'ordonnance', header: 'Ordonnancé', align: 'right', className: 'mono', render: amount('ordonnance') },
        { key: 'paye', header: 'Payé', align: 'right', className: 'mono', render: amount('paye') },
        { key: 'disponible', header: 'Disponible', align: 'right', className: 'cell-amount', render: (row) => <span style={{ color: Number(row.soldes?.disponible) < 0 ? 'var(--danger-fg)' : 'var(--green-700)' }}>{fcfa(row.soldes?.disponible)}</span> },
        { key: 'reste', header: 'Reste à payer', align: 'right', className: 'mono', render: amount('reste_a_payer') },
        {
            key: 'taux',
            header: 'Taux eng.',
            render: (row) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 110 }}>
                    <div style={{ flex: 1 }}><ProgressBar value={row.soldes?.taux_engagement} size="thin" label={`Taux d’engagement ${row.soldes?.taux_engagement ?? 0} %`} /></div>
                    <span className="mono" style={{ fontSize: 'var(--text-xs)' }}>{row.soldes?.taux_engagement ?? 0} %</span>
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            className: 'cell-actions',
            render: (row) => <ActionMenu label={`Actions sur la ligne ${row.code}`} actions={[{ label: 'Enregistrer un mouvement', icon: faArrowRightArrowLeft, onSelect: () => openMovement(row.id) }]} />,
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Lignes et mouvements"
                subtitle="Crédit autorisé, gel et virement. Le disponible intègre ces mouvements."
                actions={<Button variant="primary" icon={faArrowRightArrowLeft} onClick={() => openMovement()}>Enregistrer un mouvement</Button>}
            />

            <SectionCard flush>
                <FilterBar end={<span className="subtle">Montants en FCFA</span>}>
                    <SearchInput value={search} onChange={setSearch} placeholder="Rechercher une ligne par code, libellé ou structure" label="Rechercher une ligne budgétaire" />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={loadError}
                    compact
                    minWidth={1560}
                    empty={<EmptyState icon={ICON.budget} title="Aucune ligne budgétaire">{search ? 'Aucune ligne ne correspond à cette recherche.' : 'Aucune ligne n’est chargée pour l’exercice.'}</EmptyState>}
                />
                <Pagination meta={meta} onPage={setPage} noun="ligne" />
            </SectionCard>

            {modal && (
                <Modal
                    title="Enregistrer un mouvement budgétaire"
                    description="Le mouvement modifie le disponible de la ligne et reste tracé avec son acte."
                    icon={faArrowRightArrowLeft}
                    onClose={() => setModal(false)}
                    footer={(
                        <>
                            <Button onClick={() => setModal(false)}>Annuler</Button>
                            <Button variant="primary" type="submit" form="movement-form" icon={ICON.save} loading={pending}>Enregistrer le mouvement</Button>
                        </>
                    )}
                >
                    <form id="movement-form" className="form-grid" onSubmit={submit}>
                        <FormField label="Ligne d’origine" required className="span-all">
                            <select className="inp" value={lineId} onChange={(event) => setLineId(event.target.value)} required>
                                <option value="">Choisir une ligne</option>
                                {(options.length > 0 ? options : rows).map((row) => <option key={row.id} value={row.id}>{row.code} · disponible {fcfa(row.disponible ?? row.soldes?.disponible)}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Type de mouvement" required>
                            <select className="inp" value={kind} onChange={(event) => setKind(event.target.value)}>
                                {KINDS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Montant" required>
                            <AmountInput value={montant} onChange={setMontant} required />
                        </FormField>
                        {kind === 'virement' && (
                            <FormField label="Ligne de destination" required className="span-all">
                                <select className="inp" value={destinationId} onChange={(event) => setDestinationId(event.target.value)} required>
                                    <option value="">Choisir la ligne bénéficiaire du virement</option>
                                    {(options.length > 0 ? options : rows).filter((row) => String(row.id) !== lineId).map((row) => <option key={row.id} value={row.id}>{row.code}</option>)}
                                </select>
                            </FormField>
                        )}
                        <FormField label="Motif" required className="span-all">
                            <input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} required />
                        </FormField>
                        <FormField label="Acte" required className="span-all" hint="Référence de la décision qui fonde le mouvement.">
                            <input className="inp" value={acte} onChange={(event) => setActe(event.target.value)} required />
                        </FormField>
                    </form>
                    {!loading && rows.length === 0 && <Alert tone="warning">Aucune ligne n’est affichée : modifiez la recherche pour retrouver la ligne concernée.</Alert>}
                    <ErrorMessage error={error} title="Mouvement refusé" />
                </Modal>
            )}
        </main>
    );
}
