import type { ButtonHTMLAttributes } from 'react';

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary';
  loading?: boolean;
};

/**
 * Bouton du Design System (tokens partagés). Focus visible (a11y AA), état chargement/disabled.
 */
export function Button({ variant = 'primary', loading, disabled, children, className = '', ...rest }: Props) {
  const base =
    'inline-flex w-full items-center justify-center rounded-pill px-6 py-3 text-base font-semibold ' +
    'transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ' +
    'focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
  const styles =
    variant === 'primary'
      ? 'bg-primary text-surface hover:bg-blue-700'
      : 'border border-line bg-surface text-ink-900 hover:bg-surface-muted';

  return (
    <button className={`${base} ${styles} ${className}`} disabled={disabled || loading} {...rest}>
      {loading ? 'Veuillez patienter…' : children}
    </button>
  );
}
