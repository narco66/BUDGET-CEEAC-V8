import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FilterBar, FilterSelect, FormField, ICON, Modal, PageHeader, Pagination, SectionCard, StatusBadge, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

const vide = { exercice_id: '', category_id: '', organization_unit_id: '', label: '', description: '', montant: '', source_label: '', periode: '', date_prevue: '', observations: '' };

export default function PrevisionsPage() {
    const toast = useToast();
    const { confirm } = useDialogs();
    const [portrait, setPortrait] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [modal, setModal] = useState(false);
    const [page, setPage] = useState(1);
    const [statut, setStatut] = useState('');
    const [q, setQ] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [form, setForm] = useState(vide);

    function load(nextPage = page) {
        api.get('/recettes/previsions', { params: { page: nextPage, statut: statut || undefined, q: q || undefined } })
            .then((response) => { setPortrait(response.data); setError(''); })
            .catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [page, statut]);
    useEffect(() => { api.get('/recettes/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    async function creer(event: FormEvent) {
        event.preventDefault();
        setPending('creer');
        try {
            await api.post('/recettes/previsions', {
                ...form,
                exercice_id: Number(form.exercice_id),
                category_id: Number(form.category_id),
                organization_unit_id: form.organization_unit_id ? Number(form.organization_unit_id) : null,
                montant: Number(form.montant),
            });
            setModal(false);
            setForm(vide);
            toast.success('Prévision enregistrée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function supprimer(row: any) {
        const ok = await confirm({ title: 'Supprimer la prévision', description: `${row.code} sera définitivement retirée. Seul un brouillon sans recette peut être supprimé.`, confirmLabel: 'Supprimer', tone: 'danger', icon: ICON.archive });
        if (!ok) {
            return;
        }
        setPending(`del${row.id}`);
        try {
            await api.delete(`/recettes/previsions/${row.id}`);
            toast.success('Prévision supprimée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const columns: Column<any>[] = [
        { key: 'code', header: 'Référence', className: 'mono', render: (row) => <Link to={`/recettes/previsions/${row.id}`}>{row.code}</Link> },
        { key: 'annee', header: 'Exercice', render: (row) => row.annee },
        { key: 'libelle', header: 'Libellé', render: (row) => row.libelle },
        { key: 'categorie', header: 'Catégorie', render: (row) => row.categorie },
        { key: 'montant', header: 'Prévu', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'realise', header: 'Réalisé', align: 'right', className: 'mono', render: (row) => fcfa(row.realise) },
        { key: 'ecart', header: 'Écart', align: 'right', className: 'mono', render: (row) => fcfa(row.ecart) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'actions', header: 'Actions', render: (row) => (
            <span className="cluster">
                <Button size="sm" to={`/recettes/previsions/${row.id}`}>Voir</Button>
                {portrait?.droits?.editer && row.statut === 'brouillon' && <Button size="sm" loading={pending === `del${row.id}`} onClick={() => supprimer(row)}>Supprimer</Button>}
            </span>
        ) },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Prévisions de recettes" subtitle="Montants prévus par nature, avant la constatation d’une recette." actions={portrait?.droits?.editer && <Button variant="primary" icon={ICON.create} onClick={() => setModal(true)}>Nouvelle prévision</Button>} />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <FilterBar onReset={() => { setStatut(''); setQ(''); setPage(1); }}>
                <input className="inp" placeholder="Code, libellé, source" value={q} onChange={(event) => setQ(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); load(1); } }} />
                <FilterSelect label="Statut" value={statut} onChange={(value) => { setStatut(value); setPage(1); }} options={[
                    { value: 'brouillon', label: 'Brouillon' },
                    { value: 'soumis', label: 'Soumise' },
                    { value: 'valide', label: 'Validée' },
                    { value: 'annule', label: 'Annulée' },
                ]} />
            </FilterBar>
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.budget} title="Aucune prévision" />} />
            </SectionCard>
            <Pagination meta={portrait?.meta} onPage={setPage} noun="prévision" />
            {modal && (
                <Modal title="Nouvelle prévision" onClose={() => setModal(false)} footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="prev-form" loading={pending === 'creer'}>Enregistrer</Button></>}>
                    <form id="prev-form" className="form-grid" onSubmit={creer}>
                        <FormField label="Exercice" required><select className="inp" required value={form.exercice_id} onChange={(event) => setForm({ ...form, exercice_id: event.target.value })}><option value="">Choisir</option>{(ref?.exercices ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.annee}</option>)}</select></FormField>
                        <FormField label="Catégorie" required><select className="inp" required value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })}><option value="">Choisir</option>{(ref?.categories ?? []).filter((row: any) => row.active).map((row: any) => <option key={row.id} value={row.id}>{row.label}</option>)}</select></FormField>
                        <FormField label="Libellé" required className="span-all"><input className="inp" required value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
                        <FormField label="Montant prévu" required hint="En francs CFA, sans décimale."><input className="inp" required inputMode="numeric" value={form.montant} onChange={(event) => setForm({ ...form, montant: event.target.value })} /></FormField>
                        <FormField label="Source attendue"><input className="inp" value={form.source_label} onChange={(event) => setForm({ ...form, source_label: event.target.value })} /></FormField>
                        <FormField label="Période"><input className="inp" value={form.periode} onChange={(event) => setForm({ ...form, periode: event.target.value })} /></FormField>
                        <FormField label="Date prévue"><input className="inp" type="date" value={form.date_prevue} onChange={(event) => setForm({ ...form, date_prevue: event.target.value })} /></FormField>
                        <FormField label="Structure responsable"><select className="inp" value={form.organization_unit_id} onChange={(event) => setForm({ ...form, organization_unit_id: event.target.value })}><option value="">Aucune</option>{(ref?.services ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.sigle} · {row.name}</option>)}</select></FormField>
                        <FormField label="Observations" className="span-all"><textarea className="inp" value={form.observations} onChange={(event) => setForm({ ...form, observations: event.target.value })} /></FormField>
                    </form>
                </Modal>
            )}
        </main>
    );
}
