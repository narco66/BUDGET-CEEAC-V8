import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { createContext, useCallback, useContext, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Button, { type ButtonVariant } from './Button';
import { FormField } from './Form';
import { ICON } from './icons';
import Modal from './Modal';

export type PromptField = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'date' | 'number';
    required?: boolean;
    defaultValue?: string;
    hint?: string;
    placeholder?: string;
    maxLength?: number;
};

type DialogTone = 'default' | 'danger' | 'warning' | 'success';

type ConfirmOptions = {
    title: string;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    tone?: DialogTone;
    icon?: IconDefinition;
};

type PromptOptions = ConfirmOptions & { fields: PromptField[] };

type Pending =
    | { kind: 'confirm'; options: ConfirmOptions; resolve: (value: boolean) => void }
    | { kind: 'prompt'; options: PromptOptions; resolve: (value: Record<string, string> | null) => void };

type DialogApi = {
    confirm: (options: ConfirmOptions) => Promise<boolean>;
    prompt: (options: PromptOptions) => Promise<Record<string, string> | null>;
};

const DialogContext = createContext<DialogApi | null>(null);

const VARIANT: Record<DialogTone, ButtonVariant> = { default: 'primary', danger: 'danger', warning: 'brand', success: 'primary' };
const DEFAULT_ICON: Record<DialogTone, IconDefinition> = { default: ICON.info, danger: ICON.warning, warning: ICON.warning, success: ICON.success };

/**
 * Remplace window.confirm et window.prompt par des boîtes accessibles, au
 * style de l’application, avec libellés, champs obligatoires et validation.
 */
export function DialogProvider({ children }: { children: ReactNode }) {
    const [pending, setPending] = useState<Pending | null>(null);

    const confirm = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => setPending({ kind: 'confirm', options, resolve })), []);
    const prompt = useCallback((options: PromptOptions) => new Promise<Record<string, string> | null>((resolve) => setPending({ kind: 'prompt', options, resolve })), []);
    const api = useMemo(() => ({ confirm, prompt }), [confirm, prompt]);

    function close(value: any) {
        pending?.resolve(value);
        setPending(null);
    }

    return (
        <DialogContext.Provider value={api}>
            {children}
            {pending?.kind === 'confirm' && <ConfirmBox options={pending.options} onDone={close} />}
            {pending?.kind === 'prompt' && <PromptBox options={pending.options} onDone={close} />}
        </DialogContext.Provider>
    );
}

export function useDialogs(): DialogApi {
    const context = useContext(DialogContext);
    if (!context) {
        throw new Error('useDialogs doit être utilisé dans un DialogProvider.');
    }

    return context;
}

function ConfirmBox({ options, onDone }: { options: ConfirmOptions; onDone: (value: boolean) => void }) {
    const tone = options.tone ?? 'default';

    return (
        <Modal
            size="sm"
            title={options.title}
            icon={options.icon ?? DEFAULT_ICON[tone]}
            tone={tone}
            onClose={() => onDone(false)}
            footer={(
                <>
                    <Button onClick={() => onDone(false)}>{options.cancelLabel ?? 'Annuler'}</Button>
                    <Button variant={VARIANT[tone]} onClick={() => onDone(true)} data-autofocus>{options.confirmLabel ?? 'Confirmer'}</Button>
                </>
            )}
        >
            {options.description && <div className="muted">{options.description}</div>}
        </Modal>
    );
}

function PromptBox({ options, onDone }: { options: PromptOptions; onDone: (value: Record<string, string> | null) => void }) {
    const tone = options.tone ?? 'default';
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(options.fields.map((field) => [field.name, field.defaultValue ?? ''])));
    const [touched, setTouched] = useState(false);
    const formId = useRef(`prompt-${Math.random().toString(36).slice(2)}`).current;
    const missing = options.fields.filter((field) => field.required && !values[field.name]?.trim());

    function submit(event: FormEvent) {
        event.preventDefault();
        setTouched(true);
        if (missing.length === 0) {
            onDone(values);
        }
    }

    return (
        <Modal
            size="sm"
            title={options.title}
            description={options.description}
            icon={options.icon ?? DEFAULT_ICON[tone]}
            tone={tone}
            onClose={() => onDone(null)}
            footer={(
                <>
                    <Button onClick={() => onDone(null)}>{options.cancelLabel ?? 'Annuler'}</Button>
                    <Button variant={VARIANT[tone]} type="submit" form={formId}>{options.confirmLabel ?? 'Confirmer'}</Button>
                </>
            )}
        >
            <form id={formId} onSubmit={submit} className="stack" noValidate>
                {options.fields.map((field, index) => {
                    const error = touched && field.required && !values[field.name]?.trim() ? 'Ce champ est obligatoire.' : undefined;
                    const common = {
                        value: values[field.name] ?? '',
                        placeholder: field.placeholder,
                        maxLength: field.maxLength,
                        'data-autofocus': index === 0 ? true : undefined,
                        onChange: (event: { target: { value: string } }) => setValues((current) => ({ ...current, [field.name]: event.target.value })),
                    };

                    return (
                        <FormField key={field.name} label={field.label} required={field.required} hint={field.hint} error={error}>
                            {field.type === 'textarea'
                                ? <textarea className="inp" rows={3} {...common} />
                                : <input className="inp" type={field.type ?? 'text'} {...common} />}
                        </FormField>
                    );
                })}
            </form>
        </Modal>
    );
}
