import { NextRequest, NextResponse } from 'next/server';
import { SESSION_COOKIE } from '@/lib/constants';

/**
 * Garde rapide (edge) : redirige vers /login si aucune session n'est présente, AVANT le rendu
 * des pages du portail. Ne fait que vérifier la PRÉSENCE du cookie ; la validité (rôle, expiration)
 * est vérifiée côté serveur par la garde du layout (/auth/me). Aucune règle métier (CDC_02).
 */
export function middleware(req: NextRequest) {
  const aSession = req.cookies.has(SESSION_COOKIE);
  if (!aSession) {
    const url = req.nextUrl.clone();
    url.pathname = '/login';
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = {
  // Tout sauf : les routes d'API (proxy), les assets Next, le login, la page « réservé pros »
  // et le formulaire public de candidature.
  //
  // P11.1 — `rejoindre` est PUBLIC, et c'est tout le point de la méthode 2 de CDC_11 §3 :
  // « Clinique Saint Joseph souhaite rejoindre la plateforme » vient de quelqu'un qui n'a ni
  // compte ni contact préalable. Le protéger reviendrait à la méthode 1, celle où
  // l'administrateur crée lui-même l'établissement.
  matcher: ['/((?!api|_next/static|_next/image|favicon.ico|logo.png|login|reserve-pros|rejoindre).*)'],
};
