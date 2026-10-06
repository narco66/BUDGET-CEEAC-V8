import { FormEvent, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, PageHeader, SearchInput, SectionCard, useToast } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function DocumentSearchPage() {
    const toast = useToast();
    const [term, setTerm] = useState('');
    const [rows, setRows] = useState<any[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [retain, setRetain] = useState<Record<string, string>>({});

    async function search(event?: FormEvent) {
        event?.preventDefault();
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/documents/recherche', { params: { q: term } });
            setRows(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setLoading(false);
        }
    }

    async function conserver(row: any) {
        const key = `${row.source}-${row.id}`;
        const date = retain[key];
        if (!date) return;
        try {
            await api.post('/documents/conservation', { source: row.source, id: row.id, retain_until: date });
            toast.success('Date de conservation enregistrée.');
            search();
        } catch (caught) {
            toast.error(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                eyebrow="Pièces et actes"
                title="Recherche documentaire"
                subtitle="Les pièces des dossiers et les actes officiels déjà archivés. Une date de conservation est enregistrée sans déplacer le fichier."
            />
            <SectionCard>
                <form className="cluster" style={{ alignItems: 'flex-end' }} onSubmit={search}>
                    <FormField label="Référence ou nom de fichier" style={{ flex: '1 1 320px' }}>
                        <SearchInput value={term} onChange={setTerm} placeholder="Visa, facture, référence…" />
                    </FormField>
                    <Button variant="primary" type="submit" icon={ICON.search} loading={loading}>Rechercher</Button>
                </form>
            </SectionCard>
            <ErrorMessage error={error} title="Recherche impossible" />
            <SectionCard title="Résultats" icon={ICON.document} flush>
                <DataTable
                    columns={[
                        { key: 'source', header: 'Origine', render: (row: any) => row.source === 'acte' ? 'Acte officiel' : 'Pièce de dossier' },
                        { key: 'titre', header: 'Titre', render: (row: any) => <span className="mono">{row.titre}</span> },
                        { key: 'conservation', header: 'Conservation', render: (row: any) => row.conservation || '—' },
                        {
                            key: 'action',
                            header: 'Conserver jusqu’au',
                            render: (row: any) => {
                                const key = `${row.source}-${row.id}`;
                                return (
                                    <div className="cluster">
                                        <input className="inp" type="date" value={retain[key] ?? ''} onChange={(event) => setRetain({ ...retain, [key]: event.target.value })} />
                                        <Button size="sm" icon={ICON.archive} disabled={!retain[key]} onClick={() => conserver(row)}>Enregistrer</Button>
                                    </div>
                                );
                            },
                        },
                    ]}
                    rows={rows}
                    rowKey={(row: any) => `${row.source}-${row.id}`}
                    loading={loading}
                    empty={<EmptyState icon={ICON.document} title="Aucun document" compact>Lancez une recherche pour parcourir les pièces et les actes.</EmptyState>}
                />
            </SectionCard>
        </main>
    );
}
