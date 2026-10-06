import { useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import { DataTable, EmptyState, ICON, OpenCell, PageHeader, SectionCard, type Column } from '../../../components/ui';
import { fcfa } from '../../../utils/format';
import useResource from '../../../utils/useResource';

const MODES = { virement: 'Virement', cheque: 'Chèque', caisse: 'Caisse' };

export default function RapprochementsPage() {
    const navigate = useNavigate();
    const { data, loading, error } = useResource<any[]>(() => api.get('/paiements/rapprochements').then((response) => response.data.data), []);
    const rows = data ?? [];

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', render: (row) => <span className="cell-ref">{row.reference}</span> },
        { key: 'mode', header: 'Mode', render: (row) => MODES[row.mode] || row.mode || '—' },
        { key: 'montant', header: 'Montant', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'paye', header: 'Payé', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant_paye) },
        { key: 'reglement', header: 'Référence de règlement', className: 'mono', render: (row) => row.reference_reglement || '—' },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Rapprochements"
                subtitle="Paiements exécutés qui attendent la concordance banque ou caisse."
            />
            <SectionCard title="Paiements à rapprocher" icon={ICON.reconciliation} subtitle={loading ? undefined : `${rows.length} paiement(s)`} flush>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/paiements/${row.id}`)}
                    rowLabel={(row) => `Ouvrir le paiement ${row.reference}`}
                    empty={<EmptyState icon={ICON.reconciliation} title="Aucun paiement en attente de rapprochement">Les paiements exécutés apparaissent ici jusqu’à leur concordance avec le relevé.</EmptyState>}
                />
            </SectionCard>
        </main>
    );
}
