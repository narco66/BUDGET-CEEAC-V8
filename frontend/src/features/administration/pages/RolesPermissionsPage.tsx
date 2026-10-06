import { FormEvent, useEffect, useMemo, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Alert,
    Badge,
    Button,
    EmptyState,
    ErrorMessage,
    PageHeader,
    SearchInput,
    SectionCard,
    ICON,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

type Cellule = { permission: string; niveau: 'global' | 'scoped' | 'denied'; applique: boolean };
type RoleLigne = {
    code: string;
    label: string;
    description: string | null;
    categorie: string | null;
    systeme: boolean;
    actif: boolean;
    agents: number;
    permissions_accordees: number;
    permissions_total: number;
    updated_at: string | null;
    incompatibilites: { code: string; label: string; motif: string }[];
    cellules: Record<string, Cellule>;
};

const NIVEAU: Record<string, string> = {
    global: 'Global',
    scoped: 'Périmètre',
    denied: 'Non accordé',
};

function suivant(niveau: string): 'global' | 'scoped' | 'denied' {
    if (niveau === 'denied') return 'scoped';
    if (niveau === 'scoped') return 'global';
    return 'denied';
}

export default function RolesPermissionsPage() {
    const [roles, setRoles] = useState<RoleLigne[]>([]);
    const [modules, setModules] = useState<{ code: string; label: string; famille: string }[]>([]);
    const [actions, setActions] = useState<{ code: string; label: string }[]>([]);
    const [recherche, setRecherche] = useState('');
    const [code, setCode] = useState<string | null>(null);
    const [edits, setEdits] = useState<Record<string, 'global' | 'scoped' | 'denied'>>({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    function load(q = recherche) {
        setLoading(true);
        api.get('/admin/roles-permissions', { params: { q } })
            .then((response) => {
                const data = response.data.data;
                setRoles(data.roles);
                setModules(data.modules);
                setActions(data.actions);
                setError('');
                setCode((actuel) => actuel && data.roles.some((role: RoleLigne) => role.code === actuel) ? actuel : data.roles[0]?.code ?? null);
            })
            .catch((caught) => setError(errorsOf(caught)))
            .finally(() => setLoading(false));
    }

    useEffect(() => { load(''); }, []);

    const role = useMemo(() => roles.find((item) => item.code === code) ?? null, [roles, code]);

    function niveau(cle: string): string {
        return edits[cle] ?? role?.cellules[cle]?.niveau ?? 'denied';
    }

    function choisir(suivantCode: string) {
        setCode(suivantCode);
        setEdits({});
    }

    async function enregistrer(event: FormEvent) {
        event.preventDefault();
        if (!role || Object.keys(edits).length === 0) return;
        setPending(true);
        setError('');
        try {
            await api.put(`/admin/roles/${role.code}/matrice`, {
                updated_at: role.updated_at,
                cellules: Object.entries(edits).map(([cle, valeur]) => ({ cle, niveau: valeur })),
            });
            setEdits({});
            load(recherche);
        } catch (caught) {
            setError(errorsOf(caught));
        } finally {
            setPending(false);
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Rôles et permissions"
                subtitle="Un rôle définit ce qu’un agent peut faire. Le périmètre, le plafond et la période se fixent dans l’habilitation."
            />
            <ErrorMessage error={error} onClose={() => setError('')} />
            <div className="roles-layout">
                <SectionCard title="Rôles" icon={ICON.roles}>
                    <form onSubmit={(event) => { event.preventDefault(); load(recherche); }}>
                        <SearchInput value={recherche} onChange={setRecherche} placeholder="Nom ou code" />
                    </form>
                    {loading && <p className="subtle">Chargement des rôles…</p>}
                    {!loading && roles.length === 0 && <EmptyState icon={ICON.roles} title="Aucun rôle" compact />}
                    <ul className="role-list">
                        {roles.map((item) => (
                            <li key={item.code}>
                                <button type="button" className={`role-item${item.code === code ? ' is-current' : ''}`} aria-current={item.code === code ? 'true' : undefined} onClick={() => choisir(item.code)}>
                                    <strong>{item.label}</strong>
                                    <span className="mono">{item.code}</span>
                                    <span className="subtle">{item.agents} agent{item.agents > 1 ? 's' : ''}{item.categorie ? ` · ${item.categorie}` : ''}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                {role && (
                    <form onSubmit={enregistrer} className="stack">
                        <SectionCard
                            title={role.label}
                            icon={ICON.roles}
                            subtitle={role.description || 'Aucune description enregistrée.'}
                            actions={(
                                <>
                                    <Button type="button" onClick={() => setEdits({})} disabled={Object.keys(edits).length === 0}>Annuler</Button>
                                    <Button variant="primary" type="submit" icon={ICON.save} loading={pending} disabled={Object.keys(edits).length === 0}>Enregistrer le rôle</Button>
                                </>
                            )}
                        >
                            <div className="grid-kpi">
                                <p><strong>{role.agents}</strong><span className="subtle"> agents habilités</span></p>
                                <p><strong>{role.permissions_accordees} / {role.permissions_total}</strong><span className="subtle"> permissions accordées</span></p>
                                <p><strong>{role.updated_at ? new Date(role.updated_at).toLocaleDateString('fr-FR') : '—'}</strong><span className="subtle"> dernière modification</span></p>
                            </div>
                            {!role.actif && <Alert tone="warning" title="Rôle désactivé">Ce rôle n’ouvre plus de droit.</Alert>}
                            <p className="legend-niveaux">
                                <Badge tone="success" size="sm">✓ Global</Badge>
                                <Badge tone="info" size="sm">P Périmètre</Badge>
                                <Badge tone="neutral" size="sm">– Non accordé</Badge>
                                <span className="subtle">Cliquez une case pour changer son niveau. Une case marquée « contrôlé » modifie le contrôle serveur.</span>
                            </p>
                        </SectionCard>
                        <SectionCard title="Matrice" icon={ICON.lock} flush>
                            <div className="matrice-scroll">
                                <table className="tbl">
                                    <thead>
                                        <tr>
                                            <th>Module</th>
                                            {actions.map((action) => <th key={action.code}>{action.label}</th>)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {modules.map((module) => (
                                            <tr key={module.code}>
                                                <th scope="row">
                                                    {module.label}
                                                    <div className="subtle">{module.famille}</div>
                                                </th>
                                                {actions.map((action) => {
                                                    const cle = `${module.code}.${action.code}`;
                                                    const valeur = niveau(cle);
                                                    const applique = role.cellules[cle]?.applique;
                                                    return (
                                                        <td key={cle}>
                                                            <button
                                                                type="button"
                                                                className={`matrice-cell is-${valeur}`}
                                                                aria-label={`${module.label}, ${action.label}, ${NIVEAU[valeur]}`}
                                                                onClick={() => setEdits({ ...edits, [cle]: suivant(valeur) })}
                                                            >
                                                                {valeur === 'global' ? '✓' : valeur === 'scoped' ? 'P' : '–'}
                                                            </button>
                                                            {applique && <div className="subtle">contrôlé</div>}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </SectionCard>
                        <SectionCard title="Séparation des tâches" icon={ICON.warning} subtitle="Ce rôle ne peut pas être cumulé, par un même agent, avec :">
                            {role.incompatibilites.length === 0 && <p className="subtle">Aucune incompatibilité active.</p>}
                            <ul>
                                {role.incompatibilites.map((item) => (
                                    <li key={item.code}><strong>{item.label}</strong> <span className="mono">{item.code}</span> — {item.motif}</li>
                                ))}
                            </ul>
                        </SectionCard>
                    </form>
                )}
            </div>
        </main>
    );
}
