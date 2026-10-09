import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, PageHeader, SectionCard, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';
import { PreparationNav, StatutPrep } from '../preparation/shell';

export default function CadragePage() {
    const { id } = useParams();
    const toast = useToast();
    const [ref, setRef] = useState<any>(null);
    const [portrait, setPortrait] = useState<any>(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);
    const [hypothese, setHypothese] = useState({ code: '', label: '', categorie: 'macro', valeur: '', unite: '', source: '', justification: '' });
    const [orientation, setOrientation] = useState({ code: '', label: '', categorie: 'instruction', instructions: '', source: '' });
    const [hypotheseId, setHypotheseId] = useState<number | null>(null);
    const [orientationId, setOrientationId] = useState<number | null>(null);
    const { confirm } = useDialogs();
    const [enveloppe, setEnveloppe] = useState({ organization_unit_id: '', classification: 'fonctionnement', montant: '', parent_id: '', justification: '', statut: 'brouillon' });

    function load() {
        api.get(`/preparation/campagnes/${id}/cadrage`).then((response) => { setPortrait(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [id]);
    useEffect(() => { api.get('/preparation/referentiel').then((response) => setRef(response.data)).catch(() => setRef(null)); }, []);

    async function envoyer(cle: string, request: () => Promise<unknown>, message: string) {
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

    async function archiver(type: 'hypotheses' | 'orientations', row: any) {
        const ok = await confirm({
            title: `Archiver ${row.code}`,
            description: 'L’élément archivé reste consultable mais ne s’applique plus aux nouvelles propositions.',
            confirmLabel: 'Archiver',
            tone: 'warning',
            icon: ICON.archive,
        });
        if (ok) {
            await envoyer(`a${type}${row.id}`, () => api.post(`/preparation/${type}/${row.id}/archiver`), 'Élément archivé.');
        }
    }

    function modifierHypothese(row: any) {
        setHypotheseId(row.id);
        setHypothese({ code: row.code, label: row.label, categorie: row.categorie ?? 'macro', valeur: row.valeur ?? '', unite: row.unite ?? '', source: row.source ?? '', justification: row.justification ?? '' });
    }

    function modifierOrientation(row: any) {
        setOrientationId(row.id);
        setOrientation({ code: row.code, label: row.label, categorie: row.categorie ?? 'instruction', instructions: row.instructions ?? '', source: row.source ?? '' });
    }

    return (
        <main className="app-content">
            <PageHeader title="Cadrage" subtitle="Hypothèses, orientations et plafonds de la campagne." actions={<Button to={`/preparation/campagnes/${id}`} icon={ICON.back}>Campagne</Button>} />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard title="Hypothèses" icon={ICON.budget} flush>
                <DataTable columns={[
                    { key: 'code', header: 'Code', render: (row) => `${row.code} · v${row.version}` },
                    { key: 'label', header: 'Intitulé', render: (row) => row.label },
                    { key: 'valeur', header: 'Valeur', render: (row) => `${row.valeur ?? '—'} ${row.unite ?? ''}` },
                    { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                    { key: 'actions', header: 'Actions', render: (row) => row.statut !== 'archivee' && (
                        <span className="cluster">
                            {row.statut === 'brouillon' && <Button size="sm" onClick={() => envoyer(`h${row.id}`, () => api.post(`/preparation/hypotheses/${row.id}/publier`), 'Hypothèse publiée.')}>Publier</Button>}
                            <Button size="sm" icon={ICON.edit} onClick={() => modifierHypothese(row)}>Modifier</Button>
                            <Button size="sm" variant="danger-outline" loading={pending === `ahypotheses${row.id}`} onClick={() => archiver('hypotheses', row)}>Archiver</Button>
                        </span>
                    ) },
                ] as Column<any>[]} rows={portrait?.hypotheses ?? []} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.budget} title="Aucune hypothèse" compact />} />
            </SectionCard>
            <SectionCard title={hypotheseId ? 'Modifier l’hypothèse' : 'Nouvelle hypothèse'} subtitle={hypotheseId ? 'Une hypothèse publiée n’est pas réécrite : la modification crée une nouvelle version.' : undefined} icon={hypotheseId ? ICON.edit : ICON.create}>
                <form className="form-grid" onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    void envoyer('hyp', () => (hypotheseId ? api.patch(`/preparation/hypotheses/${hypotheseId}`, hypothese) : api.post(`/preparation/campagnes/${id}/hypotheses`, hypothese)).then(() => {
                        setHypotheseId(null);
                        setHypothese({ code: '', label: '', categorie: 'macro', valeur: '', unite: '', source: '', justification: '' });
                    }), 'Hypothèse enregistrée.');
                }}>
                    <FormField label="Code" required><input className="inp" required value={hypothese.code} onChange={(event) => setHypothese({ ...hypothese, code: event.target.value })} /></FormField>
                    <FormField label="Intitulé" required><input className="inp" required value={hypothese.label} onChange={(event) => setHypothese({ ...hypothese, label: event.target.value })} /></FormField>
                    <FormField label="Catégorie" required><input className="inp" required value={hypothese.categorie} onChange={(event) => setHypothese({ ...hypothese, categorie: event.target.value })} /></FormField>
                    <FormField label="Valeur"><input className="inp" value={hypothese.valeur} onChange={(event) => setHypothese({ ...hypothese, valeur: event.target.value })} /></FormField>
                    <FormField label="Unité"><input className="inp" value={hypothese.unite} onChange={(event) => setHypothese({ ...hypothese, unite: event.target.value })} /></FormField>
                    <FormField label="Source"><input className="inp" value={hypothese.source} onChange={(event) => setHypothese({ ...hypothese, source: event.target.value })} /></FormField>
                    <FormField label="Justification" className="span-all"><textarea className="inp" value={hypothese.justification} onChange={(event) => setHypothese({ ...hypothese, justification: event.target.value })} /></FormField>
                    <div className="cluster span-all">
                        <Button type="submit" variant="primary" icon={ICON.check} loading={pending === 'hyp'}>Enregistrer</Button>
                        {hypotheseId && <Button onClick={() => { setHypotheseId(null); setHypothese({ code: '', label: '', categorie: 'macro', valeur: '', unite: '', source: '', justification: '' }); }}>Annuler</Button>}
                    </div>
                </form>
            </SectionCard>
            <SectionCard title="Orientations" icon={ICON.report} flush>
                <DataTable columns={[
                    { key: 'code', header: 'Code', render: (row) => `${row.code} · v${row.version}` },
                    { key: 'label', header: 'Intitulé', render: (row) => row.label },
                    { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                    { key: 'actions', header: 'Actions', render: (row) => row.statut !== 'archivee' && (
                        <span className="cluster">
                            {row.statut === 'brouillon' && <Button size="sm" onClick={() => envoyer(`o${row.id}`, () => api.post(`/preparation/orientations/${row.id}/publier`), 'Orientation publiée.')}>Publier</Button>}
                            <Button size="sm" icon={ICON.edit} onClick={() => modifierOrientation(row)}>Modifier</Button>
                            <Button size="sm" variant="danger-outline" loading={pending === `aorientations${row.id}`} onClick={() => archiver('orientations', row)}>Archiver</Button>
                        </span>
                    ) },
                ] as Column<any>[]} rows={portrait?.orientations ?? []} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.report} title="Aucune orientation" compact />} />
            </SectionCard>
            <SectionCard title={orientationId ? 'Modifier l’orientation' : 'Nouvelle orientation'} subtitle={orientationId ? 'Une orientation publiée n’est pas réécrite : la modification crée une nouvelle version.' : undefined} icon={orientationId ? ICON.edit : ICON.create}>
                <form className="form-grid" onSubmit={(event: FormEvent) => {
                    event.preventDefault();
                    void envoyer('ori', () => (orientationId ? api.patch(`/preparation/orientations/${orientationId}`, orientation) : api.post(`/preparation/campagnes/${id}/orientations`, orientation)).then(() => {
                        setOrientationId(null);
                        setOrientation({ code: '', label: '', categorie: 'instruction', instructions: '', source: '' });
                    }), 'Orientation enregistrée.');
                }}>
                    <FormField label="Code" required><input className="inp" required value={orientation.code} onChange={(event) => setOrientation({ ...orientation, code: event.target.value })} /></FormField>
                    <FormField label="Intitulé" required><input className="inp" required value={orientation.label} onChange={(event) => setOrientation({ ...orientation, label: event.target.value })} /></FormField>
                    <FormField label="Instructions" className="span-all"><textarea className="inp" value={orientation.instructions} onChange={(event) => setOrientation({ ...orientation, instructions: event.target.value })} /></FormField>
                    <div className="cluster span-all">
                        <Button type="submit" variant="primary" loading={pending === 'ori'}>Enregistrer</Button>
                        {orientationId && <Button onClick={() => { setOrientationId(null); setOrientation({ code: '', label: '', categorie: 'instruction', instructions: '', source: '' }); }}>Annuler</Button>}
                    </div>
                </form>
            </SectionCard>
            <SectionCard title="Enveloppes et plafonds" icon={ICON.budget} flush>
                <DataTable columns={[
                    { key: 'structure', header: 'Structure', render: (row) => row.organization_unit?.sigle ?? row.organization_unit_id },
                    { key: 'classification', header: 'Classe', render: (row) => row.classification },
                    { key: 'montant', header: 'Plafond', align: 'right', className: 'mono', render: (row) => fcfa(row.montant) },
                    { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                    { key: 'actions', header: 'Actions', render: (row) => row.statut === 'brouillon' && <Button size="sm" onClick={() => envoyer(`e${row.id}`, () => api.delete(`/preparation/enveloppes/${row.id}`), 'Enveloppe retirée.')}>Retirer</Button> },
                ] as Column<any>[]} rows={portrait?.enveloppes ?? []} rowKey={(row) => row.id} empty={<EmptyState icon={ICON.budget} title="Aucun plafond" compact />} />
            </SectionCard>
            <SectionCard title="Nouvelle enveloppe" icon={ICON.create}>
                <form className="form-grid" onSubmit={(event: FormEvent) => { event.preventDefault(); void envoyer('env', () => api.post(`/preparation/campagnes/${id}/enveloppes`, { ...enveloppe, organization_unit_id: Number(enveloppe.organization_unit_id), montant: Number(enveloppe.montant), parent_id: enveloppe.parent_id ? Number(enveloppe.parent_id) : null }), 'Enveloppe enregistrée.'); }}>
                    <FormField label="Structure" required>
                        <select className="inp" required value={enveloppe.organization_unit_id} onChange={(event) => setEnveloppe({ ...enveloppe, organization_unit_id: event.target.value })}>
                            <option value="">Choisir</option>
                            {(ref?.structures ?? []).map((row: any) => <option key={row.id} value={row.id}>{row.sigle}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Classification">
                        <select className="inp" value={enveloppe.classification} onChange={(event) => setEnveloppe({ ...enveloppe, classification: event.target.value })}>
                            <option value="fonctionnement">Fonctionnement</option>
                            <option value="investissement">Investissement</option>
                        </select>
                    </FormField>
                    <FormField label="Montant (FCFA)" required><input className="inp" required value={enveloppe.montant} onChange={(event) => setEnveloppe({ ...enveloppe, montant: event.target.value })} /></FormField>
                    <FormField label="Statut">
                        <select className="inp" value={enveloppe.statut} onChange={(event) => setEnveloppe({ ...enveloppe, statut: event.target.value })}>
                            <option value="brouillon">Brouillon</option>
                            <option value="actif">Actif</option>
                        </select>
                    </FormField>
                    <FormField label="Justification" className="span-all"><textarea className="inp" value={enveloppe.justification} onChange={(event) => setEnveloppe({ ...enveloppe, justification: event.target.value })} /></FormField>
                    <Button type="submit" variant="primary" loading={pending === 'env'}>Enregistrer</Button>
                </form>
            </SectionCard>
        </main>
    );
}
