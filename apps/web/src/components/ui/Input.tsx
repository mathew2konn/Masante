import { forwardRef, useId, type InputHTMLAttributes } from 'react';

type Props = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  erreur?: string;
};

/**
 * Champ de saisie du Design System. Libellé toujours associé (a11y AA), erreur reliée
 * par aria-describedby, focus visible. Compatible React Hook Form via forwardRef.
 */
export const Input = forwardRef<HTMLInputElement, Props>(function Input(
  { label, erreur, id, className = '', ...rest },
  ref,
) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const erreurId = `${inputId}-erreur`;

  return (
    <div className="mb-4">
      <label htmlFor={inputId} className="mb-1 block text-sm font-semibold text-ink-700">
        {label}
      </label>
      <input
        id={inputId}
        ref={ref}
        aria-invalid={erreur ? true : undefined}
        aria-describedby={erreur ? erreurId : undefined}
        className={
          'w-full rounded-md border bg-surface-muted px-3 py-2 text-ink-900 ' +
          'focus:outline focus:outline-2 focus:outline-primary ' +
          (erreur ? 'border-danger ' : 'border-line ') +
          className
        }
        {...rest}
      />
      {erreur ? (
        <p id={erreurId} className="mt-1 text-sm text-danger">
          {erreur}
        </p>
      ) : null}
    </div>
  );
});
