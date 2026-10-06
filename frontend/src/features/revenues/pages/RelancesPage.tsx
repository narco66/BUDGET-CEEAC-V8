import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { DataTable, EmptyState, ErrorMessage, ICON, PageHeader, SectionCard, type Column } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function RelancesPage() {
    const [rows, setRows] = useState<any[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/recettes/relances').then((response) => setRows(response.data.data ?? [])).catch((caught) => setError(errorsOf(caught)));
    }, []);

    const columns: Column<any>[] = [
        { key: 'date', header: 'Date', render: (row) => row.date },
        { key: 'reference', header: 'Titre', render: (row) => <Link to={`/recettes/titres/${row.order_id}`}>{row.reference}</Link> },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'libelle', header: 'Relance', render: (row) => row.libelle },
        { key: 'canal', header: 'Canal', render: (row) => row.canal },
        { key: 'destinataire', header: 'Destinataire', render: (row) => row.destinataire },
        { key: 'resultat', header: 'Résultat', render: (row) => row.resultat || '—' },
        { key: 'suite', header: 'Prochaine action', render: (row) => row.prochaine_action || '—' },
        { key: 'auteur', header: 'Auteur', render: (row) => row.auteur },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Relances" subtitle="Premières relances, mises en demeure et rappels. Une nouvelle relance se saisit depuis la fiche du titre." />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <DataTable columns={columns} rows={rows ?? []} rowKey={(row) => row.id} loading={rows === null && !error} empty={<EmptyState icon={ICON.comment} title="Aucune relance">Ouvrez une créance prise en charge pour enregistrer la première relance.</EmptyState>} />
            </SectionCard>
        </main>
    );
}
