import { FormEvent, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FilterBar, FilterSelect, FormField, ICON, Modal, PageHeader, Pagination, SectionCard, StatusBadge, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function TitresPage() {
    const toast = useToast();
    const [portrait, setPortrait] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [modal, setModal] = useState(false);
    const [page, setPage] = useState(1);
    const [statut, setStatut] = useState('');
    const [q, setQ] = useState('');
    const [pending, setPending] = useState(false);
    const [previsions, setPrevisions] = useState<any[]>([]);
    const [params] = useSearchParams();
    const [form, setForm] = useState({ exercice_id: '', category_id: '', forecast_id: '', debtor_type: 'partenaire', debtor_label: '', member_state_id: '', montant: '', echeance: '', motif: '', description: '' });

    function load() {
        api.get('/recettes/titres', { params: { page, statut: statut || undefined, q: q || undefined } })
            .then((response) => { setPortrait(response.data); setError(''); })
            .catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [page, statut]);
    useEffect(() => {
        api.get('/recettes/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null));
        api.get('/recettes/previsions', { params: { statut: 'valide', per_page: 100 } }).then((response) => {
            const rows = response.data.data ?? [];
            setPrevisions(rows);
            const demandee = params.get('prevision');
            const prevision = rows.find((row: any) => String(row.id) === demandee);
            if (prevision) {
                setForm((current) => ({
                    ...current,
                    forecast_id: String(prevision.id),
                    exercice_id: String(prevision.exercice_id),
                    category_id: String(prevision.category_id),
                    montant: String(prevision.montant),
                    motif: prevision.libelle,
                }));
                setModal(true);
            }
        }).catch(() => setPrevisions([]));
    }, [params]);

    async function creer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        try {
            await api.post('/recettes/titres', {
                ...form,
                exercice_id: Number(form.exercice_id),
                category_id: Number(form.category_id),
                member_state_id: form.member_state_id ? Number(form.member_state_id) : null,
                forecast_id: form.forecast_id ? Number(form.forecast_id) : null,
                montant: Number(form.montant),
            });
            setModal(false);
            toast.success('Titre créé.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => <Link to={`/recettes/titres/${row.id}`}>{row.reference}</Link> },
        { key: 'annee', header: 'Exercice', render: (row) => row.annee },
        { key: 'categorie', header: 'Nature', render: (row) => row.categorie },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'montant', header: 'Constaté', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'encaisse', header: 'Encaissé', align: 'right', className: 'mono', render: (row) => fcfa(row.encaisse) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'echeance', header: 'Échéance', render: (row) => row.echeance },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Recettes" subtitle="Recette constatée : le titre suit le brouillon, la validation, la créance et l’encaissement." actions={portrait?.droits?.editer && <Button variant="primary" icon={ICON.create} onClick={() => setModal(true)}>Nouvelle recette</Button>} />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <FilterBar onReset={() => { setStatut(''); setQ(''); setPage(1); }}>
                <input className="inp" placeholder="Référence, débiteur, motif" value={q} onChange={(event) => setQ(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { setPage(1); load(); } }} />
                <FilterSelect label="Statut" value={statut} onChange={(value) => { setStatut(value); setPage(1); }} options={[
                    { value: 'brouillon', label: 'Brouillon' },
                    { value: 'soumis', label: 'Soumis' },
                    { value: 'verifie', label: 'Vérifié' },
                    { value: 'valide', label: 'Validé' },
                    { value: 'pris_en_charge', label: 'Pris en charge' },
                    { value: 'partiellement_encaisse', label: 'Partiellement encaissé' },
                    { value: 'solde', label: 'Soldé' },
                    { value: 'rejete', label: 'Rejeté' },
                    { value: 'suspendu', label: 'Suspendu' },
                    { value: 'annule', label: 'Annulé' },
                ]} />
            </FilterBar>
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.order} title="Aucun titre" />} />
            </SectionCard>
            <Pagination meta={portrait?.meta} onPage={setPage} noun="titre" />
            {modal && (
                <Modal title="Nouvelle recette" onClose={() => setModal(false)} footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="titre-form" loading={pending}>Enregistrer</Button></>}>
                    <form id="titre-form" className="form-grid" onSubmit={creer}>
                        <FormField label="Prévision" hint="Une prévision validée préremplit l’exercice, la nature et le montant." className="span-all"><select className="inp" value={form.forecast_id} onChange={(event) => {
                            const prevision = previsions.find((row) => String(row.id) === event.target.value);
                            setForm({
                                ...form,
                                forecast_id: event.target.value,
                                exercice_id: prevision ? String(prevision.exercice_id) : form.exercice_id,
                                category_id: prevision ? String(prevision.category_id) : form.category_id,
                                montant: prevision ? String(prevision.montant) : form.montant,
                                motif: prevision ? prevision.libelle : form.motif,
                            });
                        }}><option value="">Sans prévision</option>{previsions.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.libelle}</option>)}</select></FormField>
                        <FormField label="Exercice" required><select className="inp" required value={form.exercice_id} onChange={(event) => setForm({ ...form, exercice_id: event.target.value })}><option value="">Choisir</option>{(ref?.exercices ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.annee}</option>)}</select></FormField>
                        <FormField label="Nature" required><select className="inp" required value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })}><option value="">Choisir</option>{(ref?.categories ?? []).filter((row: any) => row.active).map((row: any) => <option key={row.id} value={row.id}>{row.label}</option>)}</select></FormField>
                        <FormField label="Type de débiteur" required><select className="inp" value={form.debtor_type} onChange={(event) => setForm({ ...form, debtor_type: event.target.value })}>{(ref?.debiteurs ?? []).map((row: any) => <option key={row.value} value={row.value}>{row.label}</option>)}</select></FormField>
                        {form.debtor_type === 'etat_membre'
                            ? <FormField label="État membre" required><select className="inp" required value={form.member_state_id} onChange={(event) => setForm({ ...form, member_state_id: event.target.value })}><option value="">Choisir</option>{(ref?.etats ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.nom}</option>)}</select></FormField>
                            : <FormField label="Débiteur" required><input className="inp" required value={form.debtor_label} onChange={(event) => setForm({ ...form, debtor_label: event.target.value })} /></FormField>}
                        <FormField label="Montant" required><input className="inp" required inputMode="numeric" value={form.montant} onChange={(event) => setForm({ ...form, montant: event.target.value })} /></FormField>
                        <FormField label="Échéance" required><input className="inp" required type="date" value={form.echeance} onChange={(event) => setForm({ ...form, echeance: event.target.value })} /></FormField>
                        <FormField label="Motif" required className="span-all"><input className="inp" required value={form.motif} onChange={(event) => setForm({ ...form, motif: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
                    </form>
                </Modal>
            )}
        </main>
    );
}
