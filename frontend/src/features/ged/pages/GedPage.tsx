import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, ICON, PageHeader, PageSkeleton, Pagination, SearchInput, SectionCard, type PageMeta } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Ligne = {
    id: number;
    reference: string;
    titre: string;
    statut: string;
    confidentialite: string;
    origine: string;
    exercice: number | null;
    scan: string;
    version?: number;
};

export default function GedPage() {
    const [lignes, setLignes] = useState<Ligne[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [indicateurs, setIndicateurs] = useState<Record<string, number>>({});
    const [q, setQ] = useState('');
    const [vue, setVue] = useState('');
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [selection, setSelection] = useState<number[]>([]);

    async function charger(pageDemandee = page, vueDemandee = vue, terme = q) {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/ged', { params: { q: terme, vue: vueDemandee, page: pageDemandee } });
            setLignes(response.data.data);
            setMeta(response.data.meta);
            setIndicateurs(response.data.indicateurs ?? {});
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        charger(1, vue, q);
    }, [vue]);

    function rechercher(event: FormEvent) {
        event.preventDefault();
        setPage(1);
        charger(1, vue, q);
    }

    async function exporter() {
        if (selection.length === 0) {
            return;
        }
        const response = await api.post('/ged/export', { ids: selection }, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const lien = document.createElement('a');
        lien.href = url;
        lien.download = 'bordereau-ged.zip';
        lien.click();
        URL.revokeObjectURL(url);
    }

    return (
        <main className="app-content">
            <PageHeader
                title="Gestion électronique des documents"
                subtitle="Dépôt, versions, confidentialité et rattachement aux dossiers de la chaîne."
                actions={<Button icon={ICON.download} onClick={exporter} disabled={selection.length === 0}>Exporter la sélection</Button>}
            />
            <div className="grid-kpi">
                <SectionCard title="Visibles"><strong>{indicateurs.total ?? 0}</strong></SectionCard>
                <SectionCard title="À vérifier"><strong>{indicateurs.a_verifier ?? 0}</strong></SectionCard>
                <SectionCard title="Rejetés"><strong>{indicateurs.rejetes ?? 0}</strong></SectionCard>
                <SectionCard title="Archives"><strong>{indicateurs.archives ?? 0}</strong></SectionCard>
                <SectionCard title="Quarantaine"><strong>{indicateurs.quarantaine ?? 0}</strong></SectionCard>
            </div>
            <SectionCard title="Documents" icon={ICON.document}>
                <form onSubmit={rechercher} className="form-actions">
                    <SearchInput value={q} onChange={setQ} placeholder="Référence ou titre" />
                    <Button type="submit" icon={ICON.search}>Rechercher</Button>
                    <Button type="button" onClick={() => setVue('')}>Tous</Button>
                    <Button type="button" onClick={() => setVue('a_traiter')}>À traiter</Button>
                    <Button type="button" onClick={() => setVue('miens')}>Les miens</Button>
                    <Button type="button" onClick={() => setVue('archives')}>Archives</Button>
                </form>
                <ErrorMessage error={error} />
                {loading ? <PageSkeleton /> : (
                    <DataTable
                        columns={[
                            { key: 'sel', header: '', render: (row: Ligne) => <input type="checkbox" aria-label={`Sélectionner ${row.reference}`} checked={selection.includes(row.id)} onChange={() => setSelection((courant) => courant.includes(row.id) ? courant.filter((id) => id !== row.id) : [...courant, row.id])} /> },
                            { key: 'reference', header: 'Référence', render: (row: Ligne) => <Link to={`/ged/${row.id}`}>{row.reference}</Link> },
                            { key: 'titre', header: 'Titre', render: (row: Ligne) => row.titre },
                            { key: 'statut', header: 'Statut', render: (row: Ligne) => row.scan === 'quarantaine' ? 'quarantaine' : row.statut },
                            { key: 'confidentialite', header: 'Confidentialité', render: (row: Ligne) => row.confidentialite },
                            { key: 'version', header: 'Version', render: (row: Ligne) => row.version ?? '—' },
                        ]}
                        rows={lignes}
                        rowKey={(row: Ligne) => row.id}
                        empty={<EmptyState title="Aucun document visible">Aucun document ne correspond à cette vue pour votre périmètre.</EmptyState>}
                    />
                )}
                <Pagination meta={meta} noun="document" onPage={(suivante) => { setPage(suivante); charger(suivante); }} />
            </SectionCard>
        </main>
    );
}
