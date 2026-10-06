import { faClock, faStopwatch, faUserTie } from '@fortawesome/free-solid-svg-icons';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    ChainTrail,
    DataTable,
    EmptyState,
    FilterBar,
    ICON,
    KeyValueList,
    Meter,
    OpenCell,
    PageHeader,
    Pagination,
    SearchInput,
    SectionCard,
    StatCard,
    StatStrip,
    StatusBadge,
    StripCell,
    useToast,
    type Column,
} from '../../../components/ui';
import { telecharger } from '../../../utils/download';
import { errorsOf, fcfa } from '../../../utils/format';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const EMPTY = { q: '', statut: '', nature: '', ligne: '', du: '', au: '', page: 1 };

export default function OrdList() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<any[]>([]);
    const [board, setBoard] = useState<any>(null);
    const [meta, setMeta] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [exporting, setExporting] = useState(false);
    const toast = useToast();
    const [filters, setFilters] = useState(EMPTY);
    const query = useDebouncedValue(filters, 250);

    useEffect(() => {
        const params: Record<string, string | number> = {};
        Object.entries(query).forEach(([key, value]) => {
            if (value !== '' && value !== null) {
                params[key] = value;
            }
        });
        setLoading(true);
        setError(null);
        api.get('/ordonnancements', { params })
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

    const vote = board?.execution?.vote || 0;
    const share = (amount) => (vote > 0 ? Math.min(100, Math.round((amount / vote) * 1000) / 10) : 0);
    const filtered = Boolean(filters.q || filters.statut || filters.nature || filters.ligne || filters.du || filters.au);

    const columns: Column<any>[] = [
        {
            key: 'reference',
            header: 'ORD · LIQ · ENG',
            render: (row) => (
                <div>
                    <Link className="cell-ref" to={`/ordonnancements/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.reference}</Link>
                    <div className="cell-sub mono">{row.liquidation}</div>
                    <div className="cell-sub mono">{row.engagement}</div>
                </div>
            ),
        },
        { key: 'objet', header: 'Objet · structure', render: (row) => <div style={{ minWidth: 200 }}><div style={{ fontWeight: 500 }}>{row.objet}</div><div className="cell-sub">{row.nature_libelle} · {row.structure}</div></div> },
        { key: 'beneficiaire', header: 'Bénéficiaire', render: (row) => row.beneficiaire },
        { key: 'brut', header: 'Brut', align: 'right', className: 'mono', render: (row) => fcfa(row.montant_brut) },
        { key: 'retenues', header: 'Retenues', align: 'right', className: 'mono', render: (row) => fcfa(row.retenues) },
        { key: 'net', header: 'Net à payer', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant_net) },
        { key: 'ordonnateur', header: 'Ordonnateur', render: (row) => row.ordonnateur },
        {
            key: 'statut',
            header: 'Statut',
            render: (row) => (
                <div className="stack-sm" style={{ gap: 4, alignItems: 'flex-start' }}>
                    {row.signe && row.statut === 'transmission_erreur' && <StatusBadge statut="signe" libelle="Signé" size="sm" />}
                    <StatusBadge statut={row.statut} libelle={row.statut_libelle} />
                </div>
            ),
        },
        { key: 'acteur', header: 'Acteur attendu', render: (row) => <div>{row.acteur_attendu}{row.en_retard && <div style={{ marginTop: 4 }}><Badge tone="danger" icon={faClock} size="sm">Plus de 48 h</Badge></div>}</div> },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Ordonnancements"
                subtitle="Détermination de l’ordonnateur compétent, signature de l’ordre de paiement et transmission à l’Agence Comptable."
                meta={<ChainTrail current="ORD" />}
                actions={(
                    <>
                        <Button to="/ordonnancements/delegations" icon={ICON.roles}>Délégations et seuil</Button>
                        <Button icon={ICON.export} loading={exporting} onClick={async () => {
                            setExporting(true);
                            try {
                                await telecharger('/ordonnancements/export', 'ordonnancements.xlsx');
                            } catch (caught) {
                                toast.error(errorsOf(caught));
                            } finally {
                                setExporting(false);
                            }
                        }}>Exporter</Button>
                    </>
                )}
            />

            {board && (
                <>
                    <div className="grid-kpi cols-3">
                        <StatCard label="À signer" value={board.a_signer} hint={`${fcfa(board.montant_a_signer)} FCFA`} icon={ICON.sign} tone="warning" active={filters.statut === 'a_signer'} onClick={() => setFilter({ statut: 'a_signer' })} />
                        <StatCard label="Signés aujourd’hui" value={board.signes_aujourdhui} hint={`${fcfa(board.montant_signes_aujourdhui)} FCFA`} icon={ICON.success} tone="success" onClick={() => setFilter({ statut: '' })} />
                        <StatCard dark label="Transmis à l’Agence Comptable" value={board.transformes} hint={`${fcfa(board.execution.paye)} FCFA payés`} icon={ICON.transmit} active={filters.statut === 'transforme_paiement'} onClick={() => setFilter({ statut: 'transforme_paiement' })} />
                    </div>

                    <SectionCard
                        title="Exécution de la dépense · exercice 2026"
                        icon={ICON.dashboard}
                        actions={<Badge tone="brand">Taux d’ordonnancement <span className="mono">{board.taux} %</span> du liquidé</Badge>}
                    >
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 20 }}>
                            <Meter label="Budget voté" value={fcfa(board.execution.vote)} ratio={vote > 0 ? 100 : 0} color="var(--slate-400)" />
                            <Meter label="Engagé" value={fcfa(board.execution.engage)} ratio={share(board.execution.engage)} color="var(--navy-700)" />
                            <Meter label="Liquidé" value={fcfa(board.execution.liquide)} ratio={share(board.execution.liquide)} color="#4F7CC9" />
                            <Meter label="Ordonnancé" value={fcfa(board.execution.ordonnance)} ratio={share(board.execution.ordonnance)} color="var(--gold-500)" />
                            <Meter label="Payé" value={fcfa(board.execution.paye)} ratio={share(board.execution.paye)} color="var(--green-600)" />
                        </div>
                        <span className="subtle">Montants en FCFA · part du budget voté.</span>
                    </SectionCard>

                    <div className="grid-halves">
                        <SectionCard title="Montants ordonnancés" icon={faUserTie} actions={<span className="mono strong">{fcfa(board.montant_ordonnance)} FCFA</span>}>
                            <KeyValueList compact items={[
                                { label: `Président · ${board.repartition.president_nombre} ordre(s)`, value: fcfa(board.repartition.president) },
                                { label: `SG délégué · ${board.repartition.delegue_nombre} ordre(s)`, value: fcfa(board.repartition.delegue) },
                            ]} />
                        </SectionCard>
                        <SectionCard title="Délais de traitement" icon={faStopwatch}>
                            <KeyValueList compact items={[
                                { label: 'Visa LIQ → signature', value: `${board.delais.visa_signature ?? '—'} j` },
                                { label: 'Attente de signature', value: `${board.delais.attente ?? '—'} j` },
                                { label: 'Signature → Agence Comptable', value: `${board.delais.transmission_minutes ?? '—'} min` },
                                { label: 'Taux de retour', value: `${board.delais.taux_retour} %`, warning: Number(board.delais.taux_retour) > 0 },
                            ]} />
                        </SectionCard>
                    </div>

                    {board.alertes?.length > 0 && (
                        <Alert tone="warning" title="Alertes">
                            <ul style={{ listStyle: 'disc', paddingLeft: 18 }}>{board.alertes.map((alert) => <li key={alert}>{alert}</li>)}</ul>
                        </Alert>
                    )}

                    <StatStrip label="Compteurs par statut">
                        {board.compteurs.map((item) => (
                            <StripCell key={item.libelle} label={item.libelle} value={item.valeur} onClick={() => setFilter({ statut: item.statut })} active={filters.statut === item.statut && item.statut !== ''} />
                        ))}
                    </StatStrip>

                    {board.taches?.length > 0 && (
                        <SectionCard title="Mes tâches" icon={ICON.tasks} tone="warning">
                            <ul className="list-rows">
                                {board.taches.map((task) => (
                                    <li key={task.id}>
                                        <Link to={`/ordonnancements/${task.id}`} className="list-row">
                                            <span className="list-row-main"><span className="list-row-title mono">{task.reference}</span></span>
                                            <span className="mono">{fcfa(task.montant)} FCFA</span>
                                            <Badge tone="warning" size="sm">{task.action}</Badge>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}
                </>
            )}

            <SectionCard flush>
                <FilterBar onReset={filtered ? () => setFilters(EMPTY) : null}>
                    <SearchInput value={filters.q} onChange={(q) => setFilter({ q })} placeholder="Référence, bénéficiaire, ordonnateur" />
                    <select className="inp" aria-label="Nature" value={filters.nature} onChange={(event) => setFilter({ nature: event.target.value })}>
                        <option value="">PAP + Hors PAP</option>
                        <option value="pap">PAP</option>
                        <option value="hors_pap">Hors PAP</option>
                    </select>
                    <input className="inp" aria-label="Ligne budgétaire" placeholder="Ligne budgétaire" value={filters.ligne} onChange={(event) => setFilter({ ligne: event.target.value })} />
                    <span className="filter-label">Du</span>
                    <input className="inp" type="date" aria-label="Date de début" value={filters.du} onChange={(event) => setFilter({ du: event.target.value })} style={{ minWidth: 0 }} />
                    <span className="filter-label">au</span>
                    <input className="inp" type="date" aria-label="Date de fin" value={filters.au} onChange={(event) => setFilter({ au: event.target.value })} style={{ minWidth: 0 }} />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/ordonnancements/${row.id}`)}
                    rowLabel={(row) => `Ouvrir l’ordonnancement ${row.reference}`}
                    minWidth={1240}
                    empty={filtered
                        ? <EmptyState icon={ICON.search} title="Aucun résultat">Aucun ordonnancement ne correspond à ces critères.</EmptyState>
                        : <EmptyState icon={ICON.order} title="Aucun ordonnancement">Un ordonnancement est créé au visa d’une liquidation.</EmptyState>}
                />
                <Pagination meta={meta} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
            </SectionCard>
        </main>
    );
}
