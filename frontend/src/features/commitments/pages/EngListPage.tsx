import { faStopwatch } from '@fortawesome/free-solid-svg-icons';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
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
    ['', 'Tous'],
    ['en_instruction', 'En instruction'],
    ['a_valider', 'À valider'],
    ['en_controle', 'Transmis au CF'],
    ['retourne', 'Retournés'],
    ['rejete', 'Rejetés'],
    ['transforme_liquidation', 'Transformés en LIQ'],
];

export default function EngList() {
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
        api.get('/engagements', { params })
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
        en_instruction: board.instruction,
        a_valider: board.a_valider,
        en_controle: board.controle,
        retourne: board.retournes,
        rejete: board.rejetes,
        transforme_liquidation: board.transformes,
    } : {};

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'Référence · EB source',
            render: (row) => (
                <div>
                    <Link className="cell-ref" to={`/engagements/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.reference}</Link>
                    <div className="cell-sub mono">{row.eb_reference}</div>
                </div>
            ),
        },
        { key: 'objet', header: 'Objet · structure', render: (row) => <div style={{ minWidth: 200 }}><div style={{ fontWeight: 500 }}>{row.objet}</div><div className="cell-sub">{row.structure}</div></div> },
        { key: 'type', header: 'Type · ligne', render: (row) => <div><div>{row.nature_libelle}</div><div className="cell-sub mono">{row.ligne}</div></div> },
        { key: 'beneficiaire', header: 'Bénéficiaire', render: (row) => row.beneficiaire || <span className="subtle">Non renseigné</span> },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'etape', header: 'Étape · dernière action', render: (row) => <div><div>{row.acteur_attendu}</div><div className="cell-sub">{row.derniere_action}</div></div> },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    const filtered = Boolean(filters.q || filters.statut);

    return (
        <main className="app-content">
            <PageHeader
                title="Engagements"
                subtitle="Réservation des crédits, contrôle de disponibilité et visa du Contrôleur Financier."
                meta={<ChainTrail current="ENG" />}
                actions={<Button icon={ICON.export} loading={exporting} onClick={async () => {
                    setExporting(true);
                    try {
                        await telecharger('/engagements/export', 'engagements.xlsx');
                    } catch (caught) {
                        toast.error(errorsOf(caught));
                    } finally {
                        setExporting(false);
                    }
                }}>Exporter</Button>}
            />

            {board && (
                <>
                    <div className="grid-kpi">
                        <StatCard dark label="Engagements · exercice 2026" value={board.total} unit="dossiers" hint={`${fcfa(board.montant)} FCFA engagés`} icon={ICON.commitment} active={!filters.statut} onClick={() => setFilter({ statut: '' })} />
                        <StatCard label="À valider" value={board.a_valider} icon={ICON.pending} tone="warning" active={filters.statut === 'a_valider'} onClick={() => setFilter({ statut: 'a_valider' })} />
                        <StatCard label="Transmis au CF" value={board.controle} icon={ICON.visa} tone="info" active={filters.statut === 'en_controle'} onClick={() => setFilter({ statut: 'en_controle' })} />
                        <StatCard label="Transformés en LIQ" value={board.transformes} icon={ICON.transform} tone="success" active={filters.statut === 'transforme_liquidation'} onClick={() => setFilter({ statut: 'transforme_liquidation' })} />
                    </div>
                    <StatStrip label="Autres statuts">
                        <StripCell label="En instruction" value={board.instruction} color="var(--warning-solid)" onClick={() => setFilter({ statut: 'en_instruction' })} active={filters.statut === 'en_instruction'} />
                        <StripCell label="Retournés" value={board.retournes} color="var(--orange-solid)" onClick={() => setFilter({ statut: 'retourne' })} active={filters.statut === 'retourne'} />
                        <StripCell label="Rejetés" value={board.rejetes} color="var(--danger-solid)" onClick={() => setFilter({ statut: 'rejete' })} active={filters.statut === 'rejete'} />
                    </StatStrip>
                </>
            )}

            {board?.delais && (
                <SectionCard title="Temps moyen par étape" subtitle="Calculé sur les passages réellement enregistrés" icon={faStopwatch}>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
                        {board.delais.map((row) => (
                            <div key={row.etape} className="stack-sm" style={{ gap: 2, padding: '10px 12px', borderRadius: 'var(--radius-md)', background: 'var(--slate-50)' }}>
                                <span className="subtle">{row.etape}</span>
                                <span className="mono strong" style={{ fontSize: 'var(--text-lg)' }}>{row.jours} <small className="muted">j</small></span>
                            </div>
                        ))}
                    </div>
                </SectionCard>
            )}

            <SectionCard flush>
                <Tabs label="Statut des engagements" inCard value={filters.statut} onChange={(statut) => setFilter({ statut })} items={FILTERS.map(([value, label]) => ({ value, label, count: counts[value] }))} />
                <FilterBar onReset={filtered ? () => setFilters({ q: '', statut: '', page: 1 }) : null}>
                    <SearchInput value={filters.q} onChange={(q) => setFilter({ q })} placeholder="Référence ENG, EB, objet, bénéficiaire…" />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/engagements/${row.id}`)}
                    rowLabel={(row) => `Ouvrir l’engagement ${row.reference}`}
                    minWidth={1080}
                    empty={filtered
                        ? <EmptyState icon={ICON.search} title="Aucun résultat">Aucun engagement ne correspond à ces critères.</EmptyState>
                        : <EmptyState icon={ICON.commitment} title="Aucun engagement pour cet exercice">Un engagement naît de la transformation d’une expression de besoin approuvée.</EmptyState>}
                />
                <Pagination meta={meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
            </SectionCard>
        </main>
    );
}
