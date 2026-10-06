import { faCalendarDays, faCodeBranch, faHashtag, faListUl, faPlug, faScaleBalanced, faShieldHalved, faSliders } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { FormEvent, useEffect, useState } from 'react';
import api from '../../../api/httpClient';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorMessage,
    FormField,
    ICON,
    InputGroup,
    KeyValueList,
    PageHeader,
    PageSkeleton,
    SectionCard,
    Tabs,
    useToast,
} from '../../../components/ui';
import { errorsOf } from '../../../utils/format';

const PANELS = [
    { value: 'parametres', label: 'Paramètres', icon: faSliders },
    { value: 'workflows', label: 'Workflows', icon: faCodeBranch },
    { value: 'seuils', label: 'Seuils', icon: faScaleBalanced },
    { value: 'referentiels', label: 'Référentiels', icon: faListUl },
    { value: 'numerotation', label: 'Numérotation', icon: faHashtag },
    { value: 'securite', label: 'Sécurité', icon: faShieldHalved },
    { value: 'integrations', label: 'Intégrations', icon: faPlug },
    { value: 'audit', label: 'Journal d’audit', icon: ICON.history },
];

export default function AdminSettings() {
    const toast = useToast();
    const [panel, setPanel] = useState('parametres');
    const [settings, setSettings] = useState<any>(null);
    const [workflows, setWorkflows] = useState<any[]>([]);
    const [thresholds, setThresholds] = useState<any[]>([]);
    const [integrations, setIntegrations] = useState<any[]>([]);
    const [audit, setAudit] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        Promise.all([
            api.get('/admin/parametres'),
            api.get('/admin/workflows'),
            api.get('/admin/seuils'),
            api.get('/admin/integrations'),
            api.get('/admin/audit'),
        ]).then(([settingsResponse, workflowsResponse, thresholdsResponse, integrationsResponse, auditResponse]) => {
            setSettings(settingsResponse.data);
            setWorkflows(workflowsResponse.data.data);
            setThresholds(thresholdsResponse.data.data);
            setIntegrations(integrationsResponse.data.data);
            setAudit(auditResponse.data.data);
        }).catch((caught) => setError(errorsOf(caught))).finally(() => setLoading(false));
    }, []);

    async function saveBusinessRule(id, payload) {
        setError('');
        try {
            const response = await api.put(`/admin/regles-metier/${id}`, payload);
            setSettings((current) => ({
                ...current,
                regles_metier: current.regles_metier.map((rule) => rule.id === id ? response.data.data : rule),
            }));
            toast.success('Règle métier enregistrée.');
            return response.data.data;
        } catch (caught) {
            setError(errorsOf(caught));
            toast.error(errorsOf(caught));
            return null;
        }
    }

    if (loading) {
        return <PageSkeleton />;
    }

    return (
        <main className="app-content">
            <PageHeader
                back={{ to: '/administration', label: 'Administration' }}
                title="Paramétrage"
                subtitle="Les règles publiées se versionnent. Les secrets d’intégration ne sont jamais réaffichés."
            />
            <ErrorMessage error={error} onClose={() => setError('')} />

            <Tabs label="Rubriques du paramétrage" items={PANELS} value={panel} onChange={setPanel} />

            {settings && panel === 'parametres' && (
                <div className="layout-aside is-wide">
                    <div className="stack">
                        <SectionCard title="Règles métier" icon={faScaleBalanced} subtitle="Chaque modification exige un motif.">
                            {settings.regles_metier.length === 0 && <EmptyState title="Aucune règle métier" compact />}
                            {settings.regles_metier.map((rule) => <BusinessRuleEditor key={rule.id} rule={rule} onSave={saveBusinessRule} />)}
                        </SectionCard>
                        <SectionCard title="Paramètres généraux" icon={faSliders} flush>
                            <DataTable
                                columns={[
                                    { key: 'key', header: 'Clé', className: 'mono', render: (row: any) => row.key },
                                    { key: 'value', header: 'Valeur', render: (row: any) => row.value },
                                    { key: 'critical', header: 'Sensibilité', render: (row: any) => row.critical ? <Badge tone="danger" icon={ICON.lock} size="sm">Critique</Badge> : <span className="subtle">—</span> },
                                ]}
                                rows={settings.data}
                                rowKey={(row: any) => row.key}
                            />
                        </SectionCard>
                    </div>
                    <aside className="stack">
                        <SectionCard title="Exercices" icon={faCalendarDays}>
                            <ul className="list-rows">
                                {settings.exercices.map((row) => (
                                    <li key={row.annee} className="list-row"><span className="list-row-main mono strong">{row.annee}</span><Badge tone="neutral" size="sm">{row.statut}</Badge></li>
                                ))}
                            </ul>
                        </SectionCard>
                        <SectionCard title="Calendrier" icon={faCalendarDays}>
                            {settings.calendrier.length === 0 ? <span className="muted">Aucun jour férié paramétré.</span> : (
                                <ul className="list-rows">
                                    {settings.calendrier.map((row) => (
                                        <li key={`${row.holiday_on}-${row.label}`} className="list-row"><span className="mono subtle" style={{ width: 96 }}>{row.holiday_on}</span><span className="list-row-main">{row.label}</span></li>
                                    ))}
                                </ul>
                            )}
                        </SectionCard>
                    </aside>
                </div>
            )}

            {panel === 'workflows' && (
                <div className="stack">
                    {workflows.length === 0 && <div className="card"><EmptyState icon={faCodeBranch} title="Aucun workflow" /></div>}
                    {workflows.map((workflow) => (
                        <SectionCard key={workflow.id} title={workflow.label} icon={faCodeBranch}>
                            {(workflow.versions ?? []).map((version) => (
                                <div key={version.id} className="stack-sm" style={{ paddingBottom: 10, borderBottom: '1px solid var(--color-divider)' }}>
                                    <div className="cluster">
                                        <Badge tone="brand" size="sm">v{version.version}</Badge>
                                        <Badge tone={version.status === 'publie' || version.status === 'actif' ? 'success' : 'neutral'} size="sm" dot>{version.status}</Badge>
                                    </div>
                                    <div className="cluster" style={{ gap: 4 }}>
                                        {(version.steps ?? []).map((step, index) => (
                                            <span key={index} className="cluster" style={{ gap: 4 }}>
                                                {index > 0 && <FontAwesomeIcon icon={ICON.next} style={{ color: 'var(--slate-300)', fontSize: 10 }} />}
                                                <Badge tone="neutral" size="sm"><span className="mono">{step.actor_role}</span></Badge>
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </SectionCard>
                    ))}
                </div>
            )}

            {panel === 'seuils' && (
                <SectionCard title="Seuils d’administration" icon={faScaleBalanced} flush>
                    <DataTable
                        columns={[
                            { key: 'code', header: 'Code', className: 'mono', render: (row: any) => row.code },
                            { key: 'operation', header: 'Opération', render: (row: any) => row.operation },
                            { key: 'tranche', header: 'Tranche', className: 'mono', render: (row: any) => `${row.min_amount} – ${row.max_amount ?? 'ouvert'}` },
                            { key: 'role', header: 'Acteur', render: (row: any) => <Badge tone="brand" size="sm"><span className="mono">{row.actor_role}</span></Badge> },
                        ]}
                        rows={thresholds}
                        rowKey={(row: any) => row.id}
                        empty={<EmptyState icon={faScaleBalanced} title="Aucun seuil d’administration" compact>Le seuil d’ordonnancement en vigueur reste celui des délégations de signature.</EmptyState>}
                    />
                </SectionCard>
            )}

            {settings && panel === 'referentiels' && (
                <div className="grid-thirds">
                    <SectionCard title="Référentiels" icon={faListUl} flush>
                        <DataTable
                            compact
                            columns={[
                                { key: 'set', header: 'Ensemble', className: 'mono', render: (row: any) => row.set_code },
                                { key: 'code', header: 'Code', className: 'mono', render: (row: any) => row.code },
                                { key: 'label', header: 'Libellé', render: (row: any) => row.label },
                            ]}
                            rows={settings.referentiels}
                            rowKey={(row: any) => `${row.set_code}-${row.code}`}
                        />
                    </SectionCard>
                    <SectionCard title="Pièces" icon={ICON.attachment} flush>
                        <DataTable
                            compact
                            columns={[
                                { key: 'label', header: 'Pièce', render: (row: any) => row.label },
                                { key: 'operation', header: 'Opération', render: (row: any) => row.operation },
                                { key: 'required', header: 'Statut', render: (row: any) => <Badge tone={row.required ? 'warning' : 'neutral'} size="sm">{row.required ? 'Obligatoire' : 'Facultative'}</Badge> },
                            ]}
                            rows={settings.pieces}
                            rowKey={(row: any) => row.code}
                        />
                    </SectionCard>
                    <SectionCard title="Fonctions" icon={ICON.users}>
                        <ul className="list-rows">{settings.fonctions.map((row) => <li key={row.code} className="list-row">{row.label}</li>)}</ul>
                    </SectionCard>
                </div>
            )}

            {settings && panel === 'numerotation' && (
                <SectionCard title="Séquences de numérotation" icon={faHashtag} flush>
                    <DataTable
                        columns={[
                            { key: 'code', header: 'Séquence', render: (row: any) => row.code },
                            { key: 'format', header: 'Format', render: (row: any) => <span className="mono strong">{row.prefix}{row.separator}{row.exercise_year}{row.separator}{'0'.repeat(row.padding)}</span> },
                            { key: 'last', header: 'Dernier numéro', align: 'right', className: 'mono', render: (row: any) => row.last_value },
                        ]}
                        rows={settings.sequences}
                        rowKey={(row: any) => row.code}
                    />
                </SectionCard>
            )}

            {settings && panel === 'securite' && (
                <SectionCard title="Politique de sécurité" icon={faShieldHalved}>
                    <KeyValueList items={[
                        { label: 'Longueur minimale du mot de passe', value: settings.securite?.min_length, mono: true },
                        { label: 'Échecs avant verrouillage', value: settings.securite?.max_failures, mono: true },
                        { label: 'Durée du verrouillage', value: settings.securite?.lock_minutes !== undefined ? `${settings.securite.lock_minutes} min` : null, mono: true },
                    ]} />
                    <p className="subtle">MFA exigée pour les profils sensibles configurés. L’authentification de démonstration reste l’en-tête d’acteur.</p>
                </SectionCard>
            )}

            {panel === 'integrations' && (
                <SectionCard title="Intégrations" icon={faPlug} flush>
                    <DataTable
                        columns={[
                            { key: 'name', header: 'Intégration', render: (row: any) => <span className="cell-primary">{row.name}</span> },
                            { key: 'environment', header: 'Environnement', render: (row: any) => <Badge tone="neutral" size="sm">{row.environment}</Badge> },
                            { key: 'secret', header: 'Secret', render: (row: any) => <Badge tone={row.secret_renseigne ? 'success' : 'warning'} icon={row.secret_renseigne ? ICON.lock : ICON.warning} size="sm">{row.secret_renseigne ? 'Renseigné' : 'Absent'}</Badge> },
                        ]}
                        rows={integrations}
                        rowKey={(row: any) => row.id}
                        empty={<EmptyState icon={faPlug} title="Aucune intégration déclarée" compact />}
                    />
                </SectionCard>
            )}

            {panel === 'audit' && (
                <SectionCard title="Journal d’audit" icon={ICON.history} flush>
                    <DataTable
                        columns={[
                            { key: 'le', header: 'Quand', className: 'mono', render: (row: any) => row.le },
                            { key: 'acteur', header: 'Acteur', render: (row: any) => row.acteur },
                            { key: 'action', header: 'Action', render: (row: any) => <span className="mono">{row.action}</span> },
                            { key: 'objet', header: 'Objet', render: (row: any) => row.objet },
                            { key: 'motif', header: 'Motif', render: (row: any) => row.motif || <span className="subtle">—</span> },
                        ]}
                        rows={audit}
                        rowKey={(row: any) => row.id}
                        minWidth={820}
                        empty={<EmptyState icon={ICON.history} title="Journal vide" compact />}
                    />
                </SectionCard>
            )}
        </main>
    );
}

function BusinessRuleEditor({ rule, onSave }) {
    const [value, setValue] = useState(String(rule.value ?? ''));
    const [active, setActive] = useState(Boolean(rule.active));
    const [motif, setMotif] = useState('');
    const [pending, setPending] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        const updated = await onSave(rule.id, { value, active, motif });
        setPending(false);
        if (updated) {
            setValue(String(updated.value));
            setActive(Boolean(updated.active));
            setMotif('');
        }
    }

    return (
        <form onSubmit={submit} className="card" style={{ padding: 14, boxShadow: 'none', display: 'flex', flexDirection: 'column', gap: 12, background: 'var(--slate-50)' }}>
            <div className="split">
                <strong>{rule.label}</strong>
                <label className="inline-check">
                    <input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} />
                    <span>{active ? 'Règle active' : 'Règle inactive'}</span>
                </label>
            </div>
            <div className="form-grid" style={{ alignItems: 'end' }}>
                <FormField label="Valeur" required>
                    <InputGroup unit={rule.unit}>
                        <input className="inp num align-right" type="number" min="0" step="1" value={value} onChange={(event) => setValue(event.target.value)} />
                    </InputGroup>
                </FormField>
                <FormField label="Motif de modification" required>
                    <input className="inp" value={motif} onChange={(event) => setMotif(event.target.value)} maxLength={255} />
                </FormField>
            </div>
            <div className="form-actions">
                <Button variant="primary" size="sm" type="submit" icon={ICON.save} loading={pending} disabled={!motif.trim() || value === ''}>Enregistrer la règle</Button>
            </div>
        </form>
    );
}
