import Constants from 'expo-constants';
import { api } from '../config/api';

/**
 * services/presenceRdv.ts — B1-c (D9) — présence temps réel pendant l'accès partagé d'un RDV.
 *
 * CLIENT PROTOCOLE PUSHER ÉCRIT À LA MAIN, ZÉRO DÉPENDANCE NEUVE. Reverb parle le protocole
 * Pusher sur une WebSocket brute (`Illuminate\Broadcasting\Broadcasters\ReverbBroadcaster` est un
 * pilote Pusher-compatible) — React Native expose déjà `WebSocket` globalement, donc `pusher-js` +
 * `laravel-echo` ne sont pas nécessaires pour n'écouter qu'UN canal privé et TROIS événements.
 * Ajouter deux dépendances (dont `pusher-js`, non conçue pour React Native sans polyfill) pour ce
 * périmètre aurait été une dépendance de confort, pas de nécessité (§2.6) — précédent P11.2
 * (clé + HMAC plutôt qu'OAuth2), P6.1 (mod-97 sans bcmath/gmp).
 *
 * PORTÉE ASSUMÉE, DITE PLUTÔT QUE DÉGUISÉE : aucune reconnexion automatique ni retry — un
 * décrochage réseau referme simplement le suivi (l'écran l'affiche), le patient rouvre l'écran.
 * La fiche de parcours (P7-D2) reste la source de vérité APRÈS COUP ; ce canal n'est qu'un
 * confort PENDANT la consultation.
 */

export type EtatPresence = 'connexion' | 'attente' | 'ouvert' | 'ecriture' | 'ferme' | 'indisponible';

export interface EvenementPresence {
  etat: EtatPresence;
  medecin?: string;
}

interface TrameEntrante {
  event: string;
  data?: string;
  channel?: string;
}

/** Configuration Reverb (app.config.ts -> extra.reverb*), séparée d'`API_URL` : Reverb écoute un
 *  PORT DIFFÉRENT de l'API HTTP (précédent : deux tunnels Ngrok distincts en test réel, dit en
 *  limite plutôt que masqué). */
const REVERB_HOST = (Constants.expoConfig?.extra?.reverbHost as string | undefined) ?? '';
const REVERB_PORT = (Constants.expoConfig?.extra?.reverbPort as number | undefined) ?? 8085;
const REVERB_SCHEME = (Constants.expoConfig?.extra?.reverbScheme as string | undefined) ?? 'ws';
const REVERB_KEY = (Constants.expoConfig?.extra?.reverbKey as string | undefined) ?? '';

/** Le canal N'EST JAMAIS ouvert si la configuration manque : mieux vaut « indisponible » qu'une
 *  tentative de connexion vers une chaîne vide. */
export function presenceConfiguree(): boolean {
  return REVERB_HOST !== '' && REVERB_KEY !== '';
}

export class SuiviPresenceRdv {
  private socket: WebSocket | null = null;
  private socketId: string | null = null;
  private ferme = false;

  constructor(
    private readonly rdvId: number,
    private readonly onEvenement: (e: EvenementPresence) => void,
  ) {}

  connecter(): void {
    if (!presenceConfiguree()) {
      this.onEvenement({ etat: 'indisponible' });
      return;
    }

    this.onEvenement({ etat: 'connexion' });

    const url = `${REVERB_SCHEME}://${REVERB_HOST}:${REVERB_PORT}/app/${REVERB_KEY}?protocol=7&client=masante-mobile&version=1.0`;
    this.socket = new WebSocket(url);
    this.socket.onmessage = (event) => void this.recevoir(event.data as string);
    this.socket.onerror = () => this.onEvenement({ etat: 'indisponible' });
    this.socket.onclose = () => {
      if (!this.ferme) this.onEvenement({ etat: 'indisponible' });
    };
  }

  /** Referme le socket. Idempotent : appelable même si `connecter()` n'a jamais réussi. */
  fermer(): void {
    this.ferme = true;
    this.socket?.close();
    this.socket = null;
  }

  private async recevoir(brut: string): Promise<void> {
    let trame: TrameEntrante;
    try {
      trame = JSON.parse(brut) as TrameEntrante;
    } catch {
      return; // trame illisible : ignorée, jamais un écran cassé pour un confort
    }

    const donnees = trame.data ? (this.parseJson(trame.data) ?? {}) : {};

    if (trame.event === 'pusher:connection_established') {
      const info = this.parseJson(trame.data ?? '{}') as { socket_id?: string } | null;
      this.socketId = info?.socket_id ?? null;
      await this.souscrire();
      return;
    }

    // DÉFAUT RÉEL TROUVÉ AU G2 LIVE : sans réponse au ping, Reverb referme la socket au bout de
    // `activity_timeout` (30 s par défaut) — code 4201 « Pong reply not received in time ». Le
    // protocole Pusher l'exige explicitement ; un client de test sans cette ligne l'a d'abord
    // révélé (fermeture après ~30 s malgré un abonnement réussi), reproduit ici avant d'être
    // corrigé dans CE fichier, celui réellement livré.
    if (trame.event === 'pusher:ping') {
      this.socket?.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      return;
    }

    if (trame.event === 'partage.ouvert') {
      this.onEvenement({ etat: 'ouvert', medecin: (donnees as { medecin?: string }).medecin });
    } else if (trame.event === 'partage.ecriture') {
      this.onEvenement({ etat: 'ecriture' });
    } else if (trame.event === 'partage.ferme') {
      this.onEvenement({ etat: 'ferme' });
    }
  }

  private parseJson(texte: string): Record<string, unknown> | null {
    try {
      return JSON.parse(texte) as Record<string, unknown>;
    } catch {
      return null;
    }
  }

  /** Autorisation du canal privé (D9) — même route que le web, garde côté serveur seule autorité. */
  private async souscrire(): Promise<void> {
    if (!this.socketId || !this.socket) return;

    const canal = `private-rdv.${this.rdvId}.presence`;

    try {
      const { data } = await api.post<{ auth: string }>('/v1/broadcasting/auth', {
        socket_id: this.socketId,
        channel_name: canal,
      });

      this.socket.send(JSON.stringify({
        event: 'pusher:subscribe',
        data: { channel: canal, auth: data.auth },
      }));
      this.onEvenement({ etat: 'attente' });
    } catch {
      this.onEvenement({ etat: 'indisponible' });
    }
  }
}
