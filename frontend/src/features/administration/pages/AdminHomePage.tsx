import { faCalendarCheck, faCodeBranch, faPeopleArrows, faScaleUnbalanced, faUserCheck, faUserLock, faUserSlash } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Link } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Badge, EmptyState, ICON, PageError, PageHeader, PageSkeleton, SectionCard, StatCard } from '../../../components/ui';
import useResource from '../../../utils/useResource';

const LINKS = [
    { to: '/administration/organisation', label: 'Organisation', text: 'Organigramme officiel, structures, fonctions et responsables.', icon: ICON.administration },
    { to: '/administration/utilisateurs', label: 'Utilisateurs', text: 'Comptes, structures, rôles, désactivation et historique d’administration.', icon: ICON.users },
    { to: '/administration/roles-permissions', label: 'Rôles et permissions', text: 'Matrice des droits par rôle : global, limité au périmètre, ou non accordé.', icon: ICON.roles },
    { to: '/administration/habilitations', label: 'Habilitations', text: 'Attribution d’un rôle sur un périmètre, avec plafond et période de validité.', icon: ICON.roles },
    { to: '/administration/parametrage', label: 'Paramétrage', text: 'Règles métier, workflows, seuils, référentiels, numérotation et sécurité.', icon: ICON.settings },
];

const LEVEL_TONE: Record<string, string> = { critique: 'danger', erreur: 'danger', alerte: 'warning', avertissement: 'warning', info: 'info' };

export default function AdminHome() {
    const { data: board, error, reload } = useResource(() => api.get('/admin/tableau-de-bord').then((response) => response.data), []);

    if (!board) {
        return error ? <PageError message={error} onRetry={reload} /> : <PageSkeleton />;
    }

    const alertes = board.alertes ?? [];

    return (
        <main className="app-content">
            <PageHeader
                eyebrow={<><FontAwesomeIcon icon={ICON.administration} /> Gouvernance</>}
                title="Administration"
                subtitle="Gouvernance des comptes, des habilitations et des paramètres de BUDGET-CEEAC."
            />

            <div className="grid-thirds">
                {LINKS.map((link) => (
                    <Link key={link.to} to={link.to} className="stat-card is-interactive" style={{ gap: 10 }}>
                        <div className="stat-head">
                            <span className="card-title" style={{ fontSize: 'var(--text-md)' }}>{link.label}</span>
                            <span className="stat-icon" aria-hidden="true"><FontAwesomeIcon icon={link.icon} /></span>
                        </div>
                        <span className="muted" style={{ fontSize: 'var(--text-sm)' }}>{link.text}</span>
                        <span className="btn btn-link" aria-hidden="true" style={{ alignSelf: 'flex-start' }}>Ouvrir <FontAwesomeIcon icon={ICON.open} /></span>
                    </Link>
                ))}
            </div>

            <div className="grid-kpi cols-4">
                <StatCard label="Comptes actifs" value={board.utilisateurs_actifs} icon={faUserCheck} tone="success" to="/administration/utilisateurs" />
                <StatCard label="Suspendus" value={board.utilisateurs_suspendus} icon={faUserLock} tone="warning" />
                <StatCard label="Désactivés" value={board.utilisateurs_desactives} icon={faUserSlash} tone="neutral" />
                <StatCard label="Rôles" value={board.roles} icon={ICON.roles} to="/administration/roles-permissions" />
                <StatCard label="Règles de séparation des fonctions" value={board.conflits_sod} icon={faScaleUnbalanced} to="/administration/suivi-acces#incompatibilites" />
                <StatCard label="Délégations actives" value={board.delegations_actives} icon={faPeopleArrows} to="/administration/suivi-acces#delegations" />
                <StatCard label="Workflows actifs" value={board.workflows_actifs} icon={faCodeBranch} to="/administration/parametrage" />
                <StatCard label="Exercices ouverts" value={board.exercices_ouverts} icon={faCalendarCheck} to="/administration/parametrage" />
            </div>

            <SectionCard title="Alertes d’administration" icon={ICON.warning} tag={alertes.length > 0 ? <Badge tone="warning" size="sm">{alertes.length}</Badge> : undefined}>
                {alertes.length === 0 ? (
                    <EmptyState icon={ICON.success} title="Aucune alerte d’administration" compact>La configuration ne présente aucun point d’attention.</EmptyState>
                ) : (
                    <ul className="list-rows">
                        {alertes.map((alert) => (
                            <li key={alert.message} className="list-row">
                                <Badge tone={LEVEL_TONE[String(alert.niveau).toLowerCase()] ?? 'neutral'} size="sm">{alert.niveau}</Badge>
                                <span className="list-row-main">{alert.message}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
        </main>
    );
}
