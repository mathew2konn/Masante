'use client';

/** Frontière d'erreur du portail (App Router exige un composant client). */
export default function Error({ reset }: { error: Error; reset: () => void }) {
  return (
    <div className="p-6">
      <h2 className="mb-2 text-lg font-semibold text-danger">Une erreur est survenue</h2>
      <p className="mb-4 text-sm text-ink-700">Réessayez ; si le problème persiste, reconnectez-vous.</p>
      <button
        onClick={reset}
        className="rounded-pill border border-line px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-surface-muted"
      >
        Réessayer
      </button>
    </div>
  );
}
