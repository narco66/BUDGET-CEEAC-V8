import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, Modal, PageHeader, SectionCard, StatusBadge, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function ContributionsPage() {
    const toast = useToast();
    const [portrait, setPortrait] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [error, setError] = useState('');
    const [modal, setModal] = useState(false);
    const [pending, setPending] = useState<string | null>(null);
    const [form, setForm] = useState({ exercice_id: '', member_state_id: '', quote_part: '', montant_attendu: '', echeance: '' });

    function load() {
        api.get('/recettes/contributions').then((response) => { setPortrait(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
    }
    useEffect(() => { load(); api.get('/recettes/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    async function creer(event: FormEvent) {
        event.preventDefault();
        setPending('creer');
        try {
            await api.post('/recettes/contributions', { ...form, exercice_id: Number(form.exercice_id), member_state_id: Number(form.member_state_id), quote_part: Number(form.quote_part), montant_attendu: Number(form.montant_attendu) });
            setModal(false);
            toast.success('Contribution enregistrée.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const columns: Column<any>[] = [
        { key: 'etat', header: 'État membre', render: (row) => row.etat },
        { key: 'quote', header: 'Quote-part', render: (row) => `${(row.quote_part / 100).toLocaleString('fr-FR')} %` },
        { key: 'attendu', header: 'Attendu', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_attendu) },
        { key: 'appele', header: 'Appelé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_appele) },
        { key: 'encaisse', header: 'Encaissé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_encaisse) },
        { key: 'solde', header: 'Solde', align: 'right', className: 'mono', render: (row) => fcfa(row.solde) },
        { key: 'echeance', header: 'Échéance', render: (row) => row.echeance || '—' },
        { key: 'relances', header: 'Relances', render: (row) => row.relances },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        { key: 'actions', header: 'Actions', render: (row) => row.reference ? <Link to={`/recettes/titres/${row.order_id}`}>{row.reference}</Link> : portrait?.droits?.editer && <Button size="sm" loading={pending === `a${row.id}`} onClick={() => { setPending(`a${row.id}`); api.post(`/recettes/contributions/${row.id}/appeler`).then(() => { toast.success('Appel créé.'); load(); }).catch((caught) => setError(errorsOf(caught))).finally(() => setPending(null)); }}>Appeler</Button> },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Contributions des États membres" subtitle={portrait?.exercice ? `Exercice ${portrait.exercice.annee}` : 'Quote-parts, appels et encaissements.'} actions={portrait?.droits?.editer && <Button variant="primary" icon={ICON.create} onClick={() => setModal(true)}>Nouvelle contribution</Button>} />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.users} title="Aucune contribution" />} />
            </SectionCard>
            {modal && (
                <Modal title="Contribution" onClose={() => setModal(false)} footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="contrib-form" loading={pending === 'creer'}>Enregistrer</Button></>}>
                    <form id="contrib-form" className="form-grid" onSubmit={creer}>
                        <FormField label="Exercice" required><select className="inp" required value={form.exercice_id} onChange={(event) => setForm({ ...form, exercice_id: event.target.value })}><option value="">Choisir</option>{(ref?.exercices ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.annee}</option>)}</select></FormField>
                        <FormField label="État membre" required><select className="inp" required value={form.member_state_id} onChange={(event) => setForm({ ...form, member_state_id: event.target.value })}><option value="">Choisir</option>{(ref?.etats ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.nom}</option>)}</select></FormField>
                        <FormField label="Quote-part" required hint="Centièmes de pourcent : 1250 = 12,50 %."><input className="inp" required inputMode="numeric" value={form.quote_part} onChange={(event) => setForm({ ...form, quote_part: event.target.value })} /></FormField>
                        <FormField label="Montant attendu" required><input className="inp" required inputMode="numeric" value={form.montant_attendu} onChange={(event) => setForm({ ...form, montant_attendu: event.target.value })} /></FormField>
                        <FormField label="Échéance"><input className="inp" type="date" value={form.echeance} onChange={(event) => setForm({ ...form, echeance: event.target.value })} /></FormField>
                    </form>
                </Modal>
            )}
        </main>
    );
}
