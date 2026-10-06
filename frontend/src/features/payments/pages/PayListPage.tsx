import { faBuildingColumns, faCashRegister, faMoneyCheck } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
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
    KeyValueList,
    OpenCell,
    PageHeader,
    Pagination,
    SearchInput,
    SectionCard,
    StatCard,
    StatStrip,
    StatusBadge,
    StripCell,
    useToast,
    type Column,
} from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const MODES = { virement: 'Virement', cheque: 'Chèque', caisse: 'Caisse' };

export default function PayList() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<any[]>([]);
    const [board, setBoard] = useState<any>(null);
    const [meta, setMeta] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [exporting, setExporting] = useState(false);
    const toast = useToast();
    const [filters, setFilters] = useState({ q: '', statut: '', mode: '', page: 1 });
    const query = useDebouncedValue(filters, 250);

    useEffect(() => {
        const params: Record<string, string | number> = {};
        Object.entries(query).forEach(([key, value]) => {
            if (value !== '' && value !== null) {
                params[key] = value;
            }
        });
        setLoading(true);
        setError(null);
        api.get('/paiements', { params })
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

    const filtered = Boolean(filters.q || filters.statut || filters.mode);

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'Paiement · ordre',
            render: (row) => (
                <div>
                    <Link className="cell-ref" to={`/paiements/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.reference}</Link>
                    <div className="cell-sub"><Link className="mono" to={`/ordonnancements/${row.ordonnancement_id}`} onClick={(event) => event.stopPropagation()}>{row.ordonnancement}</Link></div>
                </div>
            ),
        },
        { key: 'beneficiaire', header: 'Bénéficiaire · objet', render: (row) => <div style={{ minWidth: 200 }}><div style={{ fontWeight: 500 }}>{row.beneficiaire}</div><div className="cell-sub">{row.objet}</div></div> },
        { key: 'ordonnance', header: 'Ordonnancé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_ordonnance) },
        { key: 'paye', header: 'Payé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_paye) },
        { key: 'reste', header: 'Reste', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.reste) },
        { key: 'mode', header: 'Mode', render: (row) => MODES[row.mode] || <span className="subtle">—</span> },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Paiements"
                subtitle="L’Ordonnateur autorise, l’Agence Comptable contrôle et exécute."
                meta={<ChainTrail current="PAY" />}
                actions={(
                    <>
                        <Button to="/paiements/lots" icon={ICON.paymentBatch}>Lots de paiement</Button>
                        <Button icon={ICON.export} loading={exporting} onClick={async () => {
                            setExporting(true);
                            try {
                                await telecharger('/paiements/export', 'paiements.xlsx');
                            } catch (caught) {
                                toast.error(errorsOf(caught));
                            } finally {
                                setExporting(false);
                            }
                        }}>Exporter</Button>
                    </>
                )}
            />

            {board && (
                <>
                    <div className="grid-kpi cols-3">
                        <StatCard dark label="Payé" value={fcfa(board.paye)} unit="FCFA" hint={`${board.taux} % de l’ordonnancé`} icon={ICON.payment} active={!filters.statut} onClick={() => setFilter({ statut: '' })} />
                        <StatCard label="Reste à payer" value={fcfa(board.reste)} unit="FCFA" hint={`${board.total} dossier(s)`} icon={ICON.pending} tone="orange" active={filters.statut === 'autorise'} onClick={() => setFilter({ statut: 'autorise' })} />
                        <SectionCard title="Modes de règlement" icon={ICON.bankAccount}>
                            <KeyValueList compact items={[
                                { label: <span><FontAwesomeIcon icon={faBuildingColumns} style={{ color: 'var(--slate-400)', marginRight: 6 }} /> Virement</span>, value: board.modes.virement },
                                { label: <span><FontAwesomeIcon icon={faMoneyCheck} style={{ color: 'var(--slate-400)', marginRight: 6 }} /> Chèque</span>, value: board.modes.cheque },
                                { label: <span><FontAwesomeIcon icon={faCashRegister} style={{ color: 'var(--slate-400)', marginRight: 6 }} /> Caisse</span>, value: board.modes.caisse },
                            ]} />
                        </SectionCard>
                    </div>
                    <StatStrip label="Compteurs par statut">
                        {board.compteurs.map((item) => (
                            <StripCell key={item.statut} label={item.libelle} value={item.nombre} onClick={() => setFilter({ statut: item.statut })} active={filters.statut === item.statut} />
                        ))}
                    </StatStrip>
                </>
            )}

            <SectionCard flush>
                <FilterBar onReset={filtered ? () => setFilters({ q: '', statut: '', mode: '', page: 1 }) : null}>
                    <SearchInput value={filters.q} onChange={(q) => setFilter({ q })} placeholder="Référence, bénéficiaire, ordre" />
                    <select className="inp" aria-label="Mode de règlement" value={filters.mode} onChange={(event) => setFilter({ mode: event.target.value })}>
                        <option value="">Tous les modes</option>
                        <option value="virement">Virement</option>
                        <option value="cheque">Chèque</option>
                        <option value="caisse">Caisse</option>
                    </select>
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/paiements/${row.id}`)}
                    rowLabel={(row) => `Ouvrir le paiement ${row.reference}`}
                    minWidth={1000}
                    empty={<EmptyState icon={ICON.payment} title="Aucun paiement pour ces filtres">Un dossier naît à la transmission d’un ordre signé.</EmptyState>}
                />
                <Pagination meta={meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
            </SectionCard>
        </main>
    );
}
