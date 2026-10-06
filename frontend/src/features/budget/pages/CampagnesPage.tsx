import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FilterBar, FilterSelect, ICON, PageHeader, Pagination, SectionCard, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { PreparationNav, StatutPrep } from '../preparation/shell';

export default function CampagnesPage() {
    const toast = useToast();
    const { confirm } = useDialogs();
    const [portrait, setPortrait] = useState<any>(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [statut, setStatut] = useState('');
    const [q, setQ] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load(next = page) {
        api.get('/preparation/campagnes', { params: { page: next, statut: statut || undefined, q: q || undefined } })
            .then((response) => { setPortrait(response.data); setError(''); })
            .catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [page, statut]);

    async function supprimer(row: any) {
        const ok = await confirm({ title: 'Supprimer la campagne', description: `${row.code} doit être un brouillon sans dossier.`, confirmLabel: 'Supprimer', tone: 'danger', icon: ICON.archive });
        if (!ok) {
            return;
        }
        setPending(`del${row.id}`);
        try {
            await api.delete(`/preparation/campagnes/${row.id}`);
            toast.success('Campagne supprimée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const columns: Column<any>[] = [
        { key: 'code', header: 'Code', render: (row) => <Link className="cell-ref" to={`/preparation/campagnes/${row.id}`}>{row.code}</Link> },
        { key: 'libelle', header: 'Intitulé', render: (row) => row.libelle },
        { key: 'exercice', header: 'Exercice', render: (row) => row.exercice },
        { key: 'ouverture', header: 'Ouverture', render: (row) => row.ouverture ?? '—' },
        { key: 'cloture', header: 'Clôture', render: (row) => row.cloture ?? '—' },
        { key: 'dossiers', header: 'Dossiers', align: 'right', render: (row) => row.dossiers },
        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
        { key: 'actions', header: 'Actions', render: (row) => (
            <span className="cluster">
                <Button size="sm" to={`/preparation/campagnes/${row.id}`}>Voir</Button>
                {row.statut === 'brouillon' && <Button size="sm" to={`/preparation/campagnes/${row.id}/modifier`}>Modifier</Button>}
                {row.statut === 'brouillon' && row.dossiers === 0 && <Button size="sm" loading={pending === `del${row.id}`} onClick={() => supprimer(row)}>Supprimer</Button>}
            </span>
        ) },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Campagnes budgétaires" subtitle="Une campagne ouverte par exercice en préparation." actions={<Button variant="primary" icon={ICON.create} to="/preparation/campagnes/nouvelle">Nouvelle campagne</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <FilterBar onReset={() => { setStatut(''); setQ(''); setPage(1); }}>
                <input className="inp" placeholder="Code ou intitulé" value={q} onChange={(event) => setQ(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); load(1); } }} />
                <FilterSelect label="Statut" value={statut} onChange={(value) => { setStatut(value); setPage(1); }} options={[
                    { value: 'brouillon', label: 'Brouillon' },
                    { value: 'ouverte', label: 'Ouverte' },
                    { value: 'suspendue', label: 'Suspendue' },
                    { value: 'cloturee', label: 'Clôturée' },
                    { value: 'archivee', label: 'Archivée' },
                ]} />
            </FilterBar>
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.budget} title="Aucune campagne" action={<Button variant="primary" icon={ICON.create} to="/preparation/campagnes/nouvelle">Nouvelle campagne</Button>} />} />
            </SectionCard>
            <Pagination meta={portrait?.meta} onPage={setPage} noun="campagne" />
        </main>
    );
}
