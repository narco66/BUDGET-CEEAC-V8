import { useEffect, useState } from 'react';
import api from '../../api/httpClient';
import { EmptyState, ErrorMessage, ICON, SectionCard } from '../../components/ui';
import { errorsOf } from '../../utils/format';

type Evenement = { id: number; quand: string; acteur?: string; action: string; resultat: string; motif?: string };

export default function AuditChronologie({ type, entityId }: { type: string; entityId: number }) {
    const [lignes, setLignes] = useState<Evenement[] | null>(null);
    const [fuseau, setFuseau] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/audit/chronologie', { params: { type, id: entityId } })
            .then((response) => {
                setLignes(response.data.data.evenements);
                setFuseau(response.data.data.fuseau);
            })
            .catch((caught) => setError(errorsOf(caught)));
    }, [type, entityId]);

    return (
        <SectionCard title="Piste d’audit" icon={ICON.history} subtitle={fuseau ? `Heure de ${fuseau}` : undefined}>
            {error && <ErrorMessage error={error} />}
            {lignes === null && !error && <p className="muted">Chargement de la chronologie…</p>}
            {lignes?.length === 0 && <EmptyState compact title="Aucun événement central">Les décisions reprises ou nouvellement journalisées apparaîtront ici.</EmptyState>}
            {lignes && lignes.length > 0 && (
                <ul className="stack-sm">
                    {lignes.map((ligne) => (
                        <li key={ligne.id}>
                            <span className="mono">{ligne.quand}</span>
                            {' · '}{ligne.acteur || 'Système'}
                            {' · '}{ligne.action}
                            {' · '}{ligne.resultat}
                            {ligne.motif ? ` · ${ligne.motif}` : ''}
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
