import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { DataTable, EmptyState, ErrorMessage, ICON, Meter, PageHeader, SectionCard, StatusBadge, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function CreancesPage() {
    const [portrait, setPortrait] = useState<any>(null);
    const [echeancier, setEcheancier] = useState<any[]>([]);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/recettes/creances').then((response) => { setPortrait(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
        api.get('/recettes/echeancier').then((response) => setEcheancier(response.data.data ?? [])).catch(() => setEcheancier([]));
    }, []);

    const total = (portrait?.vieillissement ?? []).reduce((sum: number, row: any) => sum + row.montant, 0) || 1;
    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => <Link to={`/recettes/titres/${row.id}`}>{row.reference}</Link> },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'montant', header: 'Initial', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'encaisse', header: 'Payé', align: 'right', className: 'mono', render: (row) => fcfa(row.encaisse) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'echeance', header: 'Échéance', render: (row) => row.echeance },
        { key: 'retard', header: 'Retard', render: (row) => row.jours_retard ? `${row.jours_retard} j` : '—' },
        { key: 'relance', header: 'Dernière relance', render: (row) => row.derniere_relance || '—' },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Créances" subtitle="Soldes restant à recouvrer et ancienneté des retards." />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard title="Vieillissement" icon={ICON.calendar}>
                {(portrait?.vieillissement ?? []).map((row: any) => <Meter key={row.code} label={row.libelle} value={fcfa(row.montant)} ratio={(row.montant / total) * 100} />)}
            </SectionCard>
            <SectionCard title="Échéancier" icon={ICON.calendar} flush>
                <DataTable columns={columns} rows={echeancier} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.calendar} title="Aucune échéance ouverte" />} />
            </SectionCard>
        </main>
    );
}
