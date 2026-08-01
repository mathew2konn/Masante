import type { Metadata } from 'next';

/** Espace d'authentification — pages privées, non indexables (CDC_02). */
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-6">
      <div className="w-full max-w-md">{children}</div>
    </main>
  );
}
