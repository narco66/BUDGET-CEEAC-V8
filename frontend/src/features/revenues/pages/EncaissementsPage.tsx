import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Link } from 'react-router-dom';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, Modal, PageHeader, Pagination, SectionCard, StatusBadge, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

export default function EncaissementsPage() {
    const toast = useToast();
    const [portrait, setPortrait] = useState<any>(null);
    const [creances, setCreances] = useState<any[]>([]);
    const [modes, setModes] = useState<any[]>([]);
    const [error, setError] = useState('');
    const [modal, setModal] = useState(false);
    const [pending, setPending] = useState(false);
    const [page, setPage] = useState(1);
    const [form, setForm] = useState({ recu_le: '', montant: '', mode: 'virement', order_id: '', banque: '', reference_bancaire: '', commentaire: '' });
    const [affectation, setAffectation] = useState<{ receipt: any; order_id: string; montant: string } | null>(null);

    function load(nextPage = page) {
        api.get('/recettes/encaissements', { params: { page: nextPage } }).then((response) => { setPortrait(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
    }
    useEffect(() => {
        load();
    }, [page]);
    useEffect(() => {
        api.get('/recettes/creances').then((response) => setCreances(response.data.data ?? [])).catch(() => setCreances([]));
        api.get('/recettes/referentiel').then((response) => setModes(response.data.modes ?? [])).catch(() => setModes([]));
    }, []);

    async function creer(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        const montant = Number(form.montant);
        const creance = creances.find((row) => String(row.id) === form.order_id);
        const affecte = creance ? Math.min(montant, Number(creance.solde)) : 0;
        const trop = form.order_id ? montant - affecte : 0;
        try {
            await api.post('/recettes/encaissements', {
                recu_le: form.recu_le,
                montant,
                mode: form.mode,
                banque: form.banque || null,
                reference_bancaire: form.reference_bancaire || null,
                commentaire: form.commentaire || null,
                allocations: affecte > 0 ? [{ order_id: Number(form.order_id), montant: affecte }] : [],
                trop_percu: trop > 0 ? { kind: 'avance', montant: trop, order_id: Number(form.order_id) } : undefined,
            });
            setModal(false);
            toast.success('Encaissement enregistré.');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    /** Régularisation d’un encaissement non identifié : imputation sur un titre, l’excédent devient une avance. */
    async function affecter(event: FormEvent) {
        event.preventDefault();
        if (!affectation) return;
        const montant = Number(affectation.montant);
        const creance = creances.find((row) => String(row.id) === affectation.order_id);
        const impute = creance ? Math.min(montant, Number(creance.solde)) : 0;
        const trop = montant - impute;
        setPending(true);
        try {
            await api.post(`/recettes/encaissements/${affectation.receipt.id}/affecter`, {
                allocations: impute > 0 ? [{ order_id: Number(affectation.order_id), montant: impute }] : [],
                trop_percu: trop > 0 ? { kind: 'avance', montant: trop, order_id: Number(affectation.order_id) } : undefined,
            });
            setAffectation(null);
            toast.success(`Encaissement ${affectation.receipt.reference} affecté.`);
            load();
            api.get('/recettes/creances').then((response) => setCreances(response.data.data ?? [])).catch(() => undefined);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'date', header: 'Date', render: (row) => row.date },
        { key: 'montant', header: 'Montant', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'affecte', header: 'Affecté', align: 'right', className: 'mono', render: (row) => fcfa(row.affecte) },
        { key: 'mode', header: 'Mode', render: (row) => row.mode },
        { key: 'titres', header: 'Recettes', render: (row) => (row.titres ?? []).length ? (row.titres as { id: number; reference: string }[]).map((titre) => <Link key={titre.id} to={`/recettes/titres/${titre.id}`}>{titre.reference} </Link>) : 'Non identifié' },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            align: 'right',
            render: (row) => portrait?.droits?.encaisser && ['non_identifie', 'non_rapproche'].includes(row.statut) && Number(row.affecte) < Number(row.montant) ? (
                <Button size="sm" icon={ICON.transform} onClick={() => setAffectation({ receipt: row, order_id: '', montant: String(Number(row.montant) - Number(row.affecte)) })}>Affecter</Button>
            ) : null,
        },
    ];

    return (
        <main className="app-content">
            <PageHeader title="Encaissements" subtitle="Recettes perçues, affectées à un titre ou laissées non identifiées." actions={portrait?.droits?.encaisser && <Button variant="primary" icon={ICON.create} onClick={() => setModal(true)}>Nouvel encaissement</Button>} />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard flush>
                <DataTable columns={columns} rows={portrait?.data ?? []} rowKey={(row) => row.id} loading={!portrait && !error} empty={<EmptyState icon={ICON.payment} title="Aucun encaissement" />} />
            </SectionCard>
            <Pagination meta={portrait?.meta} onPage={setPage} noun="encaissement" />
            {affectation && (
                <Modal
                    title={`Affecter ${affectation.receipt.reference}`}
                    icon={ICON.transform}
                    onClose={() => setAffectation(null)}
                    footer={<><Button onClick={() => setAffectation(null)}>Annuler</Button><Button variant="primary" type="submit" form="affectation-form" loading={pending} disabled={!affectation.order_id}>Affecter</Button></>}
                >
                    <form id="affectation-form" className="form-grid" onSubmit={affecter}>
                        <FormField label="Titre de recette" required className="span-all">
                            <select className="inp" required value={affectation.order_id} onChange={(event) => setAffectation({ ...affectation, order_id: event.target.value })}>
                                <option value="">Choisir</option>
                                {creances.map((row) => <option key={row.id} value={row.id}>{row.reference} · solde {fcfa(row.solde)}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Montant à affecter (FCFA)" required hint={`Reste non affecté : ${fcfa(Number(affectation.receipt.montant) - Number(affectation.receipt.affecte))} FCFA. L’excédent sur le solde du titre est enregistré comme avance.`}>
                            <input className="inp num" required inputMode="numeric" value={affectation.montant} onChange={(event) => setAffectation({ ...affectation, montant: event.target.value.replace(/\D/g, '') })} />
                        </FormField>
                    </form>
                </Modal>
            )}
            {modal && (
                <Modal title="Encaissement" onClose={() => setModal(false)} footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="enc-form" loading={pending}>Enregistrer</Button></>}>
                    <form id="enc-form" className="form-grid" onSubmit={creer}>
                        <FormField label="Date" required><input className="inp" required type="date" value={form.recu_le} onChange={(event) => setForm({ ...form, recu_le: event.target.value })} /></FormField>
                        <FormField label="Montant" required><input className="inp" required inputMode="numeric" value={form.montant} onChange={(event) => setForm({ ...form, montant: event.target.value })} /></FormField>
                        <FormField label="Mode" required><select className="inp" value={form.mode} onChange={(event) => setForm({ ...form, mode: event.target.value })}>{modes.filter((row) => row.active !== false).map((row) => <option key={row.code} value={row.code}>{row.label}</option>)}</select></FormField>
                        <FormField label="Titre" hint="Laisser vide pour un encaissement non identifié."><select className="inp" value={form.order_id} onChange={(event) => setForm({ ...form, order_id: event.target.value })}><option value="">Non identifié</option>{creances.map((row) => <option key={row.id} value={row.id}>{row.reference} · solde {fcfa(row.solde)}</option>)}</select></FormField>
                        <FormField label="Banque"><input className="inp" value={form.banque} onChange={(event) => setForm({ ...form, banque: event.target.value })} /></FormField>
                        <FormField label="Référence bancaire"><input className="inp" value={form.reference_bancaire} onChange={(event) => setForm({ ...form, reference_bancaire: event.target.value })} /></FormField>
                    </form>
                </Modal>
            )}
        </main>
    );
}
