import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../../../api/httpClient';
import { Alert, Badge, Button, ICON, PageHeader, SectionCard } from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { iconeNotice } from '../NoticeLigne';
import { basculerLecture, ouvrirNotification, type Notice } from '../ouvrir';

export default function NotificationDetailPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [notice, setNotice] = useState<Notice | null>(null);
    const [erreur, setErreur] = useState('');

    useEffect(() => {
        api.get(`/notifications/${id}`)
            .then((response) => setNotice(response.data.data))
            .catch((caught) => setErreur(errorsOf(caught)));
    }, [id]);

    async function lecture() {
        if (!notice) {
            return;
        }
        try {
            await basculerLecture(notice);
            setNotice({ ...notice, lue: !notice.lue });
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    async function ouvrir() {
        if (!notice || notice.ouverture !== 'dossier') {
            return;
        }
        try {
            const resultat = await ouvrirNotification(notice.id);
            if (resultat.chemin) {
                navigate(resultat.chemin);
            }
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                title="Notification"
                subtitle="Le dossier n’est pas recopié ici. L’ouverture respecte vos droits."
                back={{ to: '/notifications', label: 'Notifications' }}
                actions={<Button to="/taches" icon={ICON.tasks}>Mes tâches</Button>}
            />
            {erreur && <Alert tone="danger">{erreur}</Alert>}
            {notice && (
                <SectionCard title={notice.reference || 'Information'} icon={iconeNotice(notice)}>
                    <p className={notice.lue ? '' : 'strong'}>{notice.message}</p>
                    <p className="list-row-sub">
                        <FontAwesomeIcon icon={ICON.clock} /> {notice.date}
                        {' '}
                        <Badge tone={notice.lue ? 'neutral' : 'info'} size="sm" dot>{notice.lue ? 'Lue' : 'Non lue'}</Badge>
                    </p>
                    {notice.ouverture === 'detail' && <p>Cette notification n’est rattachée à aucun dossier.</p>}
                    {notice.ouverture === 'refusee' && <Alert tone="warning">{notice.cible?.motif}</Alert>}
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 12 }}>
                        <Button icon={notice.lue ? ICON.pending : ICON.check} onClick={() => void lecture()}>
                            {notice.lue ? 'Marquer comme non lue' : 'Marquer comme lue'}
                        </Button>
                        {notice.cible?.liste && <Button icon={ICON.open} to={notice.cible.liste}>Liste du module</Button>}
                        {notice.ouverture === 'dossier' && <Button variant="primary" icon={ICON.open} onClick={() => void ouvrir()}>Ouvrir le dossier</Button>}
                    </div>
                </SectionCard>
            )}
            {!notice && !erreur && <p className="subtle">Chargement…</p>}
        </main>
    );
}
