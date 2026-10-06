import { useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, StatusBadge, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { telecharger } from '../download';

export default function EtatsRecettesPage() {
    const [rows, setRows] = useState<any[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/recettes/titres', { params: { per_page: 100 } }).then((response) => setRows(response.data.data ?? [])).catch((caught) => setError(errorsOf(caught)));
    }, []);

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'annee', header: 'Exercice', render: (row) => row.annee },
        { key: 'categorie', header: 'Nature', render: (row) => row.categorie },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'montant', header: 'Constaté', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'encaisse', header: 'Encaissé', align: 'right', className: 'mono', render: (row) => fcfa(row.encaisse) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="États des recettes"
                subtitle="Export du registre selon les colonnes affichées."
                actions={(
                    <>
                        <Button icon={ICON.export} onClick={() => telecharger('/recettes/etats', 'etat-recettes.xlsx').catch((caught) => setError(errorsOf(caught)))}>Excel</Button>
                        <Button icon={ICON.pdf} onClick={() => telecharger('/recettes/etats', 'etat-recettes.pdf', { format: 'pdf' }).catch((caught) => setError(errorsOf(caught)))}>PDF</Button>
                    </>
                )}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <DataTable columns={columns} rows={rows ?? []} rowKey={(row) => row.id} loading={rows === null && !error} empty={<EmptyState icon={ICON.report} title="Aucune ligne à exporter" />} />
            </SectionCard>
        </main>
    );
}
