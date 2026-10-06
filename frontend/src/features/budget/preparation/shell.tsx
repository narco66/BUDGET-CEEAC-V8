import { Link, useLocation } from 'react-router-dom';
import { Badge, ICON } from '../../../components/ui';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

const LIENS = [
    { to: '/preparation', label: 'Vue d’ensemble', fin: true },
    { to: '/preparation/campagnes', label: 'Campagnes' },
    { to: '/preparation/dossiers', label: 'Propositions' },
];

const TONS: Record<string, 'info' | 'success' | 'warning' | 'danger' | 'neutral'> = {
    brouillon: 'neutral',
    ouverte: 'info',
    suspendue: 'warning',
    cloturee: 'success',
    archivee: 'neutral',
    soumis: 'info',
    retourne: 'warning',
    retenu: 'success',
    ecarte: 'danger',
    annule: 'danger',
    travail: 'neutral',
    soumise: 'info',
    retournee: 'warning',
    validee: 'success',
    adoptee: 'success',
    publiee: 'success',
    actif: 'success',
    publie: 'success',
};

export function PreparationNav() {
    const { pathname } = useLocation();

    return (
        <nav className="cluster" aria-label="Préparation budgétaire" style={{ marginBottom: 16 }}>
            {LIENS.map((lien) => {
                const actif = lien.fin ? pathname === lien.to : pathname.startsWith(lien.to);
                return (
                    <Link key={lien.to} className={actif ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-ghost'} to={lien.to}>
                        {lien.label}
                    </Link>
                );
            })}
        </nav>
    );
}

export function StatutPrep({ valeur }: { valeur?: string }) {
    if (!valeur) {
        return null;
    }

    return <Badge tone={TONS[valeur] ?? 'neutral'}>{valeur.replaceAll('_', ' ')}</Badge>;
}

export function Retard({ actif }: { actif?: boolean }) {
    if (!actif) {
        return null;
    }

    return <Badge tone="danger" icon={ICON.clock}>Retard</Badge>;
}

export function LienPlanification() {
    return (
        <p className="subtle">
            <FontAwesomeIcon icon={ICON.monitoring} /> Les activités d’investissement se créent dans la <Link to="/planification">planification stratégique</Link>.
        </p>
    );
}
