import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FileDrop, FormField, ICON, PageHeader, SectionCard, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { LienPlanification, PreparationNav, StatutPrep } from '../preparation/shell';

const LIGNE = { classification: 'fonctionnement', code: '', label: '', quantite: '1', unite: '', cout_unitaire: '', justification: '', gar_node_id: '', periode: '' };

export default function DossierFichePage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { confirm } = useDialogs();
    const [fiche, setFiche] = useState<any>(null);
    const [ref, setRef] = useState<any>(null);
    const [historique, setHistorique] = useState<any[]>([]);
    const [piece, setPiece] = useState<File | null>(null);
    const [ligne, setLigne] = useState(LIGNE);
    const [edition, setEdition] = useState<number | null>(null);
    const [detail, setDetail] = useState({ designation: '', quantite: '1', cout_unitaire: '', line_id: '' });
    const [periode, setPeriode] = useState({ line_id: '', periode: 'T1', montant: '' });
    const [financement, setFinancement] = useState({ line_id: '', revenue_category_id: '', montant: '' });
    const [arbitrage, setArbitrage] = useState({ line_id: '', montant_retenu: '', decision: 'retenu', motif: '' });
    const [motif, setMotif] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get(`/preparation/dossiers/${id}`).then((response) => { setFiche(response.data.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
        api.get('/preparation/historique', { params: { type: 'dossier', id } }).then((response) => setHistorique(response.data.data ?? [])).catch(() => setHistorique([]));
    }

    useEffect(() => { load(); }, [id]);
    useEffect(() => { api.get('/preparation/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    const editable = fiche && ['brouillon', 'retourne'].includes(fiche.statut);

    async function action(cle: string, request: () => Promise<unknown>, message: string) {
        setPending(cle);
        setError('');
        try {
            await request();
            toast.success(message);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function sauverLigne(event: FormEvent) {
        event.preventDefault();
        const payload = {
            ...ligne,
            quantite: Number(ligne.quantite),
            cout_unitaire: Number(ligne.cout_unitaire),
            gar_node_id: ligne.gar_node_id ? Number(ligne.gar_node_id) : null,
        };
        await action(edition ? `l${edition}` : 'ligne', () => edition
            ? api.patch(`/preparation/lignes/${edition}`, payload)
            : api.post(`/preparation/dossiers/${id}/lignes`, payload), 'Ligne enregistrée.');
        setLigne(LIGNE);
        setEdition(null);
    }

    async function supprimer() {
        const ok = await confirm({ title: 'Supprimer le brouillon', description: 'Le dossier et ses lignes seront retirés.', confirmLabel: 'Supprimer', tone: 'danger', icon: ICON.archive });
        if (!ok) {
            return;
        }
        try {
            await api.delete(`/preparation/dossiers/${id}`);
            toast.success('Proposition supprimée.');
            navigate('/preparation/dossiers');
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    if (!fiche) {
        return <main className="app-content"><ErrorMessage error={error} /></main>;
    }

    return (
        <main className="app-content">
            <PageHeader
                title={fiche.titre}
                subtitle={`${fiche.reference} · ${fiche.campagne} · ${fiche.structure} · ${fcfa(fiche.total)}`}
                actions={(
                    <>
                        <StatutPrep valeur={fiche.statut} />
                        {editable && <Button icon={ICON.edit} to={`/preparation/dossiers/${id}/modifier`}>Modifier</Button>}
                        {editable && <Button variant="primary" icon={ICON.check} loading={pending === 'soumettre'} onClick={() => action('soumettre', () => api.post(`/preparation/dossiers/${id}/soumettre`), 'Proposition soumise.')}>Soumettre</Button>}
                        {fiche.statut === 'brouillon' && <Button onClick={() => void supprimer()}>Supprimer</Button>}
                        <Button loading={pending === 'copie'} onClick={() => action('copie', async () => {
                            const response = await api.post(`/preparation/dossiers/${id}/dupliquer`);
                            navigate(`/preparation/dossiers/${response.data.data.id}`);
                        }, 'Copie créée.')}>Dupliquer</Button>
                    </>
                )}
            />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            {fiche.retour_motif && <p className="subtle">Retour : {fiche.retour_motif}</p>}
            <SectionCard title="Lignes" icon={ICON.budget} flush>
                <DataTable
                    columns={[
                        { key: 'code', header: 'Code', className: 'mono', render: (row) => row.code },
                        { key: 'libelle', header: 'Libellé', render: (row) => row.libelle },
                        { key: 'classification', header: 'Classe', render: (row) => row.classification === 'investissement' ? 'Investissement' : 'Fonctionnement' },
                        { key: 'activite', header: 'Activité', render: (row) => row.activite || '—' },
                        { key: 'montant', header: 'Proposé', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
                        { key: 'retenu', header: 'Retenu', align: 'right', className: 'mono', render: (row) => row.montant_retenu === null ? '—' : fcfa(row.montant_retenu) },
                        { key: 'actions', header: 'Actions', render: (row) => editable && (
                            <span className="cluster">
                                <Button size="sm" onClick={() => { setEdition(row.id); setLigne({ classification: row.classification, code: row.code, label: row.libelle, quantite: String(row.quantite), unite: row.unite ?? '', cout_unitaire: String(row.cout_unitaire), justification: row.justification ?? '', gar_node_id: row.gar_node_id ? String(row.gar_node_id) : '', periode: row.periode ?? '' }); }}>Modifier</Button>
                                <Button size="sm" onClick={() => action(`dl${row.id}`, () => api.delete(`/preparation/lignes/${row.id}`), 'Ligne retirée.')}>Retirer</Button>
                            </span>
                        ) },
                    ] as Column<any>[]}
                    rows={fiche.lignes ?? []}
                    rowKey={(row) => row.id}
                    empty={<EmptyState icon={ICON.budget} title="Aucune ligne" compact />}
                />
            </SectionCard>
            {editable && (
                <SectionCard title={edition ? 'Modifier la ligne' : 'Ajouter une ligne'} icon={ICON.create}>
                    <LienPlanification />
                    <form className="form-grid" onSubmit={sauverLigne}>
                        <FormField label="Classification" required>
                            <select className="inp" value={ligne.classification} onChange={(event) => setLigne({ ...ligne, classification: event.target.value })}>
                                <option value="fonctionnement">Fonctionnement</option>
                                <option value="investissement">Investissement / PAP</option>
                            </select>
                        </FormField>
                        <FormField label="Code d’imputation" required><input className="inp" required value={ligne.code} onChange={(event) => setLigne({ ...ligne, code: event.target.value })} /></FormField>
                        <FormField label="Libellé" required><input className="inp" required value={ligne.label} onChange={(event) => setLigne({ ...ligne, label: event.target.value })} /></FormField>
                        <FormField label="Activité ou tâche">
                            <select className="inp" value={ligne.gar_node_id} onChange={(event) => setLigne({ ...ligne, gar_node_id: event.target.value })}>
                                <option value="">Aucune</option>
                                {(ref?.activites ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.type} · {row.code} · {row.libelle}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Quantité" required><input className="inp" required inputMode="decimal" value={ligne.quantite} onChange={(event) => setLigne({ ...ligne, quantite: event.target.value })} /></FormField>
                        <FormField label="Unité"><input className="inp" value={ligne.unite} onChange={(event) => setLigne({ ...ligne, unite: event.target.value })} /></FormField>
                        <FormField label="Coût unitaire (FCFA)" required hint="Montant entier. Le total est calculé par le serveur."><input className="inp" required inputMode="numeric" value={ligne.cout_unitaire} onChange={(event) => setLigne({ ...ligne, cout_unitaire: event.target.value })} /></FormField>
                        <FormField label="Période"><input className="inp" value={ligne.periode} onChange={(event) => setLigne({ ...ligne, periode: event.target.value })} /></FormField>
                        <FormField label="Justification" className="span-all"><textarea className="inp" value={ligne.justification} onChange={(event) => setLigne({ ...ligne, justification: event.target.value })} /></FormField>
                        <div className="cluster span-all">
                            <Button type="submit" variant="primary" icon={ICON.check} loading={pending === 'ligne'}>Enregistrer</Button>
                            {edition && <Button type="button" onClick={() => { setEdition(null); setLigne(LIGNE); }}>Annuler</Button>}
                        </div>
                    </form>
                </SectionCard>
            )}
            <SectionCard title="Détails, périodes et financements" icon={ICON.budget}>
                {(() => {
                    // Ventilation déjà saisie : chaque élément reste visible et peut être retiré
                    // tant que le dossier est modifiable (sinon une erreur de saisie bloquerait la soumission).
                    const elements = (fiche.lignes ?? []).flatMap((ligneDossier: any) => [
                        ...(ligneDossier.details ?? []).map((row: any) => ({ cle: `d${row.id}`, ligne: ligneDossier.code, nature: 'Détail', libelle: `${row.designation} · ${row.quantite} × ${fcfa(row.cout_unitaire)}`, montant: row.montant, url: `/preparation/details/${row.id}` })),
                        ...(ligneDossier.periodes ?? []).map((row: any) => ({ cle: `p${row.id}`, ligne: ligneDossier.code, nature: 'Période', libelle: row.periode, montant: row.montant, url: `/preparation/periodes/${row.id}` })),
                        ...(ligneDossier.financements ?? []).map((row: any) => ({ cle: `f${row.id}`, ligne: ligneDossier.code, nature: 'Financement', libelle: row.source ?? 'Source', montant: row.montant, url: `/preparation/financements/${row.id}` })),
                    ]);
                    return (
                        <DataTable
                            columns={[
                                { key: 'ligne', header: 'Ligne', className: 'mono', render: (row: any) => row.ligne },
                                { key: 'nature', header: 'Nature', render: (row: any) => row.nature },
                                { key: 'libelle', header: 'Élément', render: (row: any) => row.libelle },
                                { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row: any) => fcfa(row.montant) },
                                { key: 'actions', header: 'Actions', srHeader: true, align: 'right', render: (row: any) => editable && <Button size="sm" variant="danger-outline" loading={pending === row.cle} onClick={() => action(row.cle, () => api.delete(row.url), `${row.nature} retiré(e).`)}>Retirer</Button> },
                            ] as Column<any>[]}
                            rows={elements}
                            rowKey={(row: any) => row.cle}
                            compact
                            empty={<EmptyState icon={ICON.budget} title="Aucune ventilation saisie" compact>Ajoutez ci-dessous les détails, la répartition par période et les financements.</EmptyState>}
                        />
                    );
                })()}
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void action('detail', () => api.post(`/preparation/lignes/${detail.line_id}/details`, { designation: detail.designation, quantite: Number(detail.quantite), cout_unitaire: Number(detail.cout_unitaire) }), 'Détail enregistré.'); }}>
                    <FormField label="Ligne">
                        <select className="inp" required value={detail.line_id} onChange={(event) => setDetail({ ...detail, line_id: event.target.value })}>
                            <option value="">Choisir</option>
                            {(fiche.lignes ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.code}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Désignation"><input className="inp" required value={detail.designation} onChange={(event) => setDetail({ ...detail, designation: event.target.value })} /></FormField>
                    <FormField label="Quantité"><input className="inp" required value={detail.quantite} onChange={(event) => setDetail({ ...detail, quantite: event.target.value })} /></FormField>
                    <FormField label="Coût unitaire"><input className="inp" required value={detail.cout_unitaire} onChange={(event) => setDetail({ ...detail, cout_unitaire: event.target.value })} /></FormField>
                    {editable && <Button type="submit" loading={pending === 'detail'}>Ajouter le détail</Button>}
                </form>
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void action('periode', () => api.post(`/preparation/lignes/${periode.line_id}/periodes`, { periode: periode.periode, montant: Number(periode.montant) }), 'Répartition enregistrée.'); }}>
                    <FormField label="Ligne">
                        <select className="inp" required value={periode.line_id} onChange={(event) => setPeriode({ ...periode, line_id: event.target.value })}>
                            <option value="">Choisir</option>
                            {(fiche.lignes ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.code}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Période"><input className="inp" required value={periode.periode} onChange={(event) => setPeriode({ ...periode, periode: event.target.value })} /></FormField>
                    <FormField label="Montant"><input className="inp" required value={periode.montant} onChange={(event) => setPeriode({ ...periode, montant: event.target.value })} /></FormField>
                    {editable && <Button type="submit" loading={pending === 'periode'}>Ajouter la période</Button>}
                </form>
                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void action('fin', () => api.post(`/preparation/lignes/${financement.line_id}/financements`, { revenue_category_id: Number(financement.revenue_category_id), montant: Number(financement.montant) }), 'Financement enregistré.'); }}>
                    <FormField label="Ligne">
                        <select className="inp" required value={financement.line_id} onChange={(event) => setFinancement({ ...financement, line_id: event.target.value })}>
                            <option value="">Choisir</option>
                            {(fiche.lignes ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.code}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Source">
                        <select className="inp" required value={financement.revenue_category_id} onChange={(event) => setFinancement({ ...financement, revenue_category_id: event.target.value })}>
                            <option value="">Choisir</option>
                            {(ref?.categories ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Montant"><input className="inp" required value={financement.montant} onChange={(event) => setFinancement({ ...financement, montant: event.target.value })} /></FormField>
                    {editable && <Button type="submit" loading={pending === 'fin'}>Ajouter le financement</Button>}
                </form>
            </SectionCard>
            {fiche.statut === 'soumis' && (
                <SectionCard title="Arbitrage" icon={ICON.validate}>
                    <form className="form-grid" onSubmit={(event) => { event.preventDefault(); void action('arb', () => api.post('/preparation/arbitrages', { dossier_id: Number(id), line_id: arbitrage.line_id ? Number(arbitrage.line_id) : null, montant_retenu: Number(arbitrage.montant_retenu), decision: arbitrage.decision, motif: arbitrage.motif }), 'Arbitrage enregistré.'); }}>
                        <FormField label="Ligne">
                            <select className="inp" value={arbitrage.line_id} onChange={(event) => setArbitrage({ ...arbitrage, line_id: event.target.value })}>
                                <option value="">Dossier entier</option>
                                {(fiche.lignes ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.code}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Montant retenu"><input className="inp" required value={arbitrage.montant_retenu} onChange={(event) => setArbitrage({ ...arbitrage, montant_retenu: event.target.value })} /></FormField>
                        <FormField label="Décision">
                            <select className="inp" value={arbitrage.decision} onChange={(event) => setArbitrage({ ...arbitrage, decision: event.target.value })}>
                                <option value="retenu">Retenir</option>
                                <option value="revise">Réviser</option>
                                <option value="ecarte">Écarter</option>
                            </select>
                        </FormField>
                        <FormField label="Motif" className="span-all"><textarea className="inp" required value={arbitrage.motif} onChange={(event) => setArbitrage({ ...arbitrage, motif: event.target.value })} /></FormField>
                        <Button type="submit" variant="primary" loading={pending === 'arb'}>Enregistrer la décision</Button>
                    </form>
                    <form className="cluster" onSubmit={(event) => { event.preventDefault(); void action('retour', () => api.post(`/preparation/dossiers/${id}/retourner`, { motif }), 'Dossier retourné.'); }}>
                        <FormField label="Retour pour correction"><input className="inp" required value={motif} onChange={(event) => setMotif(event.target.value)} /></FormField>
                        <Button type="submit" loading={pending === 'retour'}>Retourner</Button>
                    </form>
                </SectionCard>
            )}
            <SectionCard title="Décisions" icon={ICON.history} flush>
                <DataTable columns={[
                    { key: 'date', header: 'Date', render: (row) => row.date },
                    { key: 'decision', header: 'Décision', render: (row) => <StatutPrep valeur={row.decision} /> },
                    { key: 'demande', header: 'Demandé', align: 'right', render: (row) => fcfa(row.montant_demande) },
                    { key: 'retenu', header: 'Retenu', align: 'right', render: (row) => fcfa(row.montant_retenu) },
                    { key: 'acteur', header: 'Acteur', render: (row) => row.acteur },
                    { key: 'motif', header: 'Motif', render: (row) => row.motif },
                ] as Column<any>[]} rows={fiche.arbitrages ?? []} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.history} title="Aucun arbitrage" compact />} />
            </SectionCard>
            <SectionCard title="Pièces" icon={ICON.document}>
                {(fiche.pieces ?? []).length === 0 && <p className="subtle">Aucune pièce jointe.</p>}
                <ul className="list-rows">
                    {(fiche.pieces ?? []).map((piece: any) => (
                        <li key={piece.id} className="list-row">{editable && <Button size="sm" variant="danger-outline" loading={pending === `rp${piece.id}`} onClick={() => action(`rp${piece.id}`, () => api.delete(`/preparation/pieces/${piece.id}`), 'Pièce retirée.')}>Retirer</Button>}{editable && (
                            <label className="btn btn-secondary btn-sm" style={{ cursor: 'pointer' }}>
                                Remplacer
                                <input type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.xlsx,.docx" onChange={(event) => {
                                    const fichier = event.target.files?.[0];
                                    event.target.value = '';
                                    if (!fichier) return;
                                    const corps = new FormData();
                                    corps.append('fichier', fichier);
                                    void action(`mp${piece.id}`, () => api.post(`/preparation/pieces/${piece.id}/remplacer`, corps), 'Nouvelle version de la pièce enregistrée.');
                                }} />
                            </label>
                        )}<Button size="sm" onClick={() => action(`p${piece.id}`, async () => {
                            const response = await api.get(`/preparation/pieces/${piece.id}/telecharger`, { responseType: 'blob' });
                            const url = URL.createObjectURL(response.data);
                            const lien = document.createElement('a');
                            lien.href = url;
                            lien.download = piece.nom;
                            lien.click();
                        }, 'Téléchargement lancé.')}>{piece.nom} · v{piece.version}</Button></li>
                    ))}
                </ul>
                {editable && (
                    <form className="stack" onSubmit={(event) => {
                        event.preventDefault();
                        if (!piece) {
                            return;
                        }
                        const corps = new FormData();
                        corps.append('fichier', piece);
                        corps.append('dossier_id', String(id));
                        void action('piece', () => api.post('/preparation/pieces', corps).then(() => setPiece(null)), 'Pièce ajoutée.');
                    }}>
                        <FormField label="Ajouter une pièce">
                            <FileDrop file={piece} onFile={setPiece} hint="PDF ou image, 10 Mo maximum." />
                        </FormField>
                        <div className="form-actions">
                            <Button type="submit" variant="primary" icon={ICON.attachment} loading={pending === 'piece'} disabled={!piece}>Joindre la pièce</Button>
                        </div>
                    </form>
                )}
            </SectionCard>
            <SectionCard title="Historique" icon={ICON.clock} flush>
                <DataTable columns={[
                    { key: 'date', header: 'Date', render: (row) => row.date },
                    { key: 'action', header: 'Action', render: (row) => row.action },
                    { key: 'acteur', header: 'Acteur', render: (row) => row.acteur },
                    { key: 'motif', header: 'Motif', render: (row) => row.motif ?? '—' },
                ] as Column<any>[]} rows={historique} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.clock} title="Aucun événement" compact />} />
            </SectionCard>
        </main>
    );
}
