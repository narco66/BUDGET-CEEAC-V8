import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Badge, Button, DataTable, EmptyState, ErrorMessage, ICON, PageError, PageHeader, PageSkeleton, SearchInput, SectionCard, StatusBadge, type Column } from '../../../components/ui';
import { errorsOf, fcfa } from '../../../utils/format';

type Resume = {
    id: number;
    reference: string;
    objet?: string;
    statut: string;
    statut_libelle?: string;
    montant: number;
    exercice?: number;
    unite?: string;
    engagements: number;
};

type Paiement = { id: number; reference: string; statut: string; statut_libelle?: string; montant: number; montant_paye: number; titulaire?: string };
type Ordre = { id: number; reference: string; statut: string; statut_libelle?: string; montant: number; paiement: Paiement | null };
type Liquidation = { id: number; reference: string; statut: string; statut_libelle?: string; montant: number; ordonnancements: Ordre[] };
type Engagement = { id: number; reference: string; statut: string; statut_libelle?: string; montant: number; liquidations: Liquidation[] };
type Dossier = Resume & { ligne?: string; engagements: Engagement[] };

/** Une ligne par maillon, de l’engagement au paiement. */
type Maillon = { cle: string; type: 'ENG' | 'LIQ' | 'ORD' | 'PAY'; niveau: number; reference: string; lien: string; statut: string; libelle?: string; montant: number; detail?: string };

function maillons(dossier: Dossier): Maillon[] {
    const lignes: Maillon[] = [];
    dossier.engagements.forEach((engagement) => {
        lignes.push({ cle: `e${engagement.id}`, type: 'ENG', niveau: 0, reference: engagement.reference, lien: `/engagements/${engagement.id}`, statut: engagement.statut, libelle: engagement.statut_libelle, montant: engagement.montant });
        engagement.liquidations.forEach((liquidation) => {
            lignes.push({ cle: `l${liquidation.id}`, type: 'LIQ', niveau: 1, reference: liquidation.reference, lien: `/liquidations/${liquidation.id}`, statut: liquidation.statut, libelle: liquidation.statut_libelle, montant: liquidation.montant });
            liquidation.ordonnancements.forEach((ordre) => {
                lignes.push({ cle: `o${ordre.id}`, type: 'ORD', niveau: 2, reference: ordre.reference, lien: `/ordonnancements/${ordre.id}`, statut: ordre.statut, libelle: ordre.statut_libelle, montant: ordre.montant });
                if (ordre.paiement) {
                    const paiement = ordre.paiement;
                    lignes.push({
                        cle: `p${paiement.id}`,
                        type: 'PAY',
                        niveau: 3,
                        reference: paiement.reference,
                        lien: `/paiements/${paiement.id}`,
                        statut: paiement.statut,
                        libelle: paiement.statut_libelle,
                        montant: paiement.montant,
                        detail: `Payé ${fcfa(paiement.montant_paye)} FCFA${paiement.titulaire ? ` · ${paiement.titulaire}` : ''}`,
                    });
                }
            });
        });
    });

    return lignes;
}

export default function DossierChainePage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [terme, setTerme] = useState('');
    const [resultats, setResultats] = useState<Resume[] | null>(null);
    const [dossier, setDossier] = useState<Dossier | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(Boolean(id));

    useEffect(() => {
        if (!id) {
            setDossier(null);
            setLoading(false);
            return;
        }
        setLoading(true);
        setError('');
        api.get(`/chaine/dossier/${id}`)
            .then((response) => setDossier(response.data.data))
            .catch((caught) => setError(errorsOf(caught) || 'Dossier indisponible.'))
            .finally(() => setLoading(false));
    }, [id]);

    async function rechercher(event: FormEvent) {
        event.preventDefault();
        setError('');
        setLoading(true);
        try {
            const response = await api.get('/chaine/dossier', { params: { q: terme } });
            setResultats(response.data.data);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setLoading(false);
        }
    }

    if (id && loading && !dossier) {
        return <PageSkeleton />;
    }
    if (id && error && !dossier) {
        return <PageError message={error} />;
    }

    const colonnesResultats: Column<Resume>[] = [
        { key: 'reference', header: 'Expression de besoin', render: (ligne) => <span className="cell-ref">{ligne.reference}</span> },
        { key: 'objet', header: 'Objet', render: (ligne) => <span>{ligne.objet || '—'}{ligne.unite && <span className="cell-sub">{ligne.unite}</span>}</span> },
        { key: 'statut', header: 'Statut', render: (ligne) => <StatusBadge statut={ligne.statut} libelle={ligne.statut_libelle} size="sm" /> },
        { key: 'engagements', header: 'Engagements', align: 'right', render: (ligne) => <span className="num">{ligne.engagements}</span> },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.montant) },
    ];

    const colonnesChaine: Column<Maillon>[] = [
        {
            key: 'reference',
            header: 'Maillon',
            render: (ligne) => (
                <span className="cluster" style={{ paddingLeft: ligne.niveau * 20 }}>
                    <Badge tone="brand" size="sm">{ligne.type}</Badge>
                    <Link className="cell-ref" to={ligne.lien}>{ligne.reference}</Link>
                </span>
            ),
        },
        { key: 'statut', header: 'Statut', render: (ligne) => <StatusBadge statut={ligne.statut} libelle={ligne.libelle} size="sm" /> },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (ligne) => fcfa(ligne.montant) },
        { key: 'detail', header: 'Règlement', render: (ligne) => <span className="cell-sub">{ligne.detail || ''}</span> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: dossier ? '/chaine/dossier' : '/chaine', label: dossier ? 'Dossier financier' : 'Tableau de chaîne' }}
                eyebrow={dossier ? <><span className="mono strong">{dossier.reference}</span><StatusBadge statut={dossier.statut} libelle={dossier.statut_libelle} size="sm" />{dossier.exercice && <span>Exercice {dossier.exercice}</span>}</> : 'Pilotage de la chaîne'}
                title={dossier ? (dossier.objet || dossier.reference) : 'Dossier financier'}
                subtitle={dossier
                    ? [dossier.unite, dossier.ligne ? `ligne ${dossier.ligne}` : null].filter(Boolean).join(' · ')
                    : 'Recherche d’un dossier, de l’expression de besoin jusqu’au paiement, par référence ou par objet.'}
                figure={dossier ? { label: 'Montant du besoin', value: fcfa(dossier.montant), unit: 'FCFA' } : undefined}
                actions={dossier ? <Button to={`/expressions-besoin/${dossier.id}`} variant="primary" iconRight={ICON.open}>Ouvrir l’expression de besoin</Button> : undefined}
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            {!dossier && (
                <SectionCard title="Recherche" icon={ICON.search}>
                    <form onSubmit={rechercher} className="cluster">
                        <div style={{ flex: '1 1 320px' }}>
                            <SearchInput value={terme} onChange={setTerme} placeholder="Référence ou objet du besoin" />
                        </div>
                        <Button type="submit" variant="primary" icon={ICON.search} loading={loading} disabled={!terme.trim()}>Rechercher</Button>
                    </form>
                </SectionCard>
            )}

            {!dossier && resultats && (
                <SectionCard title="Résultats" icon={ICON.need} subtitle={`${resultats.length} dossier(s)`} flush>
                    <DataTable
                        columns={colonnesResultats}
                        rows={resultats}
                        rowKey={(ligne) => ligne.id}
                        onRowClick={(ligne) => navigate(`/chaine/dossier/${ligne.id}`)}
                        rowLabel={(ligne) => `Ouvrir le dossier ${ligne.reference}`}
                        empty={<EmptyState icon={ICON.search} title="Aucun dossier" compact>Aucun besoin ne correspond à cette recherche.</EmptyState>}
                    />
                </SectionCard>
            )}

            {dossier && (
                <SectionCard title="Chaîne de la dépense" icon={ICON.transform} subtitle="De l’engagement au paiement, chaque maillon ouvre sa fiche." flush>
                    <DataTable
                        columns={colonnesChaine}
                        rows={maillons(dossier)}
                        rowKey={(ligne) => ligne.cle}
                        empty={<EmptyState icon={ICON.commitment} title="Aucun engagement rattaché" compact>L’expression de besoin n’a pas encore été transformée.</EmptyState>}
                    />
                </SectionCard>
            )}
        </main>
    );
}
