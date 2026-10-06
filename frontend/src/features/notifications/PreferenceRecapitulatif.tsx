import { useEffect, useState } from 'react';
import api from '../../api/httpClient';
import { Alert, CheckCard, ICON, SectionCard, useToast } from '../../components/ui';
import { errorsOf } from '../../utils/format';

type Preference = { recapitulatif: 'quotidien' | 'aucun'; courriel: string | null; actif: boolean };

/**
 * Récapitulatif quotidien par courriel des dossiers urgents ou en retard.
 * Préférence propre au compte connecté.
 */
export default function PreferenceRecapitulatif() {
    const toast = useToast();
    const [preference, setPreference] = useState<Preference | null>(null);
    const [erreur, setErreur] = useState('');
    const [envoi, setEnvoi] = useState(false);

    useEffect(() => {
        api.get('/notifications/preferences')
            .then((response) => setPreference(response.data.data))
            .catch((caught) => setErreur(errorsOf(caught)));
    }, []);

    async function changer(actif: boolean) {
        setEnvoi(true);
        setErreur('');
        try {
            const response = await api.put('/notifications/preferences', { recapitulatif: actif ? 'quotidien' : 'aucun' });
            setPreference(response.data.data);
            toast.success(actif ? 'Récapitulatif quotidien activé.' : 'Récapitulatif quotidien désactivé.');
        } catch (caught) {
            setErreur(errorsOf(caught));
        } finally {
            setEnvoi(false);
        }
    }

    if (!preference && !erreur) {
        return null;
    }

    return (
        <SectionCard title="Récapitulatif par courriel" icon={ICON.notifications} subtitle="Pour être prévenu sans avoir à ouvrir l’application.">
            {erreur && <Alert tone="danger">{erreur}</Alert>}
            {preference && (
                <>
                    <CheckCard
                        checked={preference.recapitulatif === 'quotidien'}
                        onChange={changer}
                        disabled={envoi}
                        hint={preference.courriel ? `Envoyé chaque matin à ${preference.courriel}, uniquement s’il y a des dossiers urgents, retournés ou en retard.` : 'Aucune adresse électronique n’est enregistrée sur votre compte.'}
                    >
                        Recevoir chaque matin le récapitulatif de mes dossiers urgents
                    </CheckCard>
                    {!preference.actif && (
                        <Alert tone="warning">L’envoi des récapitulatifs est désactivé sur ce serveur. Votre préférence sera appliquée dès son activation.</Alert>
                    )}
                </>
            )}
        </SectionCard>
    );
}
