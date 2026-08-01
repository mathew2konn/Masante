import Image from 'next/image';
import { i18n } from '@masante/shared';

/**
 * Écran de démonstration P0 — prouve que le web consomme la SOURCE UNIQUE :
 * tokens Tailwind (primary/secondary/background) + libellés i18n de @masante/shared.
 * Aucune règle métier ici (frontière — CDC_02 §0.1).
 * Logo : copié dans public/ (canonique = apps/mobile/assets/images/logo.png).
 */
export default function Home() {
  const t = i18n.fr;
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-8">
      <Image
        src="/logo.png"
        alt="Logo MaSanté"
        width={200}
        height={200}
        priority
        className="h-[200px] w-[200px] rounded-[44px] object-cover"
      />
      <h1 className="text-5xl font-bold text-primary">{t.app.nom}</h1>
      <p className="text-secondary">{t.app.slogan}</p>
      <span className="rounded-pill bg-primary px-6 py-3 text-surface">
        Socle P0 — Design System partagé (mobile + web)
      </span>
    </main>
  );
}
