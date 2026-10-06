import { FormEvent, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, DocumentList, ErrorMessage, FileDrop, FormField, ICON, InfoGrid, PageHeader, SectionCard, StatusBadge, useToast, WorkflowTimeline, type Column } from '../../../components/ui';
import { errorsOf, fcfa, libelleCode, resumeDetails } from '../../../utils/format';
import { telecharger } from '../download';

export default function TitreFichePage() {
    const { id } = useParams();
    const toast = useToast();
    const [fiche, setFiche] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [motif, setMotif] = useState('');
    const [relance, setRelance] = useState({ kind: 'premiere', canal: 'courriel', destinataire: '', resultat: '' });
    const [encaissement, setEncaissement] = useState({ montant: '', mode: 'virement', recu_le: '' });
    const [avoir, setAvoir] = useState('');
    const [piece, setPiece] = useState<File | null>(null);
    const [edition, setEdition] = useState(false);
    const [edit, setEdit] = useState({ montant: '', echeance: '', motif: '', description: '', observations: '' });

    function load() {
        api.get(`/recettes/titres/${id}`).then((response) => {
            setFiche(response.data);
            const row = response.data.data;
            setEdit({ montant: String(row?.montant ?? ''), echeance: row?.echeance ?? '', motif: row?.motif ?? '', description: row?.description ?? '', observations: row?.observations ?? '' });
            setError('');
        }).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [id]);

    async function agir(cle: string, request: () => Promise<unknown>, message: string) {
        setPending(cle);
        setError('');
        try {
            await request();
            toast.success(message);
            setMotif('');
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    const data = fiche?.data;
    const droits = fiche?.droits ?? {};
    const banniere = data?.banniere ?? {};
    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', className: 'mono', render: (row) => row.reference },
        { key: 'date', header: 'Date', render: (row) => row.date },
        { key: 'montant', header: 'Montant', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
        { key: 'mode', header: 'Mode', render: (row) => row.mode },
        { key: 'agent', header: 'Agent', render: (row) => row.agent },
    ];

    async function payer(event: FormEvent) {
        event.preventDefault();
        const montant = Number(encaissement.montant);
        const solde = Number(data.solde);
        const affecte = Math.min(montant, solde);
        const trop = montant - affecte;
        await agir('payer', () => api.post('/recettes/encaissements', {
            recu_le: encaissement.recu_le,
            montant,
            mode: encaissement.mode,
            allocations: affecte > 0 ? [{ order_id: data.id, montant: affecte }] : [],
            trop_percu: trop > 0 ? { kind: 'avance', montant: trop, order_id: data.id } : undefined,
        }), 'Encaissement enregistré.');
    }

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/recettes/titres', label: 'Recettes' }}
                title={data?.reference || 'Titre de recette'}
                subtitle={data?.motif}
                eyebrow={data && <StatusBadge statut={data.statut} libelle={data.statut_libelle} />}
                figure={data ? { label: 'Solde à recouvrer', value: fcfa(data.solde), unit: 'FCFA' } : undefined}
                actions={data && (
                    <>
                        <Button icon={ICON.pdf} onClick={() => telecharger(`/recettes/titres/${data.id}/document`, `${data.reference}.pdf`, { kind: 'titre' }).catch((caught) => setError(errorsOf(caught)))}>Titre PDF</Button>
                        <Button icon={ICON.pdf} onClick={() => telecharger(`/recettes/titres/${data.id}/document`, `${data.reference}-appel.pdf`, { kind: 'appel' }).catch((caught) => setError(errorsOf(caught)))}>Appel de fonds</Button>
                    </>
                )}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            {data && (
                <SectionCard tone="brand">
                    <div className="split">
                        <div className="stack-sm">
                            <span className="lbl" style={{ color: 'var(--navy-200)' }}>Étape actuelle</span>
                            <strong style={{ fontSize: 'var(--text-lg)', color: '#fff' }}>{banniere.etape}</strong>
                            <span style={{ color: '#D1E0F6' }}>Dernière action : {banniere.derniere_action || 'aucune'} · {banniere.dernier_acteur || '—'} · {banniere.date || ''}</span>
                        </div>
                        <div className="stack-sm" style={{ textAlign: 'right' }}>
                            <span className="lbl" style={{ color: 'var(--navy-200)' }}>Prochaine étape</span>
                            <strong style={{ color: '#fff' }}>{banniere.prochaine_etape || 'Aucune'}</strong>
                            <span style={{ color: '#D1E0F6' }}>Acteur attendu : {banniere.acteur_attendu || '—'}</span>
                        </div>
                    </div>
                </SectionCard>
            )}
            {data && (
                <div className="cluster">
                    {droits.editer && ['brouillon', 'rejete'].includes(data.statut) && <Button onClick={() => setEdition((value) => !value)}>{edition ? 'Fermer' : 'Modifier'}</Button>}
                    {droits.editer && ['brouillon', 'rejete'].includes(data.statut) && <Button variant="primary" loading={pending === 'soumettre'} onClick={() => agir('soumettre', () => api.post(`/recettes/titres/${data.id}/soumettre`), 'Recette soumise.')}>Soumettre</Button>}
                    {droits.verifier && data.statut === 'soumis' && <Button variant="primary" loading={pending === 'verifier'} onClick={() => agir('verifier', () => api.post(`/recettes/titres/${data.id}/verifier`), 'Titre vérifié.')}>Vérifier</Button>}
                    {droits.decider && data.statut === 'verifie' && <Button variant="primary" loading={pending === 'valider'} onClick={() => agir('valider', () => api.post(`/recettes/titres/${data.id}/valider`), 'Titre validé.')}>Valider</Button>}
                    {droits.encaisser && data.statut === 'valide' && <Button variant="primary" loading={pending === 'prendre'} onClick={() => agir('prendre', () => api.post(`/recettes/titres/${data.id}/prendre-en-charge`), 'Titre pris en charge.')}>Prendre en charge</Button>}
                    {(droits.verifier || droits.decider) && ['soumis', 'verifie'].includes(data.statut) && <Button loading={pending === 'rejeter'} onClick={() => agir('rejeter', () => api.post(`/recettes/titres/${data.id}/rejeter`, { motif }), 'Titre rejeté.')}>Rejeter</Button>}
                    {droits.decider && ['pris_en_charge', 'partiellement_encaisse'].includes(data.statut) && <Button loading={pending === 'suspendre'} onClick={() => agir('suspendre', () => api.post(`/recettes/titres/${data.id}/suspendre`, { motif }), 'Titre suspendu.')}>Suspendre</Button>}
                    {droits.decider && data.statut === 'suspendu' && <Button loading={pending === 'reprendre'} onClick={() => agir('reprendre', () => api.post(`/recettes/titres/${data.id}/reprendre`), 'Titre repris.')}>Reprendre</Button>}
                    {droits.decider && data.encaisse === 0 && !['solde', 'annule'].includes(data.statut) && <Button loading={pending === 'annuler'} onClick={() => agir('annuler', () => api.post(`/recettes/titres/${data.id}/annuler`, { motif }), 'Titre annulé.')}>Annuler</Button>}
                </div>
            )}
            {data && ['soumis', 'verifie', 'pris_en_charge', 'partiellement_encaisse'].includes(data.statut) && droits.decider && (
                <FormField label="Motif de rejet, suspension ou annulation"><input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} /></FormField>
            )}
            {data && (
                <InfoGrid items={[
                    { label: 'Exercice', value: data.annee },
                    { label: 'Nature', value: data.categorie },
                    { label: 'Débiteur', value: data.debiteur },
                    { label: 'Constaté', value: fcfa(data.montant) },
                    { label: 'Encaissé', value: fcfa(data.encaisse) },
                    { label: 'Solde', value: fcfa(data.solde) },
                    { label: 'Échéance', value: data.echeance },
                    { label: 'Retard', value: data.jours_retard ? `${data.jours_retard} j` : 'Aucun' },
                    { label: 'Prévision', value: data.prevision ? <Link to={`/recettes/previsions/${data.forecast_id}`}>{data.prevision}</Link> : '—' },
                    { label: 'Auteur', value: data.auteur },
                    { label: 'Vérificateur', value: data.verificateur || '—' },
                    { label: 'Validateur', value: data.validateur || '—' },
                ]} />
            )}
            {data && edition && (
                <SectionCard title="Modifier la recette">
                    <form className="form-grid" onSubmit={(event) => { event.preventDefault(); agir('sauver', () => api.patch(`/recettes/titres/${data.id}`, { ...edit, montant: Number(edit.montant) }), 'Recette mise à jour.'); }}>
                        <FormField label="Montant" required><input className="inp" required inputMode="numeric" value={edit.montant} onChange={(event) => setEdit({ ...edit, montant: event.target.value })} /></FormField>
                        <FormField label="Échéance" required><input className="inp" required type="date" value={edit.echeance} onChange={(event) => setEdit({ ...edit, echeance: event.target.value })} /></FormField>
                        <FormField label="Motif" required className="span-all"><input className="inp" required value={edit.motif} onChange={(event) => setEdit({ ...edit, motif: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={edit.description} onChange={(event) => setEdit({ ...edit, description: event.target.value })} /></FormField>
                        <FormField label="Observations" className="span-all"><textarea className="inp" value={edit.observations} onChange={(event) => setEdit({ ...edit, observations: event.target.value })} /></FormField>
                        <Button variant="primary" type="submit" loading={pending === 'sauver'}>Enregistrer</Button>
                    </form>
                </SectionCard>
            )}
            {data && droits.encaisser && ['pris_en_charge', 'partiellement_encaisse'].includes(data.statut) && (
                <SectionCard title="Encaisser" icon={ICON.payment}>
                    <form className="form-grid" onSubmit={payer}>
                        <FormField label="Date" required><input className="inp" required type="date" value={encaissement.recu_le} onChange={(event) => setEncaissement({ ...encaissement, recu_le: event.target.value })} /></FormField>
                        <FormField label="Montant" required hint="Un excédent est conservé comme avance."><input className="inp" required inputMode="numeric" value={encaissement.montant} onChange={(event) => setEncaissement({ ...encaissement, montant: event.target.value })} /></FormField>
                        <FormField label="Mode"><input className="inp" value={encaissement.mode} onChange={(event) => setEncaissement({ ...encaissement, mode: event.target.value })} /></FormField>
                        <Button variant="primary" type="submit" loading={pending === 'payer'}>Enregistrer l’encaissement</Button>
                    </form>
                </SectionCard>
            )}
            {data && droits.decider && ['pris_en_charge', 'partiellement_encaisse', 'solde'].includes(data.statut) && (
                <SectionCard title="Avoir" icon={ICON.return}>
                    <form className="cluster" onSubmit={(event) => { event.preventDefault(); agir('avoir', () => api.post(`/recettes/titres/${data.id}/regulariser`, { kind: 'avoir', montant: Number(avoir), motif: motif || 'Avoir' }), 'Avoir enregistré.'); }}>
                        <FormField label="Montant de l’avoir"><input className="inp" required inputMode="numeric" value={avoir} onChange={(event) => setAvoir(event.target.value)} /></FormField>
                        <Button type="submit" loading={pending === 'avoir'}>Régulariser</Button>
                    </form>
                </SectionCard>
            )}
            <SectionCard title="Encaissements" icon={ICON.payment} flush>
                <DataTable columns={columns} rows={data?.encaissements ?? []} rowKey={(row) => row.reference} empty={<span className="subtle">Aucun encaissement.</span>} />
            </SectionCard>
            {data && droits.relancer && ['pris_en_charge', 'partiellement_encaisse', 'suspendu'].includes(data.statut) && (
                <SectionCard title="Relancer" icon={ICON.comment}>
                    <form className="form-grid" onSubmit={(event) => { event.preventDefault(); agir('relance', () => api.post(`/recettes/titres/${data.id}/relances`, relance), 'Relance enregistrée.'); }}>
                        <FormField label="Type"><select className="inp" value={relance.kind} onChange={(event) => setRelance({ ...relance, kind: event.target.value })}><option value="premiere">Première relance</option><option value="deuxieme">Deuxième relance</option><option value="mise_en_demeure">Mise en demeure</option><option value="rappel_institutionnel">Rappel institutionnel</option><option value="personnalisee">Personnalisée</option></select></FormField>
                        <FormField label="Canal"><input className="inp" value={relance.canal} onChange={(event) => setRelance({ ...relance, canal: event.target.value })} /></FormField>
                        <FormField label="Destinataire" required><input className="inp" required value={relance.destinataire} onChange={(event) => setRelance({ ...relance, destinataire: event.target.value })} /></FormField>
                        <FormField label="Résultat"><input className="inp" value={relance.resultat} onChange={(event) => setRelance({ ...relance, resultat: event.target.value })} /></FormField>
                        <Button type="submit" loading={pending === 'relance'}>Enregistrer la relance</Button>
                    </form>
                </SectionCard>
            )}
            <SectionCard title="Pièces" icon={ICON.attachment}>
                <DocumentList items={(data?.pieces ?? []).map((row: any) => ({ name: row.nom, meta: row.type }))} />
                {droits.editer && data && (
                    <form className="stack" onSubmit={(event) => {
                        event.preventDefault();
                        if (!piece) return;
                        const body = new FormData();
                        body.append('fichier', piece);
                        body.append('type_piece', 'justificatif');
                        agir('piece', () => api.post(`/recettes/titres/${data.id}/pieces`, body).then(() => setPiece(null)), 'Pièce ajoutée.');
                    }}>
                        <FormField label="Ajouter une pièce justificative">
                            <FileDrop file={piece} onFile={setPiece} hint="PDF ou image, 10 Mo maximum." />
                        </FormField>
                        <div className="form-actions">
                            <Button type="submit" variant="primary" icon={ICON.attachment} loading={pending === 'piece'} disabled={!piece}>Joindre la pièce</Button>
                        </div>
                    </form>
                )}
            </SectionCard>
            <SectionCard title="Historique" icon={ICON.history}>
                <WorkflowTimeline events={(data?.historique ?? []).map((row: any) => ({ action: libelleCode(row.action), actor: row.acteur, date: row.date, detail: resumeDetails(row.apres) }))} />
            </SectionCard>
        </main>
    );
}
