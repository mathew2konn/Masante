import type { HTMLAttributes } from 'react';

/** Carte blanche du Design System (contenu lisible posé sur le fond signature). */
export function Card({ className = '', children, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={`rounded-card border border-line bg-surface p-6 shadow-sm ${className}`} {...rest}>
      {children}
    </div>
  );
}
