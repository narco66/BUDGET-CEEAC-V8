import { faClock } from '@fortawesome/free-solid-svg-icons';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    ChainTrail,
    DataTable,
    EmptyState,
    FilterBar,
    ICON,
    OpenCell,
    PageHeader,
    Pagination,
    SearchInput,
    SectionCard,
    StatCard,
    StatStrip,
    StatusBadge,
    StripCell,
    Tabs,
    useToast,
    type Column,
} from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const FILTERS = [
    ['', 'Toutes'],
    ['generee', 'Générées'],
    ['en_preparation', 'En préparation'],
    ['en_controle', 'En contrôle'],
    ['complement', 'Complément'],
    ['transformee_ordonnancement', 'Transformées en ORD'],
];

export default function LiqList() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<any[]>([]);
    const [board, setBoard] = useState<any>(null);
    const [meta, setMeta] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [exporting, setExporting] = useState(false);
    const toast = useToast();
    const [filters, setFilters] = useState({ q: '', statut: '', page: 1 });
    const query = useDebouncedValue(filters, 250);

    useEffect(() => {
        const params: Record<string, string | number> = { ...query };
        if (!params.statut) {
            delete params.statut;
        }
        setLoading(true);
        setError(null);
        api.get('/liquidations', { params })
            .then((response) => {
                setRows(response.data.data);
                setBoard(response.data.tableau_de_bord);
                setMeta(response.data.meta);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }, [query]);

    function setFilter(partial) {
        setFilters((current) => ({ ...current, page: 1, ...partial }));
    }

    const counts: Record<string, number | undefined> = board ? {
        generee: board.generees,
        en_preparation: board.preparation,
        en_controle: board.controle,
        complement: board.complements,
        transformee_ordonnancement: board.transformees,
    } : {};

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'Référence · engagement',
            render: (row) => (
                <div>
                    <Link className="cell-ref" to={`/liquidations/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.reference}</Link>
                    <div className="cell-sub mono" style={{ color: 'var(--green-700)' }}>{row.engagement}</div>
                </div>
            ),
        },
        { key: 'objet', header: 'Objet · fournisseur', render: (row) => <div style={{ minWidth: 200 }}><div style={{ fontWeight: 500 }}>{row.objet}</div><div className="cell-sub">{row.nature_libelle} · {row.fournisseur}</div></div> },
        {
            key: 'cumul',
            header: 'Liquidé / engagé',
            render: (row) => (
                <div className="mono" style={{ fontSize: 'var(--text-sm)', whiteSpace: 'nowrap' }}>
                    {fcfa(row.cumul_liquide)} <span className="subtle">/ {fcfa(row.montant_engage)}</span>
                </div>
            ),
        },
        { key: 'net', header: 'Présente LIQ (net)', align: 'right', className: 'cell-amount', render: (row) => row.montant_net ? fcfa(row.montant_net) : <span className="subtle">—</span> },
        { key: 'service', header: 'Service fait', render: (row) => row.service_fait },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'etape', header: 'Étape · acteur attendu', render: (row) => <div><div>{row.acteur_attendu}</div><div className="cell-sub">{row.derniere_action}</div></div> },
        { key: 'delai', header: 'Délai', render: (row) => row.en_retard ? <Badge tone="danger" icon={faClock} size="sm">En retard</Badge> : <span className="mono subtle">{row.echeance || '—'}</span> },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    const filtered = Boolean(filters.q || filters.statut);

    return (
        <main className="app-content">
            <PageHeader
                title="Liquidations"
                subtitle="Constat du service fait, facture et calcul du net à payer, avant visa du Contrôleur Financier."
                meta={<ChainTrail current="LIQ" />}
                actions={<Button icon={ICON.export} loading={exporting} onClick={async () => {
                    setExporting(true);
                    try {
                        await telecharger('/liquidations/export', 'liquidations.xlsx');
                    } catch (caught) {
                        toast.error(errorsOf(caught));
                    } finally {
                        setExporting(false);
                    }
                }}>Exporter</Button>}
            />

            <Alert tone="info" title="Engagement et liquidation">
                L’engagement est ce que la CEEAC s’est engagée à payer. La liquidation est ce qu’elle reconnaît devoir après service fait. Chaque dossier naît au visa de l’engagement.
            </Alert>

            {board && (
                <>
                    <div className="grid-kpi">
                        <StatCard dark label="Montant liquidé · 2026" value={fcfa(board.montant)} unit="FCFA" hint={`${board.total} liquidations`} icon={ICON.settlement} active={!filters.statut} onClick={() => setFilter({ statut: '' })} />
                        <StatCard label="Restant à liquider" value={fcfa(board.restant)} unit="FCFA" hint="sur les engagements déjà visés" icon={ICON.pending} tone="orange" />
                        <StatCard label="En contrôle" value={board.controle} icon={ICON.visa} tone="info" active={filters.statut === 'en_controle'} onClick={() => setFilter({ statut: 'en_controle' })} />
                        <StatCard label="En retard" value={board.en_retard} icon={faClock} tone={board.en_retard > 0 ? 'danger' : 'neutral'} />
                    </div>
                    <StatStrip label="Répartition par statut">
                        <StripCell label="Générées" value={board.generees} color="#0369A1" onClick={() => setFilter({ statut: 'generee' })} active={filters.statut === 'generee'} />
                        <StripCell label="En préparation" value={board.preparation} color="#0EA5E9" onClick={() => setFilter({ statut: 'en_preparation' })} active={filters.statut === 'en_preparation'} />
                        <StripCell label="Retours · compléments" value={board.complements} color="var(--orange-solid)" onClick={() => setFilter({ statut: 'complement' })} active={filters.statut === 'complement'} />
                        <StripCell label="Transformées en ORD" value={board.transformees} color="#4F46E5" onClick={() => setFilter({ statut: 'transformee_ordonnancement' })} active={filters.statut === 'transformee_ordonnancement'} />
                    </StatStrip>
                    {board.taches?.length > 0 && (
                        <SectionCard title="Mes tâches · Liquidations" icon={ICON.tasks} tone="warning" subtitle={`${board.taches.length} dossier(s) attendent votre action`}>
                            <ul className="list-rows">
                                {board.taches.map((task) => (
                                    <li key={task.id}>
                                        <Link to={`/liquidations/${task.id}`} className="list-row">
                                            <span className="mono strong" style={{ width: 150, flexShrink: 0 }}>{task.reference}</span>
                                            <span className="list-row-main"><span className="list-row-title">{task.objet}</span><span className="list-row-sub">{task.fournisseur}</span></span>
                                            <span className="mono" style={{ whiteSpace: 'nowrap' }}>{fcfa(task.montant_net)}</span>
                                            <Badge tone="warning" size="sm">{task.action}</Badge>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}
                </>
            )}

            <SectionCard flush>
                <Tabs label="Statut des liquidations" inCard value={filters.statut} onChange={(statut) => setFilter({ statut })} items={FILTERS.map(([value, label]) => ({ value, label, count: counts[value] }))} />
                <FilterBar onReset={filtered ? () => setFilters({ q: '', statut: '', page: 1 }) : null}>
                    <SearchInput value={filters.q} onChange={(q) => setFilter({ q })} placeholder="Référence, engagement, objet, fournisseur, facture…" />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/liquidations/${row.id}`)}
                    rowLabel={(row) => `Ouvrir la liquidation ${row.reference}`}
                    minWidth={1120}
                    empty={filtered
                        ? <EmptyState icon={ICON.search} title="Aucun résultat">Aucune liquidation ne correspond à ces critères.</EmptyState>
                        : <EmptyState icon={ICON.settlement} title="Aucune liquidation pour cet exercice">Une liquidation est ouverte automatiquement au visa d’un engagement.</EmptyState>}
                />
                <Pagination meta={meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
            </SectionCard>
        </main>
    );
}
