import { faCheck, faXmark } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import type { ReactNode } from 'react';

export type StepState = 'done' | 'current' | 'todo' | 'rejected';
export type StepItem = { label: ReactNode; state: StepState; hint?: ReactNode };

/**
 * Suite d’étapes (assistant de saisie, circuit d’un dossier). Avec `onSelect`,
 * chaque étape devient un bouton de navigation.
 */
export default function Stepper({ steps, onSelect, label, className }: {
    steps: StepItem[];
    onSelect?: (index: number) => void;
    label: string;
    className?: string;
}) {
    return (
        <ol className={['stepper', className].filter(Boolean).join(' ')} aria-label={label}>
            {steps.map((step, index) => {
                const classes = `step is-${step.state === 'todo' ? 'todo' : step.state}`;
                const bubble = (
                    <span className="step-bubble" aria-hidden="true">
                        {step.state === 'done' ? <FontAwesomeIcon icon={faCheck} /> : step.state === 'rejected' ? <FontAwesomeIcon icon={faXmark} /> : index + 1}
                    </span>
                );
                const text = (
                    <span style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 1 }}>
                        <span className="step-label">{step.label}</span>
                        {step.hint && <span style={{ fontSize: 10.5, color: 'var(--warning-fg)' }}>{step.hint}</span>}
                    </span>
                );

                return (
                    <li key={index} className="stepper-item" aria-current={step.state === 'current' ? 'step' : undefined}>
                        {index > 0 && <span className={`stepper-line${step.state === 'done' || step.state === 'current' ? ' is-done' : ''}`} aria-hidden="true" />}
                        {onSelect ? (
                            <button type="button" className={classes} onClick={() => onSelect(index)}>{bubble}{text}</button>
                        ) : (
                            <span className={classes}>{bubble}{text}</span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
