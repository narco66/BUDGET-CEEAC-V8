import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../../api/httpClient';
import {
    Alert,
    Button,
    EmptyState,
    FilterBar,
    ICON,
    PageHeader,
    Pagination,
    SectionCard,
    StatCard,
    Tabs,
    type PageMeta,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';
import { NoticeLigne } from '../NoticeLigne';
import PreferenceRecapitulatif from '../PreferenceRecapitulatif';
import { basculerLecture, ouvrirNotification, type Notice } from '../ouvrir';

const MODULES: Array<[string, string]> = [
    ['', 'Tous les modules'],
    ['depense', 'Chaîne de dépense'],
    ['recettes', 'Recettes'],
    ['suivi', 'Suivi-évaluation'],
    ['taches', 'Tâches'],
    ['budget', 'Budget'],
];

const TYPES: Array<[string, string]> = [
    ['', 'Tous les types'],
    ['expression_besoin', 'Expression de besoin'],
    ['engagement', 'Engagement'],
    ['liquidation', 'Liquidation'],
    ['ordonnancement', 'Ordonnancement'],
    ['paiement', 'Paiement'],
    ['tache', 'Tâche'],
    ['prevision', 'Prévision de recette'],
    ['titre', 'Titre de recette'],
    ['ecart', 'Écart'],
    ['activite', 'Activité'],
    ['indicateur', 'Indicateur'],
    ['saisie', 'Saisie'],
    ['synthese', 'Synthèse'],
    ['ligne', 'Ligne budgétaire'],
];

export default function NotificationsPage() {
    const navigate = useNavigate();
    const [rows, setRows] = useState<Notice[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [nonLues, setNonLues] = useState(0);
    const [page, setPage] = useState(1);
    const [lu, setLu] = useState('');
    const [module, setModule] = useState('');
    const [type, setType] = useState('');
    const [du, setDu] = useState('');
    const [au, setAu] = useState('');
    const [erreur, setErreur] = useState('');
    const [chargement, setChargement] = useState(true);
    const filtre = Boolean(lu || module || type || du || au);

    const charger = useCallback(() => {
        const params: Record<string, string | number> = { page };
        if (lu) {
            params.lu = lu;
        }
        if (module) {
            params.module = module;
        }
        if (type) {
            params.type = type;
        }
        if (du) {
            params.du = du;
        }
        if (au) {
            params.au = au;
        }
        setChargement(true);
        api.get('/notifications', { params })
            .then((response) => {
                setRows(response.data.data);
                setMeta(response.data.meta);
                setNonLues(response.data.non_lues ?? 0);
                setErreur('');
            })
            .catch((caught) => setErreur(errorsOf(caught)))
            .finally(() => setChargement(false));
    }, [page, lu, module, type, du, au]);

    useEffect(() => {
        charger();
    }, [charger]);

    useEffect(() => {
        function maj() {
            charger();
        }
        window.addEventListener('notifications:maj', maj);

        return () => window.removeEventListener('notifications:maj', maj);
    }, [charger]);

    async function ouvrir(notice: Notice) {
        if (notice.ouverture === 'refusee') {
            navigate(`/notifications/${notice.id}`);
            return;
        }
        try {
            const resultat = await ouvrirNotification(notice.id);
            navigate(resultat.chemin ?? `/notifications/${notice.id}`);
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    async function lecture(notice: Notice) {
        try {
            await basculerLecture(notice);
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    async function toutLire() {
        try {
            await api.post('/notifications/lues');
            window.dispatchEvent(new CustomEvent('notifications:maj', { detail: 0 }));
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    return (
        <main className="app-content">
            <PageHeader
                title="Notifications"
                subtitle="Les mêmes événements que Mes tâches : chaque ligne ouvre le dossier autorisé."
                actions={(
                    <>
                        <Button icon={ICON.check} onClick={toutLire}>Tout marquer comme lu</Button>
                        <Button to="/taches" icon={ICON.tasks}>Mes tâches</Button>
                    </>
                )}
            />
            {erreur && <Alert tone="danger">{erreur}</Alert>}
            <div className="grid-kpi">
                <StatCard label="Non lues" value={nonLues} icon={ICON.notifications} tone="info" active={lu === 'non_lues'} onClick={() => { setLu('non_lues'); setPage(1); }} />
                <StatCard label="Dans cette vue" value={meta?.total ?? 0} icon={ICON.inbox} active={lu === ''} onClick={() => { setLu(''); setPage(1); }} />
                <StatCard label="Mes tâches" value="Ouvrir" icon={ICON.tasks} hint="Dossiers en attente d’action" to="/taches" />
            </div>
            <SectionCard flush>
                <Tabs
                    label="État de lecture"
                    inCard
                    value={lu}
                    onChange={(valeur) => { setLu(valeur); setPage(1); }}
                    items={[
                        { value: '', label: 'Toutes' },
                        { value: 'non_lues', label: 'Non lues', count: nonLues },
                        { value: 'lues', label: 'Lues' },
                    ]}
                />
                <FilterBar onReset={filtre ? () => { setLu(''); setModule(''); setType(''); setDu(''); setAu(''); setPage(1); } : null}>
                    <select className="inp" aria-label="Module" value={module} onChange={(event) => { setModule(event.target.value); setPage(1); }}>
                        {MODULES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                    <select className="inp" aria-label="Type" value={type} onChange={(event) => { setType(event.target.value); setPage(1); }}>
                        {TYPES.map(([value, label]) => <option key={value || 'tous'} value={value}>{label}</option>)}
                    </select>
                    <input className="inp" type="date" aria-label="À partir du" value={du} onChange={(event) => { setDu(event.target.value); setPage(1); }} />
                    <input className="inp" type="date" aria-label="Jusqu’au" value={au} onChange={(event) => { setAu(event.target.value); setPage(1); }} />
                </FilterBar>
                {chargement ? (
                    <p className="subtle" style={{ padding: 16 }}>Chargement des notifications…</p>
                ) : rows.length === 0 ? (
                    <EmptyState icon={ICON.notifications} title="Aucune notification">
                        {filtre ? 'Aucune notification ne correspond aux filtres.' : 'Les événements de vos dossiers apparaîtront ici.'}
                    </EmptyState>
                ) : (
                    <ul className="list-rows notice-list">
                        {rows.map((notice) => <NoticeLigne key={notice.id} notice={notice} onOpen={ouvrir} onToggle={lecture} />)}
                    </ul>
                )}
                <Pagination meta={meta} onPage={setPage} noun="notification" />
            </SectionCard>
            <PreferenceRecapitulatif />
        </main>
    );
}
