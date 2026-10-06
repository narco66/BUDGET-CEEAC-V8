import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, ErrorMessage, FormField, ICON, PageHeader, SectionCard, useToast, type Column } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

export default function ParametrageRecettesPage() {
    const toast = useToast();
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [categorie, setCategorie] = useState({ code: '', label: '' });
    const [mode, setMode] = useState({ code: '', label: '' });
    const [approche, setApproche] = useState('');
    const [critique, setCritique] = useState('');

    function load() {
        api.get('/recettes/referentiel').then((response) => {
            setRef(response.data);
            setApproche(String(response.data.seuils?.approche_jours ?? ''));
            setCritique(String(response.data.seuils?.critique_jours ?? ''));
            setError('');
        }).catch((caught) => setError(errorsOf(caught)));
    }
    useEffect(() => { load(); }, []);

    async function sauver(event: FormEvent, request: () => Promise<unknown>, message: string) {
        event.preventDefault();
        try {
            await request();
            toast.success(message);
            setCategorie({ code: '', label: '' });
            setMode({ code: '', label: '' });
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    async function basculer(kind: 'categories' | 'modes', row: { id: number; code: string; label: string; active: boolean }) {
        try {
            await api.patch(`/recettes/${kind}/${row.id}`, { code: row.code, label: row.label, active: !row.active });
            toast.success(row.active ? 'Référentiel désactivé.' : 'Référentiel activé.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    const columns: Column<any>[] = [
        { key: 'code', header: 'Code', className: 'mono', render: (row) => row.code },
        { key: 'label', header: 'Libellé', render: (row) => row.label },
        { key: 'active', header: 'Active', render: (row) => row.active ? 'Oui' : 'Non' },
        { key: 'actions', header: 'Actions', render: (row) => ref?.droits?.configurer ? <Button size="sm" onClick={() => basculer('categories', row)}>{row.active ? 'Désactiver' : 'Activer'}</Button> : null },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Paramétrage des recettes" subtitle="Catégories, modes d’encaissement et seuils d’alerte. Réservé au Directeur du Budget." />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard title="Catégories de recettes" icon={ICON.settings} flush>
                <DataTable columns={columns} rows={ref?.categories ?? []} rowKey={(row) => row.id} />
            </SectionCard>
            <SectionCard title="Modes d’encaissement" icon={ICON.payment} flush>
                <DataTable columns={[
                    columns[0],
                    columns[1],
                    columns[2],
                    { key: 'actions', header: 'Actions', render: (row) => ref?.droits?.configurer ? <Button size="sm" onClick={() => basculer('modes', row)}>{row.active ? 'Désactiver' : 'Activer'}</Button> : null },
                ]} rows={ref?.modes ?? []} rowKey={(row) => row.id} />
            </SectionCard>
            {ref?.droits?.configurer && (
                <>
                    <SectionCard title="Nouvelle catégorie" icon={ICON.create}>
                        <form className="form-grid" onSubmit={(event) => sauver(event, () => api.post('/recettes/categories', categorie), 'Catégorie enregistrée.')}>
                            <FormField label="Code" required><input className="inp" required value={categorie.code} onChange={(event) => setCategorie({ ...categorie, code: event.target.value })} /></FormField>
                            <FormField label="Libellé" required><input className="inp" required value={categorie.label} onChange={(event) => setCategorie({ ...categorie, label: event.target.value })} /></FormField>
                            <Button variant="primary" type="submit">Ajouter</Button>
                        </form>
                    </SectionCard>
                    <SectionCard title="Mode d’encaissement" icon={ICON.payment}>
                        <form className="form-grid" onSubmit={(event) => sauver(event, () => api.post('/recettes/modes', mode), 'Mode enregistré.')}>
                            <FormField label="Code" required><input className="inp" required value={mode.code} onChange={(event) => setMode({ ...mode, code: event.target.value })} /></FormField>
                            <FormField label="Libellé" required><input className="inp" required value={mode.label} onChange={(event) => setMode({ ...mode, label: event.target.value })} /></FormField>
                            <Button type="submit">Ajouter</Button>
                        </form>
                    </SectionCard>
                    <SectionCard title="Seuils d’alerte" icon={ICON.calendar}>
                        <form className="form-grid" onSubmit={(event) => sauver(event, async () => { await api.post('/recettes/seuils', { key: 'approche_jours', value: Number(approche) }); await api.post('/recettes/seuils', { key: 'critique_jours', value: Number(critique) }); }, 'Seuils enregistrés.')}>
                            <FormField label="Alerte avant échéance (jours)"><input className="inp" inputMode="numeric" value={approche} onChange={(event) => setApproche(event.target.value)} /></FormField>
                            <FormField label="Retard critique (jours)"><input className="inp" inputMode="numeric" value={critique} onChange={(event) => setCritique(event.target.value)} /></FormField>
                            <Button variant="primary" type="submit">Enregistrer</Button>
                        </form>
                    </SectionCard>
                </>
            )}
        </main>
    );
}
