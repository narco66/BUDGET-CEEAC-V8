import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import { Badge, Button, DataTable, EmptyState, ErrorMessage, ICON, KeyValueList, PageHeader, Pagination, SearchInput, SectionCard, useDialogs, useToast, type PageMeta } from '../../../components/ui';
import { dateHeure, errorsOf } from '../../../utils/format';

type Ligne = {
    id: number;
    quand: string;
    acteur?: string;
    role?: string;
    module?: string;
    action: string;
    objet?: string;
    objet_id?: string;
    reference?: string;
    resultat: string;
    motif?: string;
};

export default function AuditJournalPage() {
    const [lignes, setLignes] = useState<Ligne[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [indicateurs, setIndicateurs] = useState<Record<string, number>>({});
    const [gels, setGels] = useState<{ id: number; portee: string; objet: string | null; motif: string; depuis: string | null }[]>([]);
    const { prompt } = useDialogs();
    const toast = useToast();
    const [q, setQ] = useState('');
    const [module, setModule] = useState('');
    const [resultat, setResultat] = useState('');
    const [du, setDu] = useState('');
    const [au, setAu] = useState('');
    const [unite, setUnite] = useState('');
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [fiche, setFiche] = useState<any>(null);
    const [motifGel, setMotifGel] = useState('');
    const [fuseau, setFuseau] = useState('Africa/Libreville');

    async function charger(pageDemandee = page) {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/admin/audit', { params: { q, module, resultat, du, au, unite, page: pageDemandee } });
            setLignes(response.data.data);
            setMeta(response.data.meta);
            setFuseau(response.data.meta?.fuseau ?? 'Africa/Libreville');
            setIndicateurs(response.data.indicateurs ?? {});
            setGels(response.data.gels ?? []);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        charger(1);
    }, []);

    function rechercher(event: FormEvent) {
        event.preventDefault();
        setPage(1);
        charger(1);
    }

    async function ouvrir(id: number) {
        setError('');
        try {
            const response = await api.get(`/admin/audit/${id}`);
            setFiche(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    async function exporter(format: 'csv' | 'xlsx' | 'pdf') {
        const response = await api.get('/admin/audit/export', { params: { q, module, resultat, du, au, unite, format }, responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const lien = document.createElement('a');
        lien.href = url;
        lien.download = `journal-audit.${format}`;
        lien.click();
        URL.revokeObjectURL(url);
    }

    async function geler(event: FormEvent) {
        event.preventDefault();
        try {
            await api.post('/admin/audit/gel', { scope: 'journal', motif: motifGel });
            setMotifGel('');
            charger(page);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    async function leverGel(gel: { id: number; motif: string }) {
        const values = await prompt({
            title: 'Lever le gel du journal',
            description: `Gel posé pour : ${gel.motif}. La levée est tracée et exige un motif.`,
            confirmLabel: 'Lever le gel',
            tone: 'warning',
            fields: [{ name: 'motif', label: 'Motif de la levée', type: 'textarea', required: true }],
        });
        if (!values) return;
        try {
            await api.post(`/admin/audit/gels/${gel.id}/lever`, values);
            toast.success('Gel levé.');
            charger(page);
        } catch (caught) {
            setError(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Journal d’audit"
                subtitle={`Événements réels, heure de ${fuseau}. Le journal ne se modifie pas : une rectification est un nouvel événement.`}
                actions={<Button icon={ICON.download} onClick={() => exporter('csv')}>Exporter CSV</Button>}
            />
            <div className="grid-kpi">
                <SectionCard title="Visibles"><strong>{indicateurs.total ?? 0}</strong></SectionCard>
                <SectionCard title="Refus"><strong>{indicateurs.refus ?? 0}</strong></SectionCard>
                <SectionCard title="Échecs"><strong>{indicateurs.echecs ?? 0}</strong></SectionCard>
            </div>
            <ErrorMessage error={error} onClose={() => setError('')} />
            <SectionCard title="Recherche" icon={ICON.search}>
                <form className="form-actions" onSubmit={rechercher}>
                    <SearchInput value={q} onChange={setQ} placeholder="Action, acteur, référence, motif" />
                    <input className="inp" value={module} onChange={(event) => setModule(event.target.value)} placeholder="Module" aria-label="Module" />
                    <input className="inp" value={resultat} onChange={(event) => setResultat(event.target.value)} placeholder="Résultat" aria-label="Résultat" />
                    <input className="inp" type="date" value={du} onChange={(event) => setDu(event.target.value)} aria-label="Du" />
                    <input className="inp" type="date" value={au} onChange={(event) => setAu(event.target.value)} aria-label="Au" />
                    <input className="inp" value={unite} onChange={(event) => setUnite(event.target.value)} placeholder="Unité" aria-label="Unité" inputMode="numeric" />
                    <Button type="submit" icon={ICON.search}>Filtrer</Button>
                    <Button type="button" onClick={() => exporter('xlsx')}>Excel</Button>
                    <Button type="button" onClick={() => exporter('pdf')}>PDF</Button>
                </form>
            </SectionCard>
            <SectionCard title="Événements" icon={ICON.history} flush>
                <DataTable
                    columns={[
                        { key: 'quand', header: 'Date', render: (row: Ligne) => <span className="mono">{row.quand}</span> },
                        { key: 'acteur', header: 'Acteur', render: (row: Ligne) => row.acteur || 'Système' },
                        { key: 'role', header: 'Qualité', render: (row: Ligne) => row.role || '—' },
                        { key: 'module', header: 'Module', render: (row: Ligne) => row.module || '—' },
                        { key: 'action', header: 'Action', render: (row: Ligne) => <Button variant="link" onClick={() => ouvrir(row.id)}>{row.action}</Button> },
                        { key: 'reference', header: 'Référence', render: (row: Ligne) => row.reference || `${row.objet || ''} ${row.objet_id || ''}`.trim() },
                        { key: 'resultat', header: 'Résultat', render: (row: Ligne) => row.resultat },
                    ]}
                    rows={lignes}
                    rowKey={(row: Ligne) => row.id}
                    loading={loading}
                    empty={<EmptyState title="Aucun événement">Aucun événement ne correspond à ce filtre.</EmptyState>}
                />
                <Pagination meta={meta} noun="événement" onPage={(suivante) => { setPage(suivante); charger(suivante); }} />
            </SectionCard>
            {fiche && (
                <SectionCard
                    title={fiche.action}
                    icon={ICON.document}
                    subtitle={fiche.quand}
                    actions={<Button size="sm" icon={ICON.edit} onClick={async () => {
                        const values = await prompt({
                            title: 'Rectifier cet événement',
                            description: 'L’événement d’origine n’est jamais modifié : une rectification motivée lui est liée dans le journal.',
                            confirmLabel: 'Rectifier',
                            tone: 'warning',
                            fields: [{ name: 'motif', label: 'Motif de la rectification', type: 'textarea', required: true }],
                        });
                        if (!values) return;
                        try {
                            await api.post(`/admin/audit/${fiche.id}/rectification`, values);
                            toast.success('Rectification enregistrée.');
                            ouvrir(fiche.id);
                            charger(page);
                        } catch (caught) {
                            setError(errorsOf(caught));
                        }
                    }}>Rectifier</Button>}
                >
                    <KeyValueList items={[
                        { label: 'Acteur', value: fiche.acteur || 'Système' },
                        { label: 'Qualité', value: fiche.role || '—' },
                        { label: 'Résultat', value: fiche.resultat },
                        { label: 'UTC', value: fiche.quand_utc },
                        { label: 'Fuseau', value: fiche.fuseau },
                        { label: 'Motif', value: fiche.motif || '—' },
                        { label: 'Corrélation', value: fiche.correlation || '—' },
                    ]} />
                    {fiche.contexte_incomplet && <p>Contexte historique incomplet : le rôle et l’habilitation n’étaient pas dans la source reprise.</p>}
                    {fiche.masque && <p>Les valeurs avant et après sont masquées pour ce niveau de sensibilité.</p>}
                    {(fiche.differences ?? []).length > 0 && (
                        <ul>
                            {fiche.differences.map((ligne: { champ: string; avant: unknown; apres: unknown }) => (
                                <li key={ligne.champ}>{ligne.champ} : {String(ligne.avant ?? '—')} → {String(ligne.apres ?? '—')}</li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            )}
            <SectionCard title="Gel du journal" icon={ICON.archive} subtitle="Un gel suspend toute purge du journal, par exemple pendant un contrôle ou un contentieux.">
                {gels.length > 0 && (
                    <ul className="list-rows" style={{ marginBottom: 12 }}>
                        {gels.map((gel) => (
                            <li key={gel.id} className="list-row">
                                <Badge tone="warning" size="sm" icon={ICON.lock}>Gel actif</Badge>
                                <span className="list-row-main">{gel.motif}{gel.objet ? ` · ${gel.objet}` : ''}<span className="cell-sub">depuis le {dateHeure(gel.depuis)}</span></span>
                                <Button size="sm" onClick={() => leverGel(gel)}>Lever</Button>
                            </li>
                        ))}
                    </ul>
                )}
                <form className="form-actions" onSubmit={geler}>
                    <input className="inp" value={motifGel} onChange={(event) => setMotifGel(event.target.value)} placeholder="Motif du gel" aria-label="Motif du gel" required />
                    <Button type="submit" disabled={motifGel.trim() === ''}>Geler</Button>
                </form>
            </SectionCard>
        </main>
    );
}
