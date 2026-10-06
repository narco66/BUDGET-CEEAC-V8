import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    FilterBar,
    ICON,
    OpenCell,
    PageHeader,
    SearchInput,
    SectionCard,
    Segmented,
    StatCard,
    StatStrip,
    StripCell,
    Tabs,
    Pagination,
    type Column,
    type PageMeta,
} from '../../../components/ui';
import { dateFr, dateHeure, errorsOf, fcfa } from '../../../utils/format';
import { NoticeLigne } from '../../notifications/NoticeLigne';
import { ouvrirNotification, type Notice } from '../../notifications/ouvrir';
import useDebouncedValue from '../../../utils/useDebouncedValue';

const VUES = [
    ['', 'Toutes'],
    ['a_traiter', 'À traiter'],
    ['urgentes', 'Urgentes'],
    ['retard', 'En retard'],
    ['retournees', 'Retournées'],
    ['en_cours', 'En cours'],
    ['brouillons', 'Brouillons'],
    ['terminees', 'Terminées'],
];

const RESULTATS = {
    etape_suivante: 'Étape suivante',
    rejetee: 'Rejetée',
    annulee: 'Annulée',
};

const PRIORITES: Record<string, { tone: string; icon: typeof ICON.danger; label: string }> = {
    critique: { tone: 'danger', icon: ICON.danger, label: 'Critique' },
    haute: { tone: 'orange', icon: ICON.priorityHigh, label: 'Haute' },
    normale: { tone: 'info', icon: ICON.priorityNormal, label: 'Normale' },
    faible: { tone: 'neutral', icon: ICON.priorityLow, label: 'Faible' },
};

/** Délai lisible : « en retard de 9 j », « aujourd’hui », « dans 3 j ». */
function delai(jours: number | null | undefined): string {
    if (jours === null || jours === undefined) {
        return '';
    }
    if (jours < 0) {
        return `en retard de ${-jours} j`;
    }
    return jours === 0 ? 'aujourd’hui' : `dans ${jours} j`;
}

const STATUT_TONE: Record<string, string> = { a_traiter: 'warning', en_cours: 'info', retournee: 'orange', terminee: 'success' };

const EMPTY_FILTERS = { vue: '', q: '', module: '', tri: '', exercice: '', statut: '', priorite: '', structure: '', etape: '', perimetre: '' };

export default function MesTaches() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<any[]>([]);
    const [board, setBoard] = useState<any>(null);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const query = useDebouncedValue(filters, 250);

    useEffect(() => {
        const params: Record<string, string> = {};
        Object.entries(query).forEach(([key, value]) => {
            if (value) {
                params[key] = value;
            }
        });
        params.page = String(page);
        setLoading(true);
        setError(null);
        api.get('/taches', { params })
            .then((response) => {
                setRows(response.data.data);
                setMeta(response.data.meta ?? null);
                setBoard(response.data.tableau_de_bord);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
        api.get('/notifications', { params: { lu: 'non_lues', per_page: 5 } })
            .then((response) => setNotices(response.data.data ?? []))
            .catch(() => undefined);
    }, [query, page]);

    useEffect(() => {
        function maj() {
            api.get('/notifications', { params: { lu: 'non_lues', per_page: 5 } })
                .then((response) => setNotices(response.data.data ?? []))
                .catch(() => undefined);
        }
        window.addEventListener('notifications:maj', maj);

        return () => window.removeEventListener('notifications:maj', maj);
    }, []);

    // Tout changement de filtre ramène à la première page.
    const set = (partial: Partial<typeof EMPTY_FILTERS>) => {
        setPage(1);
        setFilters((current) => ({ ...current, ...partial }));
    };
    const filtered = Object.entries(filters).some(([key, value]) => key !== 'vue' && key !== 'perimetre' && value);

    const columns: Column<any>[] = [
        {
            key: 'priorite',
            header: 'Priorité',
            render: (row) => {
                const meta = PRIORITES[row.priorite] ?? { tone: 'neutral', icon: ICON.priorityNormal, label: row.priorite };
                return (
                    <div className="stack-sm" style={{ gap: 4 }}>
                        <Badge tone={meta.tone} icon={meta.icon} size="sm">{meta.label}</Badge>
                        {row.en_retard && <Badge tone="danger" icon={ICON.clock} size="sm">Retard</Badge>}
                    </div>
                );
            },
        },
        {
            key: 'dossier',
            header: 'Dossier · objet',
            render: (row) => (
                <div style={{ minWidth: 220 }}>
                    <Link className="cell-ref" to={`/taches/${row.id}`} onClick={(event) => event.stopPropagation()}>{row.dossier}</Link>
                    <span className="subtle mono" style={{ marginLeft: 8 }}>{row.type}</span>
                    <div style={{ marginTop: 2 }}>{row.objet}</div>
                    {row.demandeur && <div className="cell-sub">{row.demandeur}</div>}
                </div>
            ),
        },
        { key: 'montant', header: 'Montant (FCFA)', align: 'right', className: 'cell-amount', render: (row) => fcfa(row.montant) },
        {
            key: 'etape',
            header: 'Étape · action attendue',
            render: (row) => (
                <div>
                    <div>{row.etape_libelle || '—'}</div>
                    <div className="cell-sub">{row.action_libelle}</div>
                    {row.deleguee && (
                        <Badge tone="info" size="sm">Par délégation{row.delegation?.titulaire ? ` de ${row.delegation.titulaire}` : ''}</Badge>
                    )}
                </div>
            ),
        },
        {
            key: 'delais',
            header: 'Reçue · échéance',
            render: (row) => (
                <div style={{ fontSize: 'var(--text-sm)' }}>
                    <div className="mono">{dateHeure(row.recue_le)}</div>
                    <div className={row.en_retard ? 'text-danger' : 'subtle'}>
                        <span className="mono">{dateFr(row.echeance)}</span>
                        {row.statut !== 'terminee' && delai(row.delai_jours) ? ` · ${delai(row.delai_jours)}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'statut',
            header: 'Statut',
            render: (row) => (
                <div>
                    <Badge tone={STATUT_TONE[row.statut] ?? 'neutral'} dot>{row.statut_libelle}</Badge>
                    {row.prise_par && <div className="cell-sub">{row.prise_par_moi ? 'par vous' : `par ${row.prise_par}`}</div>}
                    {row.statut === 'terminee' && row.duree_heures !== null && (
                        <div className="cell-sub">{row.duree_heures} h · {RESULTATS[row.completion_action] || ''}</div>
                    )}
                </div>
            ),
        },
        { key: 'open', header: 'Ouvrir', srHeader: true, className: 'cell-actions', render: () => <OpenCell /> },
    ];

    return (
        <main className="app-content">
            <PageHeader
                title="Mes tâches"
                subtitle="Les dossiers qui attendent votre intervention, issus directement des circuits de validation."
                actions={board?.vue_unite && (
                    <Segmented
                        label="Périmètre"
                        value={filters.perimetre}
                        onChange={(perimetre) => set({ perimetre })}
                        items={[{ value: '', label: 'Mes dossiers' }, { value: 'unite', label: 'Mon unité' }]}
                    />
                )}
            />

            <SectionCard title="Notifications non lues" icon={ICON.notifications} tone="info" actions={<Button to="/notifications" size="sm" icon={ICON.notifications}>Toutes</Button>}>
                {notices.length === 0 ? (
                    <EmptyState icon={ICON.notifications} title="Aucune notification non lue" compact>Les alertes de vos dossiers s’afficheront ici, synchronisées avec la cloche.</EmptyState>
                ) : (
                    <ul className="list-rows notice-list">
                        {notices.map((notice) => (
                            <NoticeLigne
                                key={notice.id}
                                notice={notice}
                                onOpen={(item) => {
                                    if (item.ouverture === 'refusee') {
                                        navigate(`/notifications/${item.id}`);
                                        return;
                                    }
                                    ouvrirNotification(item.id)
                                        .then((resultat) => navigate(resultat.chemin ?? `/notifications/${item.id}`))
                                        .catch((caught) => setError(errorsOf(caught)));
                                }}
                            />
                        ))}
                    </ul>
                )}
            </SectionCard>

            {board && (
                <>
                    <div className="grid-kpi">
                        <StatCard label="À traiter" value={board.a_traiter} icon={ICON.inbox} active={filters.vue === 'a_traiter'} onClick={() => set({ vue: 'a_traiter' })} />
                        <StatCard label="Urgentes" value={board.urgentes} icon={ICON.danger} tone="danger" active={filters.vue === 'urgentes'} onClick={() => set({ vue: 'urgentes' })} />
                        <StatCard label="En retard" value={board.en_retard} icon={ICON.clock} tone="orange" active={filters.vue === 'retard'} onClick={() => set({ vue: 'retard' })} />
                        <StatCard label="Retournées" value={board.retournees} icon={ICON.return} tone="warning" active={filters.vue === 'retournees'} onClick={() => set({ vue: 'retournees' })} />
                        <StatCard label="Terminées aujourd’hui" value={board.terminees_aujourdhui} icon={ICON.success} tone="success" active={filters.vue === 'terminees_aujourdhui'} onClick={() => set({ vue: 'terminees_aujourdhui' })} />
                    </div>
                    <StatStrip label="Activité">
                        <StripCell label="Reçues aujourd’hui" value={board.recues_aujourdhui} />
                        <StripCell label="Terminées cette semaine" value={board.terminees_semaine} />
                        <StripCell label="Délai moyen" value={board.delai_moyen ?? '—'} hint={`jours de traitement, sur ${board.delai_fenetre_jours ?? 90} jours`} />
                        <StripCell label="Brouillons à compléter" value={board.brouillons ?? 0} hint="non comptés dans « À traiter »" />
                        <StripCell label="Montant élevé" value={board.montant_eleve} hint="dossiers au-dessus du seuil" />
                    </StatStrip>
                </>
            )}

            <SectionCard flush>
                <Tabs label="Vues des tâches" inCard value={filters.vue} onChange={(vue) => set({ vue })} items={VUES.map(([value, label]) => ({
                    value,
                    label,
                    count: value === 'a_traiter' ? board?.a_traiter : value === 'urgentes' ? board?.urgentes : value === 'retard' ? board?.en_retard : value === 'retournees' ? board?.retournees : value === 'brouillons' ? board?.brouillons : undefined,
                }))} />
                <FilterBar onReset={filtered ? () => setFilters({ ...EMPTY_FILTERS, vue: filters.vue, perimetre: filters.perimetre }) : null}>
                    <SearchInput value={filters.q} onChange={(q) => set({ q })} placeholder="Référence ou objet" />
                    <select className="inp" aria-label="Module" value={filters.module} onChange={(event) => set({ module: event.target.value })}>
                        <option value="">Tous les modules</option>
                        <option value="eb">Expression de besoin</option>
                        <option value="engagement">Engagement</option>
                        <option value="liquidation">Liquidation</option>
                        <option value="ordonnancement">Ordonnancement</option>
                        <option value="paiement">Paiement</option>
                        <option value="se">Suivi-évaluation</option>
                        <option value="recette">Recettes</option>
                        <option value="preparation">Préparation budgétaire</option>
                    </select>
                    <select className="inp" aria-label="Statut" value={filters.statut} onChange={(event) => set({ statut: event.target.value })}>
                        <option value="">Tous les statuts</option>
                        <option value="a_traiter">À traiter</option>
                        <option value="en_cours">En cours</option>
                        <option value="retournee">Retournée</option>
                        <option value="terminee">Terminée</option>
                    </select>
                    <select className="inp" aria-label="Priorité" value={filters.priorite} onChange={(event) => set({ priorite: event.target.value })}>
                        <option value="">Toutes les priorités</option>
                        <option value="critique">Critique</option>
                        <option value="haute">Haute</option>
                        <option value="normale">Normale</option>
                        <option value="faible">Faible</option>
                    </select>
                    <input className="inp" aria-label="Exercice" placeholder="Exercice" inputMode="numeric" value={filters.exercice} onChange={(event) => set({ exercice: event.target.value })} style={{ minWidth: 0, width: 110 }} />
                    <input className="inp" aria-label="Structure" placeholder="Structure" value={filters.structure} onChange={(event) => set({ structure: event.target.value })} />
                    <select className="inp" aria-label="Étape" value={filters.etape} onChange={(event) => set({ etape: event.target.value })}>
                        <option value="">Toutes les étapes</option>
                        {(board?.etapes ?? []).map((etape) => <option key={etape.valeur} value={etape.valeur}>{etape.libelle}</option>)}
                    </select>
                    <select className="inp" aria-label="Trier par" value={filters.tri} onChange={(event) => set({ tri: event.target.value })}>
                        <option value="">Plus récentes</option>
                        <option value="ancien">Plus anciennes</option>
                        <option value="echeance">Échéance proche</option>
                        <option value="priorite">Priorité</option>
                        <option value="montant">Montant</option>
                        <option value="module">Module</option>
                        <option value="type">Type</option>
                        <option value="statut">Statut</option>
                        <option value="urgentes">Urgentes d’abord</option>
                    </select>
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    loading={loading}
                    error={error}
                    onRowClick={(row) => navigate(`/taches/${row.id}`)}
                    rowLabel={(row) => `Ouvrir la tâche ${row.dossier}`}
                    minWidth={980}
                    empty={(
                        <EmptyState icon={ICON.calendar} title="Aucune tâche pour cette vue">
                            {filtered ? 'Aucune tâche ne correspond aux filtres appliqués.' : 'Vous êtes à jour : aucun dossier n’attend votre intervention.'}
                        </EmptyState>
                    )}
                />
                <Pagination meta={meta} onPage={setPage} noun="tâche" />
            </SectionCard>
        </main>
    );
}
