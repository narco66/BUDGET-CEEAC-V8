import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, StatusBadge, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function RapprochementsRecettesPage() {
    const toast = useToast();
    const [portrait, setPortrait] = useState<any>(null);
    const [droits, setDroits] = useState<any>({});
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        Promise.all([api.get('/recettes/rapprochements'), api.get('/recettes/referentiel')])
            .then(([rows, ref]) => { setPortrait(rows.data); setDroits(ref.data.droits ?? {}); setError(''); })
            .catch((caught) => setError(errorsOf(caught)));
    }
    useEffect(() => { load(); }, []);

    async function rapprocher(id: number, statut: 'rapproche' | 'anomalie') {
        setPending(`${statut}${id}`);
        try {
            await api.post(`/recettes/encaissements/${id}/rapprocher`, { statut, motif: statut === 'anomalie' ? 'Écart de relevé' : null });
            toast.success(statut === 'rapproche' ? 'Encaissement rapproché.' : 'Anomalie signalée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'date', header: 'Date', render: (row) => row.date },
        { key: 'montant', header: 'Montant', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'titres', header: 'Recettes', render: (row) => (row.titres ?? []).map((titre: { reference: string }) => titre.reference).join(', ') || 'Non identifié' },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'actions', header: 'Actions', render: (row) => droits.rapprocher && ['non_rapproche', 'non_identifie'].includes(row.statut) && (
            <>
                <Button size="sm" variant="primary" loading={pending === `rapproche${row.id}`} onClick={() => rapprocher(row.id, 'rapproche')}>Rapprocher</Button>
                <Button size="sm" loading={pending === `anomalie${row.id}`} onClick={() => rapprocher(row.id, 'anomalie')}>Anomalie</Button>
            </>
        ) },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Rapprochements des recettes" subtitle="Concordance entre l’encaissement enregistré et le relevé. L’agent qui a saisi l’opération ne la rapproche pas." />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.reconciliation} title="Aucun encaissement" />} />
            </SectionCard>
        </main>
    );
}
