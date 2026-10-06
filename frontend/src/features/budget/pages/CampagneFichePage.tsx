import { FormEvent, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, PageHeader, SectionCard, useDialogs, useToast, type Column } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { PreparationNav, Retard, StatutPrep } from '../preparation/shell';

const ETAPE = { ordre: '1', label: '', description: '', debut: '', echeance: '', acteurs: '', structures: '', prerequis: '', livrables: '' };

export default function CampagneFichePage() {
    const { id } = useParams();
    const toast = useToast();
    const { confirm } = useDialogs();
    const [fiche, setFiche] = useState<any>(null);
    const [etape, setEtape] = useState(ETAPE);
    const [edition, setEdition] = useState<number | null>(null);
    const [prolongation, setProlongation] = useState('');
    const [version, setVersion] = useState('');
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get(`/preparation/campagnes/${id}`).then((response) => { setFiche(response.data.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
    }

    useEffect(() => { load(); }, [id]);

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

    async function sauverEtape(event: FormEvent) {
        event.preventDefault();
        const payload = { ...etape, ordre: Number(etape.ordre) };
        await action(edition ? `e${edition}` : 'etape', () => edition
            ? api.patch(`/preparation/etapes/${edition}`, payload)
            : api.post(`/preparation/campagnes/${id}/etapes`, payload), 'Étape enregistrée.');
        setEtape(ETAPE);
        setEdition(null);
    }

    if (!fiche) {
        return <main className="app-content"><ErrorMessage error={error} /></main>;
    }

    return (
        <main className="app-content">
            <PageHeader
                title={fiche.libelle}
                subtitle={`${fiche.code} · exercice ${fiche.exercice} · ${fiche.devise}`}
                actions={(
                    <>
                        <StatutPrep valeur={fiche.statut} />
                        {fiche.statut === 'brouillon' && <Button icon={ICON.edit} to={`/preparation/campagnes/${id}/modifier`}>Modifier</Button>}
                        {fiche.statut === 'brouillon' && <Button variant="primary" icon={ICON.check} loading={pending === 'ouvrir'} onClick={() => action('ouvrir', () => api.post(`/preparation/campagnes/${id}/ouvrir`), 'Campagne ouverte.')}>Ouvrir la campagne</Button>}
                        {fiche.statut === 'ouverte' && <Button loading={pending === 'suspendre'} onClick={() => action('suspendre', () => api.post(`/preparation/campagnes/${id}/suspendre`), 'Campagne suspendue.')}>Suspendre</Button>}
                        {['ouverte', 'suspendue'].includes(fiche.statut) && <Button loading={pending === 'cloturer'} onClick={() => action('cloturer', () => api.post(`/preparation/campagnes/${id}/cloturer`), 'Campagne clôturée.')}>Clôturer</Button>}
                        <Button to={`/preparation/campagnes/${id}/cadrage`} icon={ICON.budget}>Cadrage</Button>
                        <Button to={`/preparation/campagnes/${id}/consolidation`} icon={ICON.report}>Consolidation</Button>
                        <Button icon={ICON.download} loading={pending === 'excel'} onClick={() => action('excel', async () => {
                            const response = await api.get(`/preparation/campagnes/${id}/export.xlsx`, { responseType: 'blob' });
                            const url = URL.createObjectURL(response.data);
                            const lien = document.createElement('a');
                            lien.href = url;
                            lien.download = `${fiche.code}.xlsx`;
                            lien.click();
                            URL.revokeObjectURL(url);
                        }, 'Export prêt.')}>Excel</Button>
                    </>
                )}
            />
            <PreparationNav />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard title="Périmètre" icon={ICON.budget}>
                <p>{fiche.description || 'Aucune description.'}</p>
                <p className="subtle">Du {fiche.date_ouverture ?? '—'} au {fiche.date_cloture ?? '—'} · responsable {fiche.responsable ?? '—'} · cadrage v{fiche.version_cadrage}</p>
                <p>{(fiche.structures ?? []).map((row: any) => row.sigle).join(', ') || 'Aucune structure participante.'}</p>
                {['ouverte', 'suspendue'].includes(fiche.statut) && (
                    <form className="cluster" onSubmit={(event) => { event.preventDefault(); void action('prolonger', () => api.post(`/preparation/campagnes/${id}/prolonger`, { date_cloture: prolongation }), 'Campagne prolongée.'); }}>
                        <FormField label="Prolonger jusqu’au"><input className="inp" type="date" required value={prolongation} onChange={(event) => setProlongation(event.target.value)} /></FormField>
                        <Button type="submit" loading={pending === 'prolonger'}>Prolonger</Button>
                    </form>
                )}
            </SectionCard>
            <SectionCard title="Calendrier" icon={ICON.calendar} flush>
                <DataTable
                    columns={[
                        { key: 'ordre', header: 'Ordre', render: (row) => row.ordre },
                        { key: 'libelle', header: 'Étape', render: (row) => row.libelle },
                        { key: 'echeance', header: 'Échéance', render: (row) => <span className="cluster">{row.echeance ?? '—'} <Retard actif={row.retard} /></span> },
                        { key: 'acteurs', header: 'Acteurs', render: (row) => row.acteurs || '—' },
                        { key: 'actions', header: 'Actions', render: (row) => row.verrouillee ? <StatutPrep valeur="verrouillee" /> : (
                            <span className="cluster">
                                <Button size="sm" onClick={() => { setEdition(row.id); setEtape({ ordre: String(row.ordre), label: row.libelle, description: row.description ?? '', debut: row.debut ?? '', echeance: row.echeance ?? '', acteurs: row.acteurs ?? '', structures: row.structures ?? '', prerequis: row.prerequis ?? '', livrables: row.livrables ?? '' }); }}>Modifier</Button>
                                <Button size="sm" onClick={() => action(`del${row.id}`, () => api.delete(`/preparation/etapes/${row.id}`), 'Étape retirée.')}>Retirer</Button>
                            </span>
                        ) },
                    ] as Column<any>[]}
                    rows={fiche.etapes ?? []}
                    rowKey={(row) => row.id}
                    empty={<EmptyState icon={ICON.calendar} title="Aucune étape" compact />}
                />
            </SectionCard>
            {!['cloturee', 'archivee'].includes(fiche.statut) && (
                <SectionCard title={edition ? 'Modifier l’étape' : 'Ajouter une étape'} icon={ICON.create}>
                    <form className="form-grid" onSubmit={sauverEtape}>
                        <FormField label="Ordre" required><input className="inp" required inputMode="numeric" value={etape.ordre} onChange={(event) => setEtape({ ...etape, ordre: event.target.value })} /></FormField>
                        <FormField label="Intitulé" required><input className="inp" required value={etape.label} onChange={(event) => setEtape({ ...etape, label: event.target.value })} /></FormField>
                        <FormField label="Début"><input className="inp" type="date" value={etape.debut} onChange={(event) => setEtape({ ...etape, debut: event.target.value })} /></FormField>
                        <FormField label="Échéance"><input className="inp" type="date" value={etape.echeance} onChange={(event) => setEtape({ ...etape, echeance: event.target.value })} /></FormField>
                        <FormField label="Acteurs"><input className="inp" value={etape.acteurs} onChange={(event) => setEtape({ ...etape, acteurs: event.target.value })} /></FormField>
                        <FormField label="Structures"><input className="inp" value={etape.structures} onChange={(event) => setEtape({ ...etape, structures: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={etape.description} onChange={(event) => setEtape({ ...etape, description: event.target.value })} /></FormField>
                        <FormField label="Prérequis" className="span-all"><textarea className="inp" value={etape.prerequis} onChange={(event) => setEtape({ ...etape, prerequis: event.target.value })} /></FormField>
                        <FormField label="Livrables" className="span-all"><textarea className="inp" value={etape.livrables} onChange={(event) => setEtape({ ...etape, livrables: event.target.value })} /></FormField>
                        <div className="cluster span-all">
                            <Button type="submit" variant="primary" icon={ICON.check} loading={pending === 'etape' || pending === `e${edition}`}>Enregistrer</Button>
                            {edition && <Button type="button" onClick={() => { setEdition(null); setEtape(ETAPE); }}>Annuler</Button>}
                        </div>
                    </form>
                </SectionCard>
            )}
            <SectionCard title="Propositions" icon={ICON.tasks} actions={fiche.statut === 'ouverte' && <Button size="sm" variant="primary" icon={ICON.create} to={`/preparation/dossiers/nouvelle?campagne=${id}`}>Nouvelle proposition</Button>} flush>
                <DataTable
                    columns={[
                        { key: 'reference', header: 'Référence', render: (row) => <Link className="cell-ref" to={`/preparation/dossiers/${row.id}`}>{row.reference}</Link> },
                        { key: 'titre', header: 'Titre', render: (row) => row.titre },
                        { key: 'structure', header: 'Structure', render: (row) => row.structure },
                        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                    ] as Column<any>[]}
                    rows={fiche.dossiers ?? []}
                    rowKey={(row) => row.id}
                    empty={<EmptyState icon={ICON.tasks} title="Aucune proposition" compact />}
                />
            </SectionCard>
            <SectionCard title="Versions" icon={ICON.report} actions={(
                <form className="cluster" onSubmit={(event) => { event.preventDefault(); void action('version', () => api.post(`/preparation/campagnes/${id}/versions`, { libelle: version }), 'Version créée.'); }}>
                    <input className="inp" required placeholder="Libellé de la version" value={version} onChange={(event) => setVersion(event.target.value)} />
                    <Button type="submit" size="sm" icon={ICON.create} loading={pending === 'version'}>Créer une version</Button>
                </form>
            )} flush>
                <DataTable
                    columns={[
                        { key: 'numero', header: 'N°', render: (row) => row.numero },
                        { key: 'libelle', header: 'Libellé', render: (row) => row.libelle },
                        { key: 'statut', header: 'Statut', render: (row) => <StatutPrep valeur={row.statut} /> },
                        { key: 'actions', header: 'Actions', render: (row) => (
                            <span className="cluster">
                                {['travail', 'retournee'].includes(row.statut) && <Button size="sm" onClick={() => action(`vs${row.id}`, () => api.post(`/preparation/versions/${row.id}/soumettre`), 'Version soumise.')}>Soumettre</Button>}
                                {row.statut === 'soumise' && <Button size="sm" onClick={() => action(`vv${row.id}`, () => api.post(`/preparation/versions/${row.id}/valider`), 'Version validée.')}>Valider</Button>}
                                {row.statut === 'validee' && <Button size="sm" variant="primary" onClick={async () => {
                                    const ok = await confirm({ title: 'Adopter cette version', description: 'Les lignes retenues sont transmises au module Budget et l’exercice devient exécutoire.', confirmLabel: 'Adopter', tone: 'danger', icon: ICON.validate });
                                    if (ok) {
                                        await action(`va${row.id}`, () => api.post(`/preparation/versions/${row.id}/adopter`), 'Budget adopté et transmis.');
                                    }
                                }}>Adopter</Button>}
                                {row.statut === 'adoptee' && <Button size="sm" onClick={() => action(`vp${row.id}`, () => api.post(`/preparation/versions/${row.id}/publier`), 'Version publiée.')}>Publier</Button>}
                            </span>
                        ) },
                    ] as Column<any>[]}
                    rows={fiche.versions ?? []}
                    rowKey={(row) => row.id}
                    empty={<EmptyState icon={ICON.report} title="Aucune version" compact />}
                />
            </SectionCard>
        </main>
    );
}
