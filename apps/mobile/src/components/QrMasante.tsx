import React from 'react';
import QRCode from 'react-native-qrcode-svg';
import { colors } from '../theme/theme';

const logo = require('../../assets/images/logo.png');

/**
 * components/QrMasante.tsx — LE code QR de MaSanté (P10a, décision propriétaire D7).
 *
 * ═══ POURQUOI UN COMPOSANT ET NON QUATRE APPELS ═══
 *
 * Le projet dessinait des QR à cinq endroits (carte CMU, QR d'un membre, enrôlement MFA, reçu de
 * paiement, et désormais la fiche de triage §5.4), chacun répétant taille, couleurs et fond. La
 * décision « le logo au centre de TOUS les QR » ne se tient que si un seul endroit décide de ce à
 * quoi ressemble un QR — sinon le cinquième appel oubliera le logo, comme les sept communes
 * d'Abidjan avaient divergé côté mobile (constat G-a de P6.4b).
 *
 * ═══ POURQUOI LE NIVEAU DE CORRECTION « H » N'EST PAS UN DÉTAIL ═══
 *
 * Poser un logo au centre d'un QR, c'est EFFACER des modules de données. Le code ne reste lisible
 * que grâce à la redondance de Reed-Solomon, et seul le niveau **H** (~30 % de récupération) tolère
 * une occultation centrale confortable. Aux niveaux inférieurs, le QR *paraît* correct à l'œil et
 * cesse d'être scanné par certains lecteurs — une panne qui ne se voit pas sur l'écran du
 * développeur.
 *
 * Le logo est donc **plafonné à 20 %** du côté : au-delà, même « H » ne suffit plus. Ce n'est pas
 * une préférence esthétique, c'est la limite au-delà de laquelle le code cesse de fonctionner.
 *
 * Le fond blanc derrière le logo (`logoBackgroundColor`) est nécessaire : sans lui, les modules
 * noirs transparaissant derrière un logo à fond transparent brouillent la zone au lieu de la
 * laisser franchement absente — les lecteurs interpolent bien mieux un trou net qu'un motif douteux.
 *
 * ═══ CE QUI RESTE À PROUVER AU G4 ═══
 *
 * La lisibilité RÉELLE, avec de vrais lecteurs — en particulier l'enrôlement MFA, scanné par une
 * application d'authentification tierce (Google Authenticator, Aegis) qui n'a aucune raison d'être
 * indulgente. Un QR qui ne se scanne pas est une régression sur un module validé G5 : si le G4 le
 * montre, le logo saute sur CE site-là, pas ailleurs.
 */

interface Props {
  /** La charge utile encodée. Jamais construite ici : elle vient du serveur ou de l'appelant. */
  valeur: string;
  size?: number;
  /** Fond du code. Blanc franc pour un reçu imprimé, `surface` pour un écran. */
  backgroundColor?: string;
  /** Permet de retirer le logo si un lecteur tiers se montre trop strict (voir le G4 ci-dessus). */
  avecLogo?: boolean;
}

/** Part du côté occupée par le logo. Voir l'en-tête : au-delà, le QR cesse d'être lisible. */
const PART_LOGO = 0.2;

export function QrMasante({
  valeur,
  size = 220,
  backgroundColor = colors.surface,
  avecLogo = true,
}: Props) {
  const tailleLogo = Math.round(size * PART_LOGO);

  return (
    <QRCode
      value={valeur}
      size={size}
      color={colors.ink[900]}
      backgroundColor={backgroundColor}
      // Redondance maximale : c'est elle qui rend l'occultation centrale acceptable.
      ecl="H"
      {...(avecLogo
        ? {
            logo,
            logoSize: tailleLogo,
            // Un trou NET plutôt qu'un motif douteux — voir l'en-tête.
            logoBackgroundColor: '#FFFFFF',
            logoMargin: 2,
            logoBorderRadius: Math.round(tailleLogo / 6),
          }
        : {})}
    />
  );
}
