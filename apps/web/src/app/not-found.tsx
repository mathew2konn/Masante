import Link from 'next/link';

/** 404 global. */
export default function NotFound() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-center">
      <h1 className="text-2xl font-bold text-blue-900">Page introuvable</h1>
      <Link href="/" className="text-primary underline">
        Retour à l’accueil
      </Link>
    </main>
  );
}
