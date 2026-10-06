import { faClipboardCheck, faFileCirclePlus, faSnowflake } from '@fortawesome/free-solid-svg-icons';
import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    ActionMenu,
    Badge,
    Button,
    CheckCard,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    KeyValueList,
    PageHeader,
    SectionCard,
    useDialogs,
    useToast,
    type Column,
} from '../../../components/ui';

const TYPES: Array<[string, string]> = [
    ['trimestriel', 'Trimestriel'],
    ['mensuel', 'Mensuel'],
    ['semestriel', 'Semestriel'],
    ['annuel', 'Annuel'],
    ['pap', 'Exécution du PAP'],
    ['performance', 'Performance'],
    ['physique_financier', 'Physique / financier'],
];

const STATUTS: Record<string, { label: string; tone: string }> = {
    brouillon: { label: 'Brouillon', tone: 'neutral' },
    en_revue: { label: 'En revue', tone: 'warning' },
    valide: { label: 'Validé', tone: 'info' },
    publie: { label: 'Publié', tone: 'success' },
};

function messageErreur(error: any): string {
    const details = error?.response?.data?.errors;

    return details ? Object.values(details).flat().join(' ') : error?.response?.data?.message ?? 'Action refusée.';
}

export default function RapportsPage() {
    const toast = useToast();
    const { prompt } = useDialogs();
    const [rapports, setRapports] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [periodes, setPeriodes] = useState<any[]>([]);
    const [apercu, setApercu] = useState<any>(null);
    const [erreur, setErreur] = useState('');
    const [pending, setPending] = useState(false);

    function charger() {
        setLoading(true);
        api.get('/suivi/rapports-performance').then((response) => setRapports(response.data.data)).finally(() => setLoading(false));
    }

    useEffect(() => {
        api.get('/suivi/periodes').then((response) => setPeriodes(response.data.data));
        charger();
    }, []);

    async function generer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setErreur('');
        setPending(true);
        const form = new FormData(event.currentTarget);
        try {
            const response = await api.post('/suivi/rapports-performance', {
                kind: form.get('type'),
                monitoring_period_id: form.get('periode') ? Number(form.get('periode')) : null,
                commentaire: form.get('commentaire') || null,
            });
            setApercu(response.data.data);
            toast.success('Rapport généré : les données sont figées.');
            charger();
        } catch (error) {
            setErreur(messageErreur(error));
        } finally {
            setPending(false);
        }
    }

    async function action(rapport: any, etape: string) {
        setErreur('');
        let motif = '';
        if (etape === 'retourner' || etape === 'nouvelle-version') {
            const values = await prompt({
                title: etape === 'retourner' ? 'Retourner le rapport' : 'Créer une nouvelle version',
                description: `${rapport.reference} · v${rapport.version}`,
                confirmLabel: etape === 'retourner' ? 'Retourner' : 'Créer la version',
                tone: etape === 'retourner' ? 'warning' : 'default',
                fields: [etape === 'retourner'
                    ? { name: 'motif', label: 'Motif du retour', type: 'textarea', required: true }
                    : { name: 'motif', label: 'Objet de la nouvelle version', type: 'textarea', hint: 'Facultatif.' }],
            });
            if (!values) return;
            motif = values.motif;
        }
        try {
            if (etape === 'nouvelle-version') {
                await api.post(`/suivi/rapports-performance/${rapport.id}/nouvelle-version`, { commentaire: motif || null });
            } else {
                await api.post(`/suivi/rapports-performance/${rapport.id}/${etape}`, etape === 'retourner' ? { motif } : {});
            }
            toast.success('Rapport mis à jour.');
            charger();
        } catch (error) {
            setErreur(messageErreur(error));
            toast.error(messageErreur(error));
        }
    }

    function ouvrir(rapport: any) {
        api.get(`/suivi/rapports-performance/${rapport.id}`).then((response) => setApercu(response.data.data));
    }

    const synthese = apercu?.snapshot?.synthese;

    const columns: Column<any>[] = [
        { key: 'reference', header: 'Référence', render: (rapport) => <span className="cell-ref">{rapport.reference} <Badge tone="brand" size="sm">v{rapport.version}</Badge></span> },
        { key: 'titre', header: 'Titre', render: (rapport) => <span style={{ fontWeight: 500 }}>{rapport.titre}</span> },
        { key: 'situation', header: 'Situation au', className: 'mono', render: (rapport) => rapport.situation_au },
        {
            key: 'statut',
            header: 'Statut',
            render: (rapport) => {
                const statut = STATUTS[rapport.statut] ?? { label: rapport.statut, tone: 'neutral' };
                return <div><Badge tone={statut.tone} dot>{statut.label}</Badge>{rapport.motif_retour && <div className="cell-sub">{rapport.motif_retour}</div>}</div>;
            },
        },
        { key: 'etabli', header: 'Établi par · validé par', render: (rapport) => <div><div>{rapport.etabli_par}</div><div className="cell-sub">{rapport.valide_par ?? 'Non validé'}</div></div> },
        {
            key: 'actions',
            header: 'Actions',
            srHeader: true,
            className: 'cell-actions',
            render: (rapport) => (
                <div className="btn-group" style={{ justifyContent: 'flex-end', flexWrap: 'nowrap' }}>
                    {rapport.statut === 'brouillon' && <Button size="sm" variant="primary" icon={ICON.submit} onClick={() => action(rapport, 'soumettre')}>Soumettre</Button>}
                    {rapport.statut === 'en_revue' && <Button size="sm" variant="primary" icon={ICON.validate} onClick={() => action(rapport, 'valider')}>Valider</Button>}
                    {rapport.statut === 'valide' && <Button size="sm" variant="primary" icon={ICON.validate} onClick={() => action(rapport, 'publier')}>Publier</Button>}
                    <ActionMenu label={`Actions sur ${rapport.reference}`} actions={[
                        { label: 'Aperçu des données figées', icon: ICON.view, onSelect: () => ouvrir(rapport) },
                        { label: 'PDF officiel', icon: ICON.pdf, href: `/api/v1/suivi/rapports-performance/${rapport.id}/pdf`, hidden: rapport.statut !== 'publie' },
                        { label: 'Retourner', icon: ICON.return, onSelect: () => action(rapport, 'retourner'), hidden: rapport.statut !== 'en_revue' },
                        { label: 'Nouvelle version', icon: ICON.duplicate, onSelect: () => action(rapport, 'nouvelle-version'), hidden: !['valide', 'publie'].includes(rapport.statut) },
                    ]} />
                </div>
            ),
        },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Rapports de performance"
                subtitle="Un rapport fige les données au moment de sa génération. Il est revu, validé par un autre acteur que son auteur, puis publié : le PDF officiel est alors archivé avec son empreinte et son code de vérification."
            />
            <ErrorMessage error={erreur} onClose={() => setErreur('')} />

            <SectionCard title="Générer un rapport" icon={faFileCirclePlus}>
                <form className="form-grid" style={{ ['--cols' as string]: 3, alignItems: 'end' }} onSubmit={generer}>
                    <FormField label="Type" required>
                        <select className="inp" name="type" defaultValue="trimestriel">
                            {TYPES.map(([valeur, nom]) => <option key={valeur} value={valeur}>{nom}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Période">
                        <select className="inp" name="periode" defaultValue="">
                            <option value="">Sans période</option>
                            {periodes.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}
                        </select>
                    </FormField>
                    <FormField label="Commentaire" optional><input className="inp" name="commentaire" /></FormField>
                    <div className="form-actions span-all">
                        <Button variant="primary" type="submit" icon={faFileCirclePlus} loading={pending}>Générer le rapport</Button>
                    </div>
                </form>
            </SectionCard>

            <SectionCard title="Rapports" icon={ICON.report} flush>
                <DataTable
                    columns={columns}
                    rows={rapports}
                    rowKey={(rapport) => rapport.id}
                    loading={loading}
                    minWidth={920}
                    empty={<EmptyState icon={ICON.report} title="Aucun rapport">Générez un premier rapport pour figer la situation de la période.</EmptyState>}
                />
            </SectionCard>

            {synthese && (
                <SectionCard
                    title={`${apercu.titre} · ${apercu.reference} v${apercu.version}`}
                    icon={faSnowflake}
                    subtitle={`Données figées le ${apercu.snapshot.genere_le} · périmètre ${apercu.snapshot.perimetre}`}
                    actions={<Button size="sm" variant="ghost" icon={ICON.close} onClick={() => setApercu(null)}>Fermer l’aperçu</Button>}
                >
                    <div className="grid-halves">
                        <KeyValueList compact items={[
                            { label: 'Exécution physique', value: `${synthese.physique} %` },
                            { label: 'Exécution financière', value: `${synthese.financier} %` },
                            { label: 'Écart', value: `${synthese.ecart} points`, warning: true },
                            { label: 'Activités', value: synthese.activites },
                            { label: 'Dont critiques', value: synthese.critiques, warning: synthese.critiques > 0 },
                        ]} />
                        <KeyValueList compact items={[
                            { label: 'Risques critiques', value: synthese.risques_critiques, warning: synthese.risques_critiques > 0 },
                            { label: 'Recommandations échues', value: synthese.recommandations_echues, warning: synthese.recommandations_echues > 0 },
                            { label: 'Mesures en retard', value: synthese.mesures_en_retard, warning: synthese.mesures_en_retard > 0 },
                        ]} />
                    </div>
                </SectionCard>
            )}

            <FicheEvaluation />
        </main>
    );
}

/** Fiche d’évaluation (mi-parcours, finale) selon les critères du référentiel. */
function FicheEvaluation() {
    const toast = useToast();
    const [criteres, setCriteres] = useState<any[]>([]);
    const [choisis, setChoisis] = useState<string[]>([]);
    const [erreur, setErreur] = useState('');
    const [pending, setPending] = useState(false);

    useEffect(() => {
        api.get('/suivi/referentiels/critere').then((response) => setCriteres(response.data.data.filter((row: any) => row.active))).catch(() => setCriteres([]));
    }, []);

    async function evaluer(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formulaire = event.currentTarget;
        const form = new FormData(formulaire);
        setErreur('');
        setPending(true);
        try {
            await api.post('/suivi/evaluations', {
                subject: form.get('objet'),
                type: form.get('type'),
                evaluator_role: 'directeur',
                criteria: choisis,
                conclusions: form.get('conclusions'),
            });
            toast.success('Évaluation enregistrée.');
            formulaire.reset();
            setChoisis([]);
        } catch (error) {
            setErreur(messageErreur(error));
        } finally {
            setPending(false);
        }
    }

    return (
        <SectionCard title="Fiche d’évaluation" icon={faClipboardCheck} subtitle="Évaluation à mi-parcours ou finale selon les critères du référentiel.">
            <form className="stack" onSubmit={evaluer}>
                <div className="form-grid">
                    <FormField label="Objet" required><input className="inp" name="objet" /></FormField>
                    <FormField label="Type" required>
                        <select className="inp" name="type" defaultValue="mi_parcours"><option value="mi_parcours">Mi-parcours</option><option value="finale">Finale</option></select>
                    </FormField>
                    <FormField label="Conclusions" className="span-all"><textarea className="inp" rows={3} name="conclusions" /></FormField>
                </div>
                <fieldset className="stack-sm" style={{ border: 0, padding: 0, margin: 0 }}>
                    <legend className="field-label" style={{ marginBottom: 8 }}>Critères évalués</legend>
                    {criteres.length === 0 ? <span className="subtle">Aucun critère actif dans le référentiel.</span> : (
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8 }}>
                            {criteres.map((row) => (
                                <CheckCard key={row.code} checked={choisis.includes(row.code)} onChange={(checked) => setChoisis((courant) => checked ? [...courant, row.code] : courant.filter((code) => code !== row.code))}>
                                    {row.label}
                                </CheckCard>
                            ))}
                        </div>
                    )}
                </fieldset>
                <ErrorMessage error={erreur} title="Évaluation refusée" />
                <div className="form-actions">
                    <Button variant="primary" type="submit" icon={ICON.save} loading={pending}>Enregistrer l’évaluation</Button>
                </div>
            </form>
        </SectionCard>
    );
}
