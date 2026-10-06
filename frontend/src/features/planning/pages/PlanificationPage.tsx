import { faBullseye, faChevronRight, faCubes, faFlag, faLayerGroup, faListCheck, faSitemap, faTag } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Alert,
    AmountInput,
    Badge,
    Button,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    PageError,
    PageHeader,
    PageSkeleton,
    SectionCard,
    MultiCheck,
    useDialogs,
    useToast,
} from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

const LIBELLES: Record<string, string> = {
    pilier: 'Pilier',
    axe: 'Axe',
    produit: 'Produit',
    sous_produit: 'Sous-produit',
    activite: 'Activité',
    tache: 'Tâche',
    brouillon: 'Brouillon',
    en_validation: 'En validation',
    valide: 'Validée',
    publie: 'Publiée',
    archive: 'Archivée',
};

const TYPE_ICONS = { pilier: faLayerGroup, axe: faFlag, produit: faCubes, sous_produit: faTag, activite: faBullseye, tache: faListCheck };
const VERSION_TONE: Record<string, string> = { brouillon: 'neutral', en_validation: 'warning', valide: 'info', publie: 'success', archive: 'neutral' };

const VIDE = {
    type: 'pilier',
    parent_id: '',
    libelle: '',
    description: '',
    objectifs: '',
    resultats_attendus: '',
    organization_unit_id: '',
    periode: '',
    date_debut: '',
    date_fin: '',
    indicateur: '',
    unite_mesure: '',
    cible: '',
    enveloppe: '',
    justification: '',
};

export default function PlanificationPage() {
    const toast = useToast();
    const { confirm } = useDialogs();
    const [portrait, setPortrait] = useState<any>(null);
    const [loadError, setLoadError] = useState('');
    const [selected, setSelected] = useState<any>(null);
    const [form, setForm] = useState(VIDE);
    const [contributeurs, setContributeurs] = useState<number[]>([]);
    const [motif, setMotif] = useState('');
    const [effet, setEffet] = useState('2026-01-01');
    const [error, setError] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function load() {
        api.get('/planification')
            .then((response) => { setPortrait(response.data); setLoadError(''); })
            .catch((caught) => setLoadError(errorsOf(caught)));
    }

    useEffect(() => {
        load();
    }, []);

    const noeuds = portrait?.noeuds ?? [];
    const enfants = useMemo(() => {
        const map = new Map<string, any[]>();
        noeuds.forEach((node) => {
            const key = String(node.parent_id ?? 'racine');
            map.set(key, [...(map.get(key) ?? []), node]);
        });

        return map;
    }, [noeuds]);

    function choisir(node) {
        setSelected(node);
        setForm({
            ...VIDE,
            type: node.type,
            parent_id: node.parent_id ? String(node.parent_id) : '',
            libelle: node.libelle || '',
            description: node.description || '',
            objectifs: node.objectifs || '',
            resultats_attendus: node.resultats_attendus || '',
            organization_unit_id: node.organization_unit_id ? String(node.organization_unit_id) : '',
            periode: node.periode || '',
            date_debut: node.date_debut || '',
            date_fin: node.date_fin || '',
            indicateur: node.indicateur || '',
            unite_mesure: node.unite_mesure || '',
            cible: node.cible || '',
            enveloppe: node.enveloppe ? String(node.enveloppe) : '',
        });
        setContributeurs((node.contributeurs ?? []).map((unit: { id: number }) => unit.id));
    }

    function nouveau() {
        setSelected(null);
        setForm({ ...VIDE, type: 'pilier', parent_id: '' });
        setContributeurs([]);
    }

    async function executer(key: string, request: () => Promise<unknown>, success: string) {
        setError('');
        setPending(key);
        try {
            await request();
            setMotif('');
            toast.success(success);
            load();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    function payload() {
        return {
            type: form.type,
            parent_id: form.parent_id ? Number(form.parent_id) : null,
            libelle: form.libelle,
            description: form.description || null,
            objectifs: form.objectifs || null,
            resultats_attendus: form.resultats_attendus || null,
            organization_unit_id: form.organization_unit_id ? Number(form.organization_unit_id) : null,
            periode: form.periode || null,
            date_debut: form.date_debut || null,
            date_fin: form.date_fin || null,
            indicateur: form.indicateur || null,
            unite_mesure: form.unite_mesure || null,
            cible: form.cible || null,
            enveloppe: form.enveloppe ? Number(form.enveloppe) : 0,
            justification: form.justification || null,
            contributeurs,
        };
    }

    function enregistrer() {
        const versionId = portrait?.version?.id;
        if (!versionId) {
            return;
        }
        if (selected) {
            executer('save', () => api.patch(`/planification/noeuds/${selected.id}`, payload()), 'Nœud mis à jour.');
            return;
        }
        executer('save', () => api.post(`/planification/versions/${versionId}/noeuds`, payload()), 'Nœud créé.');
    }

    async function publier() {
        if (await confirm({ title: 'Publier la chaîne de résultats', description: `La version publiée devient immuable à compter du ${effet}. Toute modification ultérieure passera par un avenant.`, confirmLabel: 'Publier', tone: 'success', icon: ICON.validate })) {
            executer('publier', () => api.post(`/planification/versions/${version.id}/publier`, { date_effet: effet }), 'Version publiée.');
        }
    }

    async function archiver() {
        if (await confirm({ title: 'Archiver le nœud', description: `${selected.code} · ${selected.libelle}`, confirmLabel: 'Archiver', tone: 'warning', icon: ICON.archive })) {
            executer('archiver', () => api.post(`/planification/noeuds/${selected.id}/archiver`, { motif: motif || 'Archivage du nœud' }), 'Nœud archivé.');
        }
    }

    function rendu(parentId: string, depth: number) {
        return (enfants.get(parentId) ?? []).map((node) => (
            <li key={node.id}>
                <button
                    type="button"
                    onClick={() => choisir(node)}
                    className={['tree-node', selected?.id === node.id && 'is-selected', node.statut === 'archive' && 'is-archived'].filter(Boolean).join(' ')}
                    style={{ paddingLeft: 10 + depth * 18 }}
                    aria-current={selected?.id === node.id ? 'true' : undefined}
                >
                    {depth > 0 && <FontAwesomeIcon icon={faChevronRight} style={{ fontSize: 8, color: 'var(--slate-300)' }} />}
                    <FontAwesomeIcon icon={TYPE_ICONS[node.type] ?? faSitemap} className="tree-node-icon" />
                    <span className="tree-node-code">{node.code}</span>
                    <span className="truncate" style={{ flex: 1 }}>{node.libelle}</span>
                    <span className="subtle">{LIBELLES[node.type] || node.type}</span>
                </button>
                <ul>{rendu(String(node.id), depth + 1)}</ul>
            </li>
        ));
    }

    if (!portrait) {
        return loadError ? <PageError message={loadError} onRetry={load} /> : <PageSkeleton />;
    }

    const droits = portrait.droits || {};
    const version = portrait.version;
    const parents = noeuds.filter((node) => node.statut !== 'archive');
    const set = (key: keyof typeof VIDE) => (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value });

    return (
        <main className="app-content">
            <PageHeader
                eyebrow={<><FontAwesomeIcon icon={ICON.planning} /> Gestion axée sur les résultats</>}
                title="Planification stratégique · GAR/RBM"
                subtitle={`Chaîne de résultats de l’exercice ${portrait.exercice?.annee ?? ''}. Une version publiée reste immuable ; une modification ouvre un avenant.`}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            <SectionCard
                title={version ? `Version ${version.numero}` : 'Aucune chaîne de résultats'}
                icon={ICON.planning}
                tag={version && <Badge tone={VERSION_TONE[version.statut] ?? 'neutral'} dot>{LIBELLES[version.statut] || version.statut}</Badge>}
                subtitle={version?.effet ? `Effet au ${version.effet}` : undefined}
            >
                <div className="cluster" style={{ alignItems: 'flex-end' }}>
                    {(droits.soumettre || droits.avenant || (droits.editer && selected)) && (
                        <FormField label="Justification" optional hint="Reprise pour la soumission, l’avenant ou l’archivage." style={{ flex: '1 1 300px' }}>
                            <input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} />
                        </FormField>
                    )}
                    {droits.publier && (
                        <FormField label="Date d’effet" required>
                            <input className="inp" type="date" value={effet} onChange={(event) => setEffet(event.target.value)} />
                        </FormField>
                    )}
                    <div className="btn-group">
                        {droits.initialiser && <Button variant="brand" icon={ICON.transform} loading={pending === 'init'} onClick={() => executer('init', () => api.post('/planification/initialiser'), 'Chaîne PAP reprise.')}>Reprendre la chaîne PAP</Button>}
                        {droits.soumettre && <Button variant="primary" icon={ICON.submit} loading={pending === 'soumettre'} onClick={() => executer('soumettre', () => api.post(`/planification/versions/${version.id}/soumettre`, { justification: motif || 'Soumission de la chaîne GAR' }), 'Version soumise à validation.')}>Soumettre</Button>}
                        {droits.valider && <Button variant="primary" icon={ICON.validate} loading={pending === 'valider'} onClick={() => executer('valider', () => api.post(`/planification/versions/${version.id}/valider`), 'Version validée.')}>Valider</Button>}
                        {droits.publier && <Button variant="primary" icon={ICON.validate} loading={pending === 'publier'} onClick={publier}>Publier</Button>}
                        {droits.avenant && <Button icon={ICON.edit} loading={pending === 'avenant'} onClick={() => executer('avenant', () => api.post(`/planification/versions/${version.id}/avenant`, { justification: motif || 'Avenant de la chaîne GAR' }), 'Avenant ouvert.')}>Ouvrir un avenant</Button>}
                    </div>
                </div>
            </SectionCard>

            <div className="grid-halves">
                <SectionCard
                    title="Arborescence"
                    icon={faSitemap}
                    subtitle={`${noeuds.length} nœud(s)`}
                    actions={droits.editer && <Button size="sm" icon={ICON.create} onClick={nouveau}>Nouveau nœud</Button>}
                >
                    {noeuds.length === 0 ? (
                        <EmptyState icon={faSitemap} title="Aucun nœud" compact>Reprenez d’abord la chaîne PAP déjà portée par les lignes budgétaires.</EmptyState>
                    ) : (
                        <ul className="tree" role="tree" aria-label="Chaîne de résultats">{rendu('racine', 0)}</ul>
                    )}
                </SectionCard>

                <SectionCard
                    title={selected ? <><span className="mono">{selected.code}</span> · {LIBELLES[selected.type]}</> : 'Nouveau nœud'}
                    icon={selected ? (TYPE_ICONS[selected.type] ?? faSitemap) : ICON.create}
                    tag={selected && <Badge tone={VERSION_TONE[selected.statut] ?? 'neutral'} size="sm">{LIBELLES[selected.statut] || selected.statut}</Badge>}
                    subtitle={selected ? `Enveloppe ${fcfa(selected.enveloppe)} FCFA` : undefined}
                    footer={droits.editer && (
                        <div className="form-actions" style={{ width: '100%' }}>
                            {selected && selected.statut !== 'archive' && <Button variant="warning" icon={ICON.archive} loading={pending === 'archiver'} onClick={archiver}>Archiver</Button>}
                            <Button variant="primary" icon={ICON.save} loading={pending === 'save'} onClick={enregistrer} disabled={!form.libelle.trim()}>Enregistrer</Button>
                        </div>
                    )}
                >
                    {!droits.editer && <Alert tone="neutral" icon={ICON.lock}>Cette version n’est pas modifiable.</Alert>}
                    <fieldset disabled={!droits.editer} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
                        <div className="form-grid">
                            {!selected && (
                                <FormField label="Type de nœud" required>
                                    <select className="inp" value={form.type} onChange={set('type')}>
                                        {portrait.types.map((type: string) => <option key={type} value={type}>{LIBELLES[type]}</option>)}
                                    </select>
                                </FormField>
                            )}
                            <FormField label="Parent" className={selected ? 'span-all' : undefined}>
                                <select className="inp" value={form.parent_id} onChange={set('parent_id')}>
                                    <option value="">Aucun (pilier)</option>
                                    {parents.map((node) => <option key={node.id} value={node.id}>{node.code} · {node.libelle}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Libellé" required className="span-all"><input className="inp" value={form.libelle} onChange={set('libelle')} /></FormField>
                            <FormField label="Description" className="span-all"><textarea className="inp" rows={2} value={form.description} onChange={set('description')} /></FormField>
                            <FormField label="Objectifs"><textarea className="inp" rows={2} value={form.objectifs} onChange={set('objectifs')} /></FormField>
                            <FormField label="Résultats attendus"><textarea className="inp" rows={2} value={form.resultats_attendus} onChange={set('resultats_attendus')} /></FormField>
                            <FormField label="Unité responsable" className="span-all">
                                <select className="inp" value={form.organization_unit_id} onChange={set('organization_unit_id')}>
                                    <option value="">Non renseignée</option>
                                    {(portrait.structures ?? []).map((unit) => <option key={unit.id} value={unit.id}>{unit.sigle} · {unit.nom}</option>)}
                                </select>
                            </FormField>
                            <FormField label="Unités contributrices" className="span-all" optional>
                                <MultiCheck
                                    options={(portrait.structures ?? []).map((unit) => ({ value: Number(unit.id), label: <><span className="strong">{unit.sigle}</span> · {unit.nom}</>, text: `${unit.sigle} ${unit.nom}` }))}
                                    value={contributeurs}
                                    onChange={setContributeurs}
                                    searchPlaceholder="Filtrer les unités…"
                                    maxHeight={200}
                                />
                            </FormField>
                            <FormField label="Période"><input className="inp" value={form.periode} onChange={set('periode')} /></FormField>
                            <FormField label="Indicateur"><input className="inp" value={form.indicateur} onChange={set('indicateur')} /></FormField>
                            <FormField label="Cible"><input className="inp" value={form.cible} onChange={set('cible')} /></FormField>
                            <FormField label="Enveloppe indicative">
                                <AmountInput value={form.enveloppe} onChange={(value) => setForm({ ...form, enveloppe: value })} />
                            </FormField>
                        </div>
                    </fieldset>
                </SectionCard>
            </div>
        </main>
    );
}
