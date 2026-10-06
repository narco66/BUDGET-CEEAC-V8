import { useState } from 'react';
import { Alert, Button, CheckCard, FormField, ICON, Modal } from '../../../components/ui';

const FIELDS = [
    'Description du besoin — justification',
    'Sous-lignes — prix unitaires',
    'Imputation budgétaire',
    'Pièces justificatives',
    'Contexte programmatique PAP',
];

/** Retour pour correction ou rejet d’une expression de besoin (motif et champs concernés). */
export default function ReturnModal({ title, reference, initiateur, onClose, onConfirm, mode = 'retour' }: {
    title: string;
    reference: string;
    initiateur: string;
    onClose: () => void;
    onConfirm: (payload: { motif: string; observations: string; champs: string[] }) => Promise<unknown> | void;
    mode?: 'retour' | 'rejet';
}) {
    const [motif, setMotif] = useState('');
    const [observations, setObservations] = useState('');
    const [champs, setChamps] = useState(['Description du besoin — justification']);
    const [pending, setPending] = useState(false);
    const rejet = mode === 'rejet';

    function toggle(field: string) {
        setChamps((current) => current.includes(field) ? current.filter((item) => item !== field) : [...current, field]);
    }

    async function confirm() {
        setPending(true);
        try {
            await onConfirm({ motif, observations, champs });
        } finally {
            setPending(false);
        }
    }

    return (
        <Modal
            title={title}
            description={<><span className="mono">{reference}</span> · {rejet ? 'décision notifiée à' : 'retour vers'} {initiateur}</>}
            icon={rejet ? ICON.reject : ICON.return}
            tone={rejet ? 'danger' : 'warning'}
            onClose={onClose}
            footer={(
                <>
                    <Button onClick={onClose}>Annuler</Button>
                    <Button
                        variant={rejet ? 'danger' : 'brand'}
                        icon={rejet ? ICON.reject : ICON.return}
                        disabled={!motif.trim() || !observations.trim()}
                        loading={pending}
                        onClick={confirm}
                    >
                        {rejet ? 'Confirmer le rejet' : 'Retourner pour correction'}
                    </Button>
                </>
            )}
        >
            <FormField label="Motif" required>
                <input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} maxLength={255} data-autofocus />
            </FormField>
            <FormField label="Observations" required hint="Précisez ce qui doit être corrigé ou ce qui motive la décision.">
                <textarea className="inp" rows={4} value={observations} onChange={(event) => setObservations(event.target.value)} />
            </FormField>
            <fieldset className="stack-sm" style={{ border: 0, padding: 0, margin: 0 }}>
                <legend className="field-label" style={{ marginBottom: 8 }}>Champs concernés</legend>
                {FIELDS.map((field) => (
                    <CheckCard key={field} checked={champs.includes(field)} onChange={() => toggle(field)} tone="warning">{field}</CheckCard>
                ))}
            </fieldset>
            <Alert tone={rejet ? 'danger' : 'info'}>
                {rejet ? 'Le rejet est définitif. ' : ''}La version actuelle de l’EB est conservée dans le journal. L’initiateur est notifié.
            </Alert>
        </Modal>
    );
}
