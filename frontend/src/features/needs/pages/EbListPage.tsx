import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Button,
    ChainTrail,
    DataTable,
    EmptyState,
    FilterBar,
    ICON,
    NatureBadge,
    OpenCell,
    PageHeader,
    Pagination,
    ProgressBar,
    SearchInput,
    SectionCard,
    Segmented,
    StackedBar,
    StatCard,
    StatStrip,
    StatusBadge,
    StripCell,
    Tabs,
    useToast,
    type Column,
} from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const TABS = [
    ['', 'Toutes'],
    ['brouillon', 'Brouillons'],
    ['a_valider', 'À valider'],
    ['retournee', 'Retournées'],
    ['approuvees', 'Approuvées'],
    ['rejetee', 'Rejetées'],
    ['annulee', 'Annulées'],
];

export default function EbList() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<any[]>([]);
    const [board, setBoard] = useState<any>(null);
    const [meta, setMeta] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [exporting, setExporting] = useState(false);
    const toast = useToast();
    const [filters, setFilters] = useState({ q: '', statut: '', nature: '', page: 1 });
    const query = useDebouncedValue(filters, 250);

    useEffect(() => {
        const params: Record<string, string | number> = { ...query };
        if (!params.statut) delete params.statut;
        if (!params.nature) delete params.nature;
        setLoading(true);
        setError(null);
        api.get('/expressions-besoin', { params })
            .then((response) => {
                setRows(response.data.data);
                setBoard(response.data.tableau_de_bord);
                setMeta(response.data.meta);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }, [query]);

    function setFilter(partial) {
        setFilters((current) => ({ ...current, page: 1, ...partial }));
    }

    const counts: Record<string, number | undefined> = board ? {
        brouillon: board.brouillons,
        a_valider: board.a_valider,
        retournee: board['retournées'],
        approuvees: board.approuvees,
        rejetee: board.rejetees,
    } : {};

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'Référence · objet · ligne',
            render: (row) => (
                <div style={{ minWidth: 240 }}>
                    <Link className="cell-ref" to={`/expressions-besoin/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.reference}</Link>
                    <div style={{ marginTop: 3, fontWeight: 500 }}>{row.objet}</div>
                    <div className="cell-sub mono">{row.ligne.code} · {row.ligne.libelle}</div>
                </div>
            ),
        },
        { key: 'structure', header: 'Structure', render: (row) => <div style={{ minWidth: 150 }}>{row.structure}</div> },
        { key: 'nature', header: 'Nature', render: (row) => <NatureBadge nature={row.nature} libelle={row.nature_libelle} /> },
        {
            key: 'completude',
            header: 'Complétude PAP',
            render: (row) => row.completude === null ? <span className="subtle">Non applicable</span> : (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 110 }}>
                    <div style={{ flex: 1 }}>
                        <ProgressBar value={row.completude} size="thin" label={`Complétude ${row.completude} %`} color={row.completude >= 80 ? 'var(--green-600)' : row.completude >= 60 ? 'var(--warning-solid)' : 'var(--danger-solid)'} />
                    </div>
                    <span className="mono" style={{ fontSize: 'var(--text-xs)' }}>{row.completude} %</span>
                </div>
            ),
        },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant) },
        { key: 'statut', header: 'Statut', render: (row) => <StatusBadge statut={row.statut} libelle={row.statut_libelle} /> },
        {
            key: 'acteur',
            header: 'Acteur attendu · délai',
            render: (row) => (
                <div>
                    <div>{row.acteur_attendu}</div>
                    <div className="cell-sub" style={{ color: row.en_retard ? 'var(--danger-fg)' : undefined, fontWeight: row.en_retard ? 600 : undefined }}>{row.delai_libelle}</div>
                </div>
            ),
        },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    const filtered = Boolean(filters.q || filters.statut || filters.nature);

    return (
        <main className="app-content">
            <PageHeader
                title="Expressions de besoin"
                subtitle="Recensement et validation des besoins, préalable à tout engagement de dépense."
                meta={<ChainTrail current="EB" />}
                actions={(
                    <>
                        <Button icon={ICON.export} loading={exporting} onClick={async () => {
                            setExporting(true);
                            try {
                                await telecharger('/expressions-besoin/export', 'expressions-de-besoin.xlsx');
                            } catch (caught) {
                                toast.error(errorsOf(caught));
                            } finally {
                                setExporting(false);
                            }
                        }}>Exporter</Button>
                        <Button variant="primary" to="/expressions-besoin/nouvelle" icon={ICON.create}>Nouvelle expression de besoin</Button>
                    </>
                )}
            />

            {board && (
                <>
                    <div className="grid-kpi cols-3">
                        <StatCard dark label="EB totales · exercice 2026" value={board.total} unit="dossiers" icon={ICON.need} hint={`${fcfa(board.montant_total)} FCFA au total`} />
                        <SectionCard title="Répartition PAP / Hors PAP" subtitle="Détection automatique d’après la ligne budgétaire">
                            <StackedBar
                                label="Répartition des EB"
                                segments={[
                                    { label: `PAP · ${board.pap_count} EB`, value: board.pap_count, color: 'var(--green-600)' },
                                    { label: `Hors PAP · ${board.hors_pap_count} EB`, value: board.hors_pap_count, color: '#7EA0DC' },
                                ]}
                            />
                            <div className="split subtle">
                                <span>PAP <b className="mono">{fcfa(board.pap_montant)}</b></span>
                                <span>Hors PAP <b className="mono">{fcfa(board.hors_pap_montant)}</b></span>
                            </div>
                        </SectionCard>
                        <SectionCard title="Points d’attention" icon={ICON.warning} tone="warning">
                            <ul className="list-rows">
                                <li className="list-row"><span className="list-row-main">EB en retard de traitement</span><strong className="mono text-danger">{board.en_retard}</strong></li>
                                <li className="list-row"><span className="list-row-main">Lignes PAP à complétude &lt; 70 %</span><strong className="mono text-warning">{board.completude_faible}</strong></li>
                                <li className="list-row"><span className="list-row-main">Propositions de tâches à valider</span><strong className="mono">{board.propositions_taches}</strong></li>
                            </ul>
                        </SectionCard>
                    </div>
                    <StatStrip label="Répartition par statut">
                        <StripCell label="Brouillons" value={board.brouillons} hint="non soumises" color="var(--slate-400)" onClick={() => setFilter({ statut: 'brouillon' })} active={filters.statut === 'brouillon'} />
                        <StripCell label="À valider" value={board.a_valider} hint="dans le circuit" color="var(--warning-solid)" onClick={() => setFilter({ statut: 'a_valider' })} active={filters.statut === 'a_valider'} />
                        <StripCell label="Retournées" value={board['retournées']} hint="à corriger" color="var(--orange-solid)" onClick={() => setFilter({ statut: 'retournee' })} active={filters.statut === 'retournee'} />
                        <StripCell label="Rejetées" value={board.rejetees} hint="définitif" color="var(--danger-solid)" onClick={() => setFilter({ statut: 'rejetee' })} active={filters.statut === 'rejetee'} />
                        <StripCell label="Approuvées" value={board.approuvees} hint={`${board.transformees} transformées`} color="var(--success-solid)" onClick={() => setFilter({ statut: 'approuvees' })} active={filters.statut === 'approuvees'} />
                        <StripCell label="En retard" value={board.en_retard} hint="délai dépassé" color="var(--danger-fg)" />
                    </StatStrip>
                </>
            )}

            <SectionCard flush>
                <Tabs label="Statut des expressions de besoin" inCard value={filters.statut} onChange={(statut) => setFilter({ statut })} items={TABS.map(([value, label]) => ({ value, label, count: counts[value] }))} />
                <FilterBar
                    onReset={filtered ? () => setFilters({ q: '', statut: '', nature: '', page: 1 }) : null}
                    end={(
                        <Segmented
                            label="Nature"
                            value={filters.nature}
                            onChange={(nature) => setFilter({ nature })}
                            items={[{ value: '', label: 'Tout' }, { value: 'pap', label: 'PAP' }, { value: 'hors_pap', label: 'Hors PAP' }]}
                        />
                    )}
                >
                    <SearchInput value={filters.q} onChange={(q) => setFilter({ q })} placeholder="Référence, objet, ligne, activité…" />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/expressions-besoin/${row.id}`)}
                    rowLabel={(row) => `Ouvrir l’expression de besoin ${row.reference}`}
                    minWidth={1080}
                    empty={filtered ? (
                        <EmptyState icon={ICON.search} title="Aucun résultat">Aucune expression de besoin ne correspond à ces critères.</EmptyState>
                    ) : (
                        <EmptyState icon={ICON.need} title="Aucune expression de besoin enregistrée pour cet exercice" action={<Button variant="primary" to="/expressions-besoin/nouvelle" icon={ICON.create}>Créer une expression de besoin</Button>}>
                            Une expression de besoin part d’une ligne budgétaire et suit le circuit de validation jusqu’à l’engagement.
                        </EmptyState>
                    )}
                />
                <Pagination meta={meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
            </SectionCard>
        </main>
    );
}
