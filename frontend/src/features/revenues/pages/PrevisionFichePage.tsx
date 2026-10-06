import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, ErrorMessage, FormField, ICON, InfoGrid, PageHeader, SectionCard, StatusBadge, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function PrevisionFichePage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { confirm } = useDialogs();
    const [fiche, setFiche] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [edition, setEdition] = useState(false);
    const [form, setForm] = useState<any>({});
    const [motif, setMotif] = useState('');

    function load() {
        api.get(`/recettes/previsions/${id}`).then((response) => {
            setFiche(response.data);
            const data = response.data.data;
            setForm({
                category_id: String(data.category_id ?? ''),
                organization_unit_id: String(data.organization_unit_id ?? ''),
                label: data.libelle ?? '',
                description: data.description ?? '',
                montant: String(data.montant ?? ''),
                source_label: data.source ?? '',
                periode: data.periode ?? '',
                date_prevue: data.date_prevue ?? '',
                observations: data.observations ?? '',
            });
            setError('');
        }).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [id]);
    useEffect(() => { api.get('/recettes/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    async function agir(cle: string, request: () => Promise<unknown>, message: string) {
        setPending(cle);
        try {
            await request();
            toast.success(message);
            setEdition(false);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        await agir('sauver', () => api.patch(`/recettes/previsions/${id}`, {
            ...form,
            category_id: Number(form.category_id),
            organization_unit_id: form.organization_unit_id ? Number(form.organization_unit_id) : null,
            montant: Number(form.montant),
        }), 'Prévision mise à jour.');
    }

    async function supprimer() {
        const ok = await confirm({ title: 'Supprimer la prévision', description: 'Le brouillon sera retiré. Cette action est définitive.', confirmLabel: 'Supprimer', tone: 'danger', icon: ICON.archive });
        if (!ok) {
            return;
        }
        setPending('supprimer');
        try {
            await api.delete(`/recettes/previsions/${id}`);
            toast.success('Prévision supprimée.');
            navigate('/recettes/previsions');
        } catch (caught) {
            setError(errorsOf(caught));
            setPending(null);
        }
    }

    const data = fiche?.data;
    const droits = fiche?.droits ?? {};
    const recettes: Column<any>[] = [
        { key: 'reference', header: 'Recette', className: 'mono', render: (row) => <Link to={`/recettes/titres/${row.id}`}>{row.reference}</Link> },
        { key: 'debiteur', header: 'Débiteur', render: (row) => row.debiteur },
        { key: 'montant', header: 'Constaté', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'encaisse', header: 'Encaissé', align: 'right', className: 'mono', render: (row) => fcfa(row.encaisse) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/recettes/previsions', label: 'Prévisions' }}
                title={data?.code || 'Prévision'}
                subtitle={data?.libelle}
                eyebrow={data && <StatusBadge statut={data.statut} libelle={data.statut_libelle} />}
                figure={data ? { label: 'Écart prévu / encaissé', value: fcfa(data.ecart), unit: 'FCFA' } : undefined}
                actions={data && (
                    <>
                        {droits.editer && data.statut === 'valide' && <Button variant="primary" icon={ICON.create} to={`/recettes/titres?prevision=${data.id}`}>Créer la recette</Button>}
                        {droits.editer && data.statut === 'brouillon' && <Button variant="primary" loading={pending === 'soumettre'} onClick={() => agir('soumettre', () => api.post(`/recettes/previsions/${data.id}/soumettre`), 'Prévision soumise.')}>Soumettre</Button>}
                        {droits.decider && data.statut === 'soumis' && <Button variant="primary" loading={pending === 'valider'} onClick={() => agir('valider', () => api.post(`/recettes/previsions/${data.id}/valider`), 'Prévision validée.')}>Valider</Button>}
                        {droits.editer && data.statut === 'brouillon' && <Button onClick={() => setEdition((value) => !value)}>{edition ? 'Fermer' : 'Modifier'}</Button>}
                        {droits.editer && data.statut === 'brouillon' && <Button loading={pending === 'supprimer'} onClick={supprimer}>Supprimer</Button>}
                    </>
                )}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            {data && (
                <>
                    <SectionCard title="Identification">
                        <InfoGrid items={[
                            { label: 'Exercice', value: data.annee },
                            { label: 'Catégorie', value: data.categorie },
                            { label: 'Source', value: data.source || '—' },
                            { label: 'Période', value: data.periode || '—' },
                            { label: 'Date prévue', value: data.date_prevue || '—' },
                            { label: 'Structure', value: data.structure_nom || data.structure || '—' },
                            { label: 'Auteur', value: data.auteur || '—' },
                            { label: 'Montant prévu', value: fcfa(data.montant) },
                            { label: 'Encaissé', value: fcfa(data.realise) },
                        ]} />
                        {data.description && <p>{data.description}</p>}
                        {data.observations && <p>{data.observations}</p>}
                    </SectionCard>
                    {edition && (
                        <SectionCard title="Modifier la prévision" icon={ICON.edit}>
                            <form className="form-grid" onSubmit={enregistrer}>
                                <FormField label="Catégorie" required><select className="inp" required value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })}>{(ref?.categories ?? []).filter((row: any) => row.active).map((row: any) => <option key={row.id} value={row.id}>{row.label}</option>)}</select></FormField>
                                <FormField label="Montant" required><input className="inp" required inputMode="numeric" value={form.montant} onChange={(event) => setForm({ ...form, montant: event.target.value })} /></FormField>
                                <FormField label="Libellé" required className="span-all"><input className="inp" required value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} /></FormField>
                                <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
                                <FormField label="Source"><input className="inp" value={form.source_label} onChange={(event) => setForm({ ...form, source_label: event.target.value })} /></FormField>
                                <FormField label="Période"><input className="inp" value={form.periode} onChange={(event) => setForm({ ...form, periode: event.target.value })} /></FormField>
                                <FormField label="Date prévue"><input className="inp" type="date" value={form.date_prevue} onChange={(event) => setForm({ ...form, date_prevue: event.target.value })} /></FormField>
                                <FormField label="Structure"><select className="inp" value={form.organization_unit_id} onChange={(event) => setForm({ ...form, organization_unit_id: event.target.value })}><option value="">Aucune</option>{(ref?.services ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.sigle}</option>)}</select></FormField>
                                <FormField label="Observations" className="span-all"><textarea className="inp" value={form.observations} onChange={(event) => setForm({ ...form, observations: event.target.value })} /></FormField>
                                <Button variant="primary" type="submit" loading={pending === 'sauver'}>Enregistrer</Button>
                            </form>
                        </SectionCard>
                    )}
                    {droits.editer && data.statut === 'soumis' && (
                        <SectionCard title="Annuler la soumission">
                            <form className="cluster" onSubmit={(event) => { event.preventDefault(); agir('annuler', () => api.post(`/recettes/previsions/${data.id}/annuler`, { motif }), 'Prévision annulée.'); }}>
                                <input className="inp" required placeholder="Motif d’annulation" value={motif} onChange={(event) => setMotif(event.target.value)} />
                                <Button type="submit" loading={pending === 'annuler'}>Annuler</Button>
                            </form>
                        </SectionCard>
                    )}
                    <SectionCard title="Recettes constatées" flush>
                        <DataTable columns={recettes} rows={data.recettes ?? []} rowKey={(row) => row.id} empty={<p className="muted">Aucune recette n’est encore rattachée.</p>} />
                    </SectionCard>
                    <SectionCard title="Historique" flush>
                        <DataTable columns={[
                            { key: 'date', header: 'Date', render: (row: any) => row.date },
                            { key: 'action', header: 'Action', render: (row: any) => row.action },
                            { key: 'acteur', header: 'Acteur', render: (row: any) => row.acteur || '—' },
                        ]} rows={data.historique ?? []} rowKey={(row: any) => `${row.date}-${row.action}`} />
                    </SectionCard>
                </>
            )}
        </main>
    );
}
