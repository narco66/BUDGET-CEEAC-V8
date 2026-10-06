import { Badge, Button, ICON, SectionCard } from '../../components/ui';

const LIBELLES: Record<string, string> = {
    engagement: 'Fiche d’engagement',
    controle_budgetaire: 'Contrôle budgétaire',
    attestation_service_fait: 'Attestation de service fait',
    pv_reception: 'Procès-verbal de réception',
    liquidation: 'Fiche de liquidation',
    ordonnancement: 'Fiche d’ordonnancement',
    bordereau_transmission: 'Bordereau de transmission',
    paiement: 'Fiche de paiement',
    ordre_virement: 'Ordre de virement',
    bordereau_cheque: 'Bordereau de chèque',
    bon_sortie_caisse: 'Bon de sortie de caisse',
    rapprochement: 'Fiche de rapprochement',
};

export default function ActesDossier({ dossier, base }: { dossier: { id: number, actes?: any[], actes_a_emettre?: string[] }, base: string }) {
    const actes = dossier.actes ?? [];
    const aEmettre = dossier.actes_a_emettre ?? [];
    if (actes.length === 0 && aEmettre.length === 0) {
        return null;
    }

    return (
        <SectionCard title="Documents du dossier" icon={ICON.pdf} subtitle="Chaque émission est archivée. Un téléchargement restitue le fichier de la révision, sans le régénérer.">
            <ul className="list-rows">
                {actes.map((acte) => (
                    <li key={acte.id} className="list-row">
                        <div>
                            <div className="list-row-title">{LIBELLES[acte.type] ?? acte.type}</div>
                            <div className="cell-sub">{acte.reference} · version {acte.version} · {acte.genere_le} · {acte.evenement}</div>
                        </div>
                        <Button size="sm" icon={ICON.pdf} href={`${base}/${dossier.id}/pdf?document=${acte.type}&version=${acte.version}`}>Télécharger</Button>
                    </li>
                ))}
                {aEmettre.map((type) => (
                    <li key={type} className="list-row">
                        <div>
                            <div className="list-row-title">{LIBELLES[type] ?? type}</div>
                            <Badge tone="warning" size="sm">Pas encore émis</Badge>
                        </div>
                        <Button size="sm" variant="secondary" icon={ICON.pdf} href={`${base}/${dossier.id}/pdf?document=${type}`}>Émettre</Button>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
