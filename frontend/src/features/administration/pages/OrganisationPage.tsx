import { FormEvent, useEffect, useMemo, useState } from 'react';
import api from '../../../api/httpClient';
import { Button, DataTable, EmptyState, ErrorMessage, FormField, ICON, Modal, OpenCell, PageHeader, SectionCard, StatusBadge, useToast } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Noeud = { id: number; code: string; nom: string; type: string; type_libelle: string; actif: boolean; enfants?: Noeud[] };

const vide = { sigle: '', name: '', kind: 'service', parent_id: '', description: '', sort_order: '0', effective_on: '' };

export default function OrganisationPage() {
    const toast = useToast();
    const [arbre, setArbre] = useState<any>(null);
    const [fiche, setFiche] = useState<any>(null);
    const [fonctions, setFonctions] = useState<any[]>([]);
    const [error, setError] = useState('');
    const [ouvert, setOuvert] = useState<Record<number, boolean>>({});
    const [vue, setVue] = useState<'arbre' | 'organigramme'>('arbre');
    const [modal, setModal] = useState(false);
    const [form, setForm] = useState(vide);
    const [edition, setEdition] = useState({ name: '', kind: 'service', parent_id: '', sort_order: '0', description: '' });
    const [pending, setPending] = useState<string | null>(null);
    const [q, setQ] = useState('');

    function charger() {
        api.get('/organisation/arbre').then((response) => { setArbre(response.data); setError(''); }).catch((caught) => setError(errorsOf(caught)));
        api.get('/organisation/fonctions').then((response) => setFonctions(response.data.data ?? [])).catch(() => setFonctions([]));
    }

    useEffect(() => { charger(); }, []);

    function ouvrir(id: number) {
        api.get(`/organisation/unites/${id}`).then((response) => {
            setFiche(response.data);
            const row = response.data.data;
            setEdition({ name: row.nom ?? '', kind: row.type ?? 'service', parent_id: row.parent_id ? String(row.parent_id) : '', sort_order: String(row.ordre ?? 0), description: row.description ?? '' });
        }).catch((caught) => setError(errorsOf(caught)));
    }

    const plats = useMemo(() => {
        const rows: Noeud[] = [];
        const walk = (nodes: Noeud[]) => nodes.forEach((node) => { rows.push(node); walk(node.enfants ?? []); });
        walk(arbre?.data ?? []);
        return rows;
    }, [arbre]);

    const visibles = q.trim() === '' ? null : plats.filter((node) => `${node.code} ${node.nom}`.toLowerCase().includes(q.trim().toLowerCase()));

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        setPending('sauver');
        try {
            await api.post('/organisation/unites', {
                ...form,
                parent_id: form.parent_id ? Number(form.parent_id) : null,
                sort_order: Number(form.sort_order || 0),
                effective_on: form.effective_on || null,
            });
            toast.success('Structure enregistrée.');
            setModal(false);
            setForm(vide);
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    async function basculer(actif: boolean) {
        if (!fiche?.data) {
            return;
        }
        setPending('statut');
        try {
            await api.post(`/organisation/unites/${fiche.data.id}/${actif ? 'activer' : 'desactiver'}`);
            toast.success(actif ? 'Structure activée.' : 'Structure désactivée.');
            ouvrir(fiche.data.id);
            charger();
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(null);
        }
    }

    function rendu(nodes: Noeud[], profondeur = 0) {
        return nodes.map((node) => {
            const enfants = node.enfants ?? [];
            const estOuvert = ouvert[node.id] ?? profondeur < 2;
            return (
                <li key={node.id}>
                    <div className="cluster" style={{ paddingLeft: profondeur * 16 }}>
                        {enfants.length > 0 ? <Button size="sm" onClick={() => setOuvert((state) => ({ ...state, [node.id]: !estOuvert }))}>{estOuvert ? '−' : '+'}</Button> : <span className="muted">·</span>}
                        <button type="button" className="btn btn-link" onClick={() => ouvrir(node.id)}>{node.code}</button>
                        <span>{node.nom}</span>
                        <span className="muted">{node.type_libelle}</span>
                        {!node.actif && <StatusBadge statut="annule" libelle="Inactive" />}
                    </div>
                    {estOuvert && enfants.length > 0 && <ul className="stack" style={{ listStyle: 'none', margin: 0 }}>{rendu(enfants, profondeur + 1)}</ul>}
                </li>
            );
        });
    }

    const droits = arbre?.droits ?? {};
    const data = fiche?.data;

    return (
        <main className="app-content">
            <PageHeader
                title="Référentiel organisationnel"
                subtitle={arbre?.version ? `${arbre.version.libelle} · ${arbre.version.document} · effet ${arbre.version.date_effet}` : 'Organigramme officiel de la Commission.'}
                actions={droits.gerer && <Button variant="primary" icon={ICON.create} onClick={() => { setForm({ ...vide, parent_id: data ? String(data.id) : '' }); setModal(true); }}>Nouvelle structure</Button>}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <div className="cluster">
                <Button variant={vue === 'arbre' ? 'primary' : 'secondary'} onClick={() => setVue('arbre')}>Arborescence</Button>
                <Button variant={vue === 'organigramme' ? 'primary' : 'secondary'} onClick={() => setVue('organigramme')}>Organigramme</Button>
                <input className="inp" placeholder="Code ou libellé" value={q} onChange={(event) => setQ(event.target.value)} />
            </div>
            <div className="grid-thirds">
                <SectionCard title={vue === 'arbre' ? 'Arborescence' : 'Organigramme'} icon={ICON.administration}>
                    {visibles ? (
                        <ul className="stack" style={{ listStyle: 'none', margin: 0 }}>{visibles.map((node) => <li key={node.id}><button type="button" className="btn btn-link" onClick={() => ouvrir(node.id)}>{node.code} · {node.nom}</button></li>)}</ul>
                    ) : vue === 'arbre' ? (
                        <ul className="stack" style={{ listStyle: 'none', margin: 0 }}>{rendu(arbre?.data ?? [])}</ul>
                    ) : (
                        <div style={{ overflow: 'auto' }}>{(arbre?.data ?? []).map((racine: Noeud) => (
                            <div key={racine.id} className="stack">
                                <Button onClick={() => ouvrir(racine.id)}>{racine.code}</Button>
                                <div className="cluster" style={{ alignItems: 'flex-start' }}>
                                    {(racine.enfants ?? []).map((enfant) => (
                                        <div key={enfant.id} className="stack">
                                            <Button size="sm" onClick={() => ouvrir(enfant.id)}>{enfant.code}</Button>
                                            {(enfant.enfants ?? []).slice(0, 8).map((feuille) => <Button key={feuille.id} size="sm" onClick={() => ouvrir(feuille.id)}>{feuille.code}</Button>)}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}</div>
                    )}
                </SectionCard>
                <SectionCard title={data ? data.code : 'Détail'} icon={ICON.budget}>
                    {data ? (
                        <div className="stack">
                            <strong>{data.nom}</strong>
                            <span>{data.type_libelle} · ordre {data.ordre}</span>
                            <span>Parent : {data.parent ? `${data.parent.sigle} · ${data.parent.nom}` : '—'}</span>
                            <span>Responsable : {data.responsable ? `${data.responsable.nom} · ${data.responsable.fonction}` : 'Non affecté'}</span>
                            <span>Chemin : {(data.chemin ?? []).map((row: Noeud) => row.code).join(' → ') || '—'}</span>
                            {droits.gerer && (
                                <form className="form-grid" onSubmit={(event) => { event.preventDefault(); setPending('modifier'); api.patch(`/organisation/unites/${data.id}`, { name: edition.name, kind: edition.kind, parent_id: edition.parent_id ? Number(edition.parent_id) : null, sort_order: Number(edition.sort_order || 0), description: edition.description || null }).then(() => { toast.success('Structure mise à jour.'); ouvrir(data.id); charger(); }).catch((caught) => setError(errorsOf(caught))).finally(() => setPending(null)); }}>
                                    <FormField label="Libellé"><input className="inp" value={edition.name} onChange={(event) => setEdition({ ...edition, name: event.target.value })} /></FormField>
                                    <FormField label="Type"><select className="inp" value={edition.kind} onChange={(event) => setEdition({ ...edition, kind: event.target.value })}>{Object.entries(arbre?.types ?? {}).map(([value, label]) => <option key={value} value={value}>{String(label)}</option>)}</select></FormField>
                                    <FormField label="Parent"><select className="inp" value={edition.parent_id} onChange={(event) => setEdition({ ...edition, parent_id: event.target.value })}><option value="">Aucune</option>{plats.filter((row) => row.id !== data.id).map((row) => <option key={row.id} value={row.id}>{row.code}</option>)}</select></FormField>
                                    <FormField label="Ordre"><input className="inp" value={edition.sort_order} onChange={(event) => setEdition({ ...edition, sort_order: event.target.value })} /></FormField>
                                    <Button type="submit" loading={pending === 'modifier'}>Enregistrer</Button>
                                    <Button type="button" loading={pending === 'statut'} onClick={() => basculer(!data.actif)}>{data.actif ? 'Désactiver' : 'Activer'}</Button>
                                </form>
                            )}
                            <h3 className="card-title">Enfants</h3>
                            <DataTable
                                columns={[
                                    { key: 'code', header: 'Code', render: (row: Noeud) => <span className="cell-ref">{row.code}</span> },
                                    { key: 'nom', header: 'Structure', render: (row: Noeud) => row.nom },
                                    { key: 'ouvrir', header: 'Ouvrir', srHeader: true, align: 'right', width: 48, render: () => <OpenCell /> },
                                ]}
                                rows={data.enfants ?? []}
                                rowKey={(row: Noeud) => row.id}
                                onRowClick={(row: Noeud) => ouvrir(row.id)}
                                rowLabel={(row: Noeud) => `Ouvrir ${row.code}`}
                                compact
                                empty={<EmptyState icon={ICON.roles} title="Aucune structure rattachée" compact />}
                            />
                        </div>
                    ) : <p className="muted">Sélectionnez une structure.</p>}
                </SectionCard>
                <SectionCard title="Fonctions" icon={ICON.roles} subtitle={`${fonctions.length} fonction(s)`} flush>
                    <DataTable
                        columns={[
                            { key: 'code', header: 'Code', render: (row: any) => <span className="cell-ref">{row.code}</span> },
                            { key: 'libelle', header: 'Libellé', render: (row: any) => row.libelle },
                        ]}
                        rows={fonctions}
                        rowKey={(row: any) => row.id}
                        compact
                        empty={<EmptyState icon={ICON.roles} title="Aucune fonction" compact />}
                    />
                </SectionCard>
            </div>
            {modal && (
                <Modal title="Nouvelle structure" onClose={() => setModal(false)} footer={<><Button onClick={() => setModal(false)}>Annuler</Button><Button variant="primary" type="submit" form="org-form" loading={pending === 'sauver'}>Enregistrer</Button></>}>
                    <form id="org-form" className="form-grid" onSubmit={enregistrer}>
                        <FormField label="Code" required><input className="inp mono" required value={form.sigle} onChange={(event) => setForm({ ...form, sigle: event.target.value })} /></FormField>
                        <FormField label="Libellé" required><input className="inp" required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></FormField>
                        <FormField label="Type" required><select className="inp" value={form.kind} onChange={(event) => setForm({ ...form, kind: event.target.value })}>{Object.entries(arbre?.types ?? {}).map(([value, label]) => <option key={value} value={value}>{String(label)}</option>)}</select></FormField>
                        <FormField label="Structure parente"><select className="inp" value={form.parent_id} onChange={(event) => setForm({ ...form, parent_id: event.target.value })}><option value="">Aucune</option>{plats.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.nom}</option>)}</select></FormField>
                        <FormField label="Ordre"><input className="inp" inputMode="numeric" value={form.sort_order} onChange={(event) => setForm({ ...form, sort_order: event.target.value })} /></FormField>
                        <FormField label="Date d’effet"><input className="inp" type="date" value={form.effective_on} onChange={(event) => setForm({ ...form, effective_on: event.target.value })} /></FormField>
                        <FormField label="Description" className="span-all"><textarea className="inp" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></FormField>
                    </form>
                </Modal>
            )}
        </main>
    );
}
