import { faAnglesLeft, faBars, faCalendarDays, faChevronDown, faChevronRight, faRightFromBracket, faUserGear } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { Fragment, Suspense, useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import api, { forgetActor, rememberActor } from '../../api/httpClient';
import CeeacMark from '../brand/CeeacMark';
import { NoticeLigne } from '../../features/notifications/NoticeLigne';
import { ouvrirNotification, basculerLecture, type Notice } from '../../features/notifications/ouvrir';
import { errorsOf } from '../../utils/format';
import { findNavigation, navigationFor, navigationForKeys, type NavGroup, type NavItem } from '../../app/navigation';
import { useDismiss } from '../ui/ActionMenu';
import Button from '../ui/Button';
import { Alert, EmptyState, PageSkeleton } from '../ui/Feedback';
import { ICON } from '../ui/icons';
import Modal from '../ui/Modal';

type Actor = { id: number; nom: string; fonction: string | null; role: string | null; initiales: string | null; structure: string | null };
type Session = { courant: Actor; demo: boolean; acteurs: Actor[]; notifications: Notice[]; non_lues: number };

function readPreference(key: string, fallback: string): string {
    try {
        return window.localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function writePreference(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        /* Préférence d’affichage non essentielle. */
    }
}

export default function AppShell() {
    const [session, setSession] = useState<Session | null>(null);
    const [sessionError, setSessionError] = useState<string | null>(null);
    const [allowedKeys, setAllowedKeys] = useState<Set<string> | null>(null);
    const [navigationBadges, setNavigationBadges] = useState<Record<string, number>>({});
    const [collapsed, setCollapsed] = useState(() => readPreference('gesbudep.sidebar', 'open') === 'collapsed');
    const [closedGroups, setClosedGroups] = useState<string[]>(() => readPreference('gesbudep.groups', '').split(',').filter(Boolean));
    const [mobileOpen, setMobileOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const { pathname: path, hash } = useLocation();
    const current = findNavigation(path);

    const chargerSession = useCallback(() => {
        setSessionError(null);
        api.get('/acteurs')
            .then((response) => setSession(response.data))
            .catch((caught) => {
                setSession(null);
                // 401/419 : l’intercepteur renvoie déjà vers la connexion.
                const status = caught?.response?.status;
                if (status === 401 || status === 419) {
                    return;
                }
                setSessionError(!caught?.response || status >= 500
                    ? 'Le serveur de l’application ne répond pas : votre compte et vos données ne peuvent pas être chargés.'
                    : errorsOf(caught));
            });
    }, []);

    useEffect(() => {
        chargerSession();
    }, [chargerSession]);

    useEffect(() => {
        if (!session?.courant?.id) {
            return;
        }
        api.get('/navigation')
            .then((response) => {
                const keys = new Set<string>();
                for (const group of response.data.groups ?? []) {
                    for (const item of group.items ?? []) {
                        if (typeof item.key === 'string') {
                            keys.add(item.key);
                        }
                    }
                }
                setAllowedKeys(keys);
                setNavigationBadges(response.data.badges ?? {});
            })
            .catch(() => {
                setAllowedKeys(null);
                setNavigationBadges({});
            });
    }, [session?.courant?.id, path]);

    useEffect(() => {
        setMobileOpen(false);
        window.scrollTo({ top: 0 });
    }, [path]);

    useEffect(() => {
        function onKey(event: globalThis.KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setPaletteOpen(true);
            }
        }
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        document.title = current ? `${current.item.label} · BUDGET-CEEAC` : 'BUDGET-CEEAC';
    }, [current?.item.label]);

    function toggleCollapsed() {
        setCollapsed((value) => {
            writePreference('gesbudep.sidebar', value ? 'open' : 'collapsed');
            return !value;
        });
    }

    function toggleGroup(id: string) {
        setClosedGroups((list) => {
            const next = list.includes(id) ? list.filter((item) => item !== id) : [...list, id];
            writePreference('gesbudep.groups', next.join(','));
            return next;
        });
    }

    function switchActor(id: string) {
        api.post('/acteurs/courant', { user_id: Number(id) }).then(() => {
            rememberActor(id);
            window.location.reload();
        });
    }

    function logout() {
        api.post('/auth/logout').finally(() => {
            forgetActor();
            window.location.assign('/connexion');
        });
    }

    const actor = session?.courant;
    const navigation = useMemo(
        () => (allowedKeys ? navigationForKeys(allowedKeys) : navigationFor(actor?.role)),
        [allowedKeys, actor?.role],
    );
    const cible = `${path}${hash}`;
    const ancreCourante = navigation.some((group) => group.items.some((item) => item.to === cible));

    return (
        <div className={['app-shell', collapsed && 'is-collapsed', mobileOpen && 'nav-open'].filter(Boolean).join(' ')}>
            <a href="#contenu" className="skip-link">Aller au contenu</a>
            <aside className="sidebar" aria-label="Navigation principale">
                <Link to="/taches" className="sidebar-brand" aria-label="BUDGET-CEEAC, accueil">
                    <CeeacMark />
                    <span className="brand-text">
                        <span className="brand-name">BUDGET-CEEAC</span>
                        <span className="brand-sub">GESBUDEP · Commission de la CEEAC</span>
                    </span>
                </Link>
                <div className="sidebar-context" title="Exercice budgétaire en cours">
                    <FontAwesomeIcon icon={faCalendarDays} />
                    <span>Exercice 2026 · Exécutoire</span>
                </div>
                <nav className="sidebar-nav">
                    {navigation.map((group) => {
                        const open = !closedGroups.includes(group.id) || group.items.some((item) => item.match(path));

                        return (
                            <div key={group.id} className="nav-group">
                                <button type="button" className="nav-group-toggle" aria-expanded={open} onClick={() => toggleGroup(group.id)} title={group.label}>
                                    <span>{group.label}</span>
                                    <FontAwesomeIcon icon={faChevronDown} className="chev" />
                                </button>
                                {(open || collapsed) && group.items.map((item) => {
                                    return (
                                        <Fragment key={item.to}>
                                            <SidebarLink
                                                item={item}
                                                active={item.to === cible || (!ancreCourante && item.match(path))}
                                                count={item.badge ? navigationBadges[item.badge] : undefined}
                                            />
                                        </Fragment>
                                    );
                                })}
                            </div>
                        );
                    })}
                </nav>
                <div className="sidebar-footer">
                    <button type="button" className="sidebar-collapse" onClick={toggleCollapsed} aria-pressed={collapsed} title={collapsed ? 'Déplier la navigation' : 'Réduire la navigation'}>
                        <FontAwesomeIcon icon={faAnglesLeft} />
                        <span>Réduire la navigation</span>
                    </button>
                </div>
            </aside>
            {mobileOpen && <div className="sidebar-scrim" onClick={() => setMobileOpen(false)} aria-hidden="true" />}

            <div className="app-main">
                <header className="topbar">
                    <button type="button" className="icon-button topbar-menu" onClick={() => setMobileOpen(true)} aria-label="Ouvrir la navigation" aria-expanded={mobileOpen}>
                        <FontAwesomeIcon icon={faBars} />
                    </button>
                    <CeeacMark size={32} className="topbar-logo" />
                    <Breadcrumbs path={path} />
                    <div className="topbar-end">
                        <button type="button" className="topbar-search" onClick={() => setPaletteOpen(true)} aria-label="Rechercher un module ou un dossier (Ctrl + K)">
                            <FontAwesomeIcon icon={ICON.search} />
                            <span>Aller à un module…</span>
                            <kbd>Ctrl K</kbd>
                        </button>
                        <Notifications />
                        <span className="topbar-divider" aria-hidden="true" />
                        {actor && <UserMenu session={session!} onSwitch={switchActor} onLogout={logout} />}
                    </div>
                </header>
                <div id="contenu" tabIndex={-1} style={{ outline: 'none' }}>
                    {sessionError && (
                        <div className="app-content" style={{ paddingBottom: 0 }}>
                            <Alert tone="danger" title="Connexion au serveur impossible">
                                <div className="cluster" style={{ justifyContent: 'space-between' }}>
                                    <span>{sessionError} Vérifiez que le serveur est démarré, puis réessayez.</span>
                                    <Button size="sm" icon={ICON.retry} onClick={chargerSession}>Réessayer</Button>
                                </div>
                            </Alert>
                        </div>
                    )}
                    <Suspense fallback={<PageSkeleton />}>
                        <Outlet />
                    </Suspense>
                </div>
            </div>
            {paletteOpen && <CommandPalette groups={navigation} onClose={() => setPaletteOpen(false)} />}
        </div>
    );
}

function SidebarLink({ item, active, count }: { item: NavItem; active: boolean; count?: number }) {
    return (
        <Link
            to={item.to}
            className={['nav-link', item.nested && 'is-nested', active && 'is-active'].filter(Boolean).join(' ')}
            aria-current={active ? 'page' : undefined}
            title={item.label}
        >
            <FontAwesomeIcon icon={item.icon} className="nav-icon" />
            <span className="nav-label">{item.label}</span>
            {count !== undefined && count > 0 && <span className="nav-count" aria-label={`${count} en attente`}>{count > 99 ? '99+' : count}</span>}
        </Link>
    );
}

function Breadcrumbs({ path }: { path: string }) {
    const found = findNavigation(path);
    if (!found) {
        return <nav className="breadcrumbs" aria-label="Fil d’Ariane"><span className="crumb-current">BUDGET-CEEAC</span></nav>;
    }
    const { group, item } = found;
    const parent = item.nested ? group.items.slice(0, group.items.indexOf(item)).reverse().find((entry) => !entry.nested) : undefined;
    const rest = path.length > item.to.length && path.startsWith(item.to) ? path.slice(item.to.length) : '';
    const detail = rest.includes('/cadrage')
        ? 'Cadrage'
        : rest.includes('/consolidation')
            ? 'Consolidation'
            : item.to === '/suivi' && path !== '/suivi'
                ? 'Fiche activité'
                : rest.endsWith('/modifier') ? 'Modification' : rest === '/nouvelle' ? 'Nouvelle' : rest && /\d/.test(rest) && !item.to.includes('gantt') ? 'Fiche' : null;

    return (
        <nav className="breadcrumbs" aria-label="Fil d’Ariane">
            <span className="crumb-parent muted">{group.label}</span>
            {parent && (
                <>
                    <FontAwesomeIcon icon={faChevronRight} className="crumb-sep" />
                    <Link className="crumb-parent" to={parent.to}>{parent.label}</Link>
                </>
            )}
            <FontAwesomeIcon icon={faChevronRight} className="crumb-sep" />
            {detail ? <Link className="crumb-parent" to={item.to}>{item.label}</Link> : <span className="crumb-current" aria-current="page">{item.label}</span>}
            {detail && (
                <>
                    <FontAwesomeIcon icon={faChevronRight} className="crumb-sep" />
                    <span className="crumb-current" aria-current="page">{detail}</span>
                </>
            )}
        </nav>
    );
}

function Notifications() {
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(0);
    const [items, setItems] = useState<Notice[]>([]);
    const [erreur, setErreur] = useState('');
    const close = useCallback(() => setOpen(false), []);
    const ref = useDismiss<HTMLDivElement>(open, close);

    const { pathname } = useLocation();
    const compter = useCallback(() => {
        api.get('/notifications/compteur').then((response) => setUnread(response.data.non_lues ?? 0)).catch(() => undefined);
    }, []);

    // Rafraîchissement léger : à chaque changement d’écran et toutes les
    // 60 secondes tant que l’onglet est visible.
    useEffect(() => {
        compter();
    }, [compter, pathname]);

    useEffect(() => {
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                compter();
            }
        }, 60_000);

        return () => window.clearInterval(timer);
    }, [compter]);

    useEffect(() => {
        compter();
        function maj(event: Event) {
            const detail = (event as CustomEvent<number>).detail;
            if (typeof detail === 'number') {
                setUnread(detail);
            } else {
                compter();
            }
            if (open) {
                api.get('/notifications', { params: { per_page: 8 } })
                    .then((response) => setItems(response.data.data ?? []))
                    .catch(() => undefined);
            }
        }
        function stockage(event: StorageEvent) {
            if (event.key === 'gesbudep.notifications') {
                compter();
            }
        }
        window.addEventListener('notifications:maj', maj);
        window.addEventListener('focus', compter);
        window.addEventListener('storage', stockage);

        return () => {
            window.removeEventListener('notifications:maj', maj);
            window.removeEventListener('focus', compter);
            window.removeEventListener('storage', stockage);
        };
    }, [compter, open]);

    useEffect(() => {
        if (!open) {
            return;
        }
        api.get('/notifications', { params: { per_page: 8 } })
            .then((response) => {
                setItems(response.data.data ?? []);
                setUnread(response.data.non_lues ?? 0);
                setErreur('');
            })
            .catch((caught) => setErreur(errorsOf(caught)));
    }, [open]);

    async function ouvrir(notice: Notice) {
        if (notice.ouverture === 'refusee') {
            close();
            navigate(`/notifications/${notice.id}`);
            return;
        }
        try {
            const resultat = await ouvrirNotification(notice.id);
            close();
            navigate(resultat.chemin ?? `/notifications/${notice.id}`);
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    async function lecture(notice: Notice) {
        try {
            const reste = await basculerLecture(notice);
            setUnread(reste);
            setItems((liste) => liste.map((item) => (item.id === notice.id ? { ...item, lue: !item.lue } : item)));
        } catch (caught) {
            setErreur(errorsOf(caught));
        }
    }

    return (
        <div className="menu-anchor" ref={ref}>
            <button type="button" className="icon-button" aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(!open)} aria-label={`Notifications${unread ? ` : ${unread} non lue(s)` : ''}`} title="Notifications">
                <FontAwesomeIcon icon={ICON.notifications} />
                {unread > 0 && <span className="icon-button-badge">{unread > 9 ? '9+' : unread}</span>}
            </button>
            {open && (
                <div className="menu notif-panel" role="dialog" aria-label="Notifications récentes">
                    <div className="notif-head">
                        <strong>Notifications</strong>
                        <span className="badge tone-info badge-sm">{unread} non lue{unread > 1 ? 's' : ''}</span>
                    </div>
                    {erreur && <p className="notif-meta" style={{ padding: '8px 14px' }}>{erreur}</p>}
                    {items.length === 0 ? (
                        <EmptyState icon={ICON.notifications} title="Aucune notification" compact>Les événements de vos dossiers apparaîtront ici.</EmptyState>
                    ) : (
                        <ul className="notif-list list-rows">
                            {items.map((notice) => <NoticeLigne key={notice.id} notice={notice} onOpen={ouvrir} onToggle={lecture} />)}
                        </ul>
                    )}
                    <div className="notif-foot">
                        <Link to="/notifications" className="menu-item" onClick={close}>
                            <FontAwesomeIcon icon={ICON.notifications} fixedWidth />
                            Toutes les notifications
                        </Link>
                        <Link to="/taches" className="menu-item" onClick={close}>
                            <FontAwesomeIcon icon={ICON.tasks} fixedWidth />
                            Ouvrir Mes tâches
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}

function UserMenu({ session, onSwitch, onLogout }: { session: Session; onSwitch: (id: string) => void; onLogout: () => void }) {
    const [open, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const ref = useDismiss<HTMLDivElement>(open, close);
    const actor = session.courant;

    return (
        <div className="menu-anchor" ref={ref}>
            <button type="button" className="user-button" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)}>
                <span className="avatar size-sm" aria-hidden="true">{actor.initiales || actor.nom.slice(0, 2).toUpperCase()}</span>
                <span className="user-meta">
                    <span className="user-name">{actor.nom}</span>
                    <span className="user-role">{actor.fonction || actor.structure || '—'}</span>
                </span>
                <FontAwesomeIcon icon={faChevronDown} style={{ fontSize: 10, color: 'var(--slate-400)' }} />
            </button>
            {open && (
                <div className="menu" style={{ minWidth: 280 }}>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '8px 10px 10px' }}>
                        <span className="avatar" aria-hidden="true">{actor.initiales || actor.nom.slice(0, 2).toUpperCase()}</span>
                        <div style={{ minWidth: 0 }}>
                            <div className="strong truncate">{actor.nom}</div>
                            <div className="subtle truncate">{actor.fonction || '—'}</div>
                            {actor.structure && <div className="subtle truncate">{actor.structure}</div>}
                        </div>
                    </div>
                    {session.demo && (
                        <>
                            <div className="menu-separator" />
                            <div className="menu-label">Mode démonstration</div>
                            <div style={{ padding: '4px 10px 8px' }}>
                                <label className="field-label" htmlFor="actor-switch" style={{ marginBottom: 6 }}>
                                    <FontAwesomeIcon icon={faUserGear} style={{ color: 'var(--slate-400)' }} /> Changer d’acteur
                                </label>
                                <select id="actor-switch" className="inp inp-sm" value={actor.id} onChange={(event) => onSwitch(event.target.value)}>
                                    {session.acteurs.map((item) => <option key={item.id} value={item.id}>{item.nom} · {item.fonction}</option>)}
                                </select>
                            </div>
                        </>
                    )}
                    <div className="menu-separator" />
                    <button type="button" className="menu-item is-danger" onClick={onLogout}>
                        <FontAwesomeIcon icon={faRightFromBracket} fixedWidth />
                        Se déconnecter
                    </button>
                </div>
            )}
        </div>
    );
}

function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

type CommandHit = { key: string; to: string; label: string; group: string; icon: NavItem['icon'] };

/** Palette (Ctrl + K) : modules, puis dossiers financiers dont la référence ou l’objet correspond. */
function CommandPalette({ groups, onClose }: { groups: NavGroup[]; onClose: () => void }) {
    const [query, setQuery] = useState('');
    const [index, setIndex] = useState(0);
    const [dossiers, setDossiers] = useState<CommandHit[]>([]);
    const navigate = useNavigate();
    const list = useRef<HTMLUListElement>(null);
    const entries = useMemo(() => groups.flatMap((group) => group.items.map((item) => ({ group: group.label, item }))), [groups]);
    const modules = useMemo(() => {
        const words = normalize(query).split(/\s+/).filter(Boolean);

        return entries.filter(({ group, item }) => {
            const haystack = normalize(`${item.label} ${group} ${item.keywords ?? ''}`);
            return words.every((word) => haystack.includes(word));
        }).map(({ group, item }): CommandHit => ({
            key: item.to,
            to: item.to,
            label: item.label,
            group,
            icon: item.icon,
        }));
    }, [entries, query]);
    const results = useMemo(() => [...modules, ...dossiers], [modules, dossiers]);
    const actif = results.length === 0 ? 0 : Math.min(index, results.length - 1);

    useEffect(() => setIndex(0), [query]);
    useEffect(() => {
        const terme = query.trim();
        if (terme.length < 2) {
            setDossiers([]);
            return;
        }
        let ignore = false;
        const timer = window.setTimeout(() => {
            api.get('/chaine/dossier', { params: { q: terme } })
                .then((response) => {
                    if (ignore) {
                        return;
                    }
                    setDossiers((response.data.data ?? []).map((ligne: { id: number; reference: string; objet?: string }): CommandHit => ({
                        key: `dossier-${ligne.id}`,
                        to: `/chaine/dossier/${ligne.id}`,
                        label: ligne.objet ? `${ligne.reference} — ${ligne.objet}` : ligne.reference,
                        group: 'Dossier',
                        icon: ICON.need,
                    })));
                })
                .catch(() => {
                    if (!ignore) {
                        setDossiers([]);
                    }
                });
        }, 250);

        return () => {
            ignore = true;
            window.clearTimeout(timer);
        };
    }, [query]);
    useEffect(() => {
        list.current?.querySelector('[aria-selected=true]')?.scrollIntoView({ block: 'nearest' });
    }, [actif]);

    function go(hit: CommandHit) {
        onClose();
        navigate(hit.to);
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') { event.preventDefault(); setIndex((value) => Math.min(value + 1, results.length - 1)); }
        if (event.key === 'ArrowUp') { event.preventDefault(); setIndex((value) => Math.max(value - 1, 0)); }
        if (event.key === 'Enter' && results[actif]) { event.preventDefault(); go(results[actif]); }
    }

    return (
        <Modal title="Aller à un module ou un dossier" onClose={onClose} size="md">
            <div style={{ position: 'relative', margin: '-18px -20px 0' }}>
                <FontAwesomeIcon icon={ICON.search} className="command-icon" />
                <input
                    className="inp command-input"
                    data-autofocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="Module, référence ou objet d’un dossier…"
                    aria-label="Rechercher un module ou un dossier"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="command-results"
                    aria-activedescendant={results[actif] ? `cmd-${results[actif].key}` : undefined}
                />
            </div>
            {results.length === 0 ? (
                <EmptyState icon={ICON.search} title="Aucun résultat" compact>Essayez un module ou une référence de la chaîne de dépense.</EmptyState>
            ) : (
                <ul id="command-results" ref={list} role="listbox" className="command-list" style={{ margin: '0 -12px -10px' }}>
                    {results.map((hit, position) => (
                        <li key={hit.key} id={`cmd-${hit.key}`} role="option" aria-selected={position === actif}>
                            <button type="button" className="menu-item command-item" aria-selected={position === actif} onMouseEnter={() => setIndex(position)} onClick={() => go(hit)} tabIndex={-1}>
                                <FontAwesomeIcon icon={hit.icon} fixedWidth />
                                {hit.label}
                                <span className="command-group">{hit.group}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}
