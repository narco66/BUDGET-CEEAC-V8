import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FilterBar, FilterSelect, ICON, PageHeader, Pagination, SectionCard, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { PreparationNav, StatutPrep } from '../preparation/shell';

export default function DossiersPage() {
    const [portrait, setPortrait] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [statut, setStatut] = useState('');
    const [campagne, setCampagne] = useState('');
    const [q, setQ] = useState('');

    function load(next = page) {
        api.get('/preparation/dossiers', { params: { page: next, statut: statut || undefined, campaign_id: campagne || undefined, q: q || undefined } })
            .then((response) => { setPortrait(response.data); setError(''); })
            .catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [page, statut, campagne]);
    useEffect(() => { api.get('/preparation/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', render: (row) => <Link className="cell-ref" to={`/preparation/dossiers/${row.id}`}>{row.reference}</Link> },
        { key: 'titre', header: 'Titre', render: (row) => row.titre },
        { key: 'campagne', header: 'Campagne', render: (row) => row.campagne },
        { key: 'structure', header: 'Structure', render: (row) => row.structure },
        { key: 'montant', header: 'Proposé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
        { key: 'actions', header: 'Actions', render: (row) => (
            <span className="cluster">
                <Button size="sm" to={`/preparation/dossiers/${row.id}`}>Voir</Button>
                {['brouillon', 'retourne'].includes(row.statut) && <Button size="sm" to={`/preparation/dossiers/${row.id}/modifier`}>Modifier</Button>}
            </span>
        ) },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Propositions budgétaires" subtitle="Dossiers de fonctionnement et d’investissement, distincts des crédits adoptés." actions={<Button variant="primary" icon={ICON.create} to="/preparation/dossiers/nouvelle">Nouvelle proposition</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <FilterBar onReset={() => { setStatut(''); setCampagne(''); setQ(''); setPage(1); }}>
                <input className="inp" placeholder="Référence ou titre" value={q} onChange={(event) => setQ(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); load(1); } }} />
                <FilterSelect label="Campagne" value={campagne} onChange={(value) => { setCampagne(value); setPage(1); }} options={(ref?.campagnes ?? []).map((row: any) => ({ value: String(row.id), label: row.code }))} />
                <FilterSelect label="Statut" value={statut} onChange={(value) => { setStatut(value); setPage(1); }} options={[
                    { value: 'brouillon', label: 'Brouillon' },
                    { value: 'soumis', label: 'Soumis' },
                    { value: 'retourne', label: 'Retourné' },
                    { value: 'retenu', label: 'Retenu' },
                    { value: 'ecarte', label: 'Écarté' },
                ]} />
            </FilterBar>
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.budget} title="Aucune proposition" action={<Button variant="primary" icon={ICON.create} to="/preparation/dossiers/nouvelle">Nouvelle proposition</Button>} />} />
            </SectionCard>
            <Pagination meta={portrait?.meta} onPage={setPage} noun="proposition" />
        </main>
    );
}
