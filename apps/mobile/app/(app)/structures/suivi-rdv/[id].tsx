import React, { useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { Card } from '../../../../src/components/Card';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { SuiviPresenceRdv, type EvenementPresence } from '../../../../src/services/presenceRdv';
import { colors, spacing, typography } from '../../../../src/theme/theme';

/**
 * Suivi en direct d'un rendez-vous (B1-c / D9, CDC_11 §9).
 *
 * Tant que le médecin de CE rendez-vous a un accès partagé ouvert (30 min), cet écran affiche
 * l'état en direct — jamais de contenu médical, jamais le détail d'une écriture, juste « le
 * médecin consulte votre dossier » puis « refermé ». Le canal est PRIVÉ et n'autorise que le
 * titulaire du membre concerné ({@see \App\Support\AutorisationCanalPresenceRdv}) — le serveur
 * refait foi à chaque abonnement, cet écran n'affiche que ce qui est probable.
 */
export default function SuiviRdvEcran() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const rdvId = Number(id);
  const [etat, setEtat] = useState<EvenementPresence>({ etat: 'connexion' });

  useEffect(() => {
    const suivi = new SuiviPresenceRdv(rdvId, setEtat);
    suivi.connecter();
    return () => suivi.fermer();
  }, [rdvId]);

  return (
    <Screen>
      <ScreenHeader title="Suivi en direct" onBack={() => router.back()} />

      <Card style={styles.card}>
        <Icone etat={etat.etat} />
        <Text style={styles.libelle}>{libelleEtat(etat)}</Text>
        {etat.etat === 'indisponible' ? (
          <Text style={styles.aide}>
            Le suivi en direct n'est pas disponible pour le moment. Votre rendez-vous n'en est pas
            affecté.
          </Text>
        ) : null}
      </Card>
    </Screen>
  );
}

function Icone({ etat }: { etat: EvenementPresence['etat'] }) {
  const props = {
    connexion: { name: 'radio-outline' as const, color: colors.ink[500] },
    attente: { name: 'time-outline' as const, color: colors.ink[500] },
    ouvert: { name: 'pulse-outline' as const, color: colors.success.solid },
    ecriture: { name: 'create-outline' as const, color: colors.success.solid },
    ferme: { name: 'checkmark-circle-outline' as const, color: colors.blue[600] },
    indisponible: { name: 'cloud-offline-outline' as const, color: colors.ink[500] },
  }[etat];

  return <Ionicons name={props.name} size={40} color={props.color} />;
}

/** Aucun contenu médical n'entre jamais ici — le libellé le plus précis reste « votre dossier ». */
function libelleEtat(e: EvenementPresence): string {
  switch (e.etat) {
    case 'connexion':
      return 'Connexion…';
    case 'attente':
      return 'En attente de votre médecin.';
    case 'ouvert':
      return e.medecin ? `${e.medecin} consulte votre dossier.` : 'Votre médecin consulte votre dossier.';
    case 'ecriture':
      return 'Votre dossier est en cours de mise à jour.';
    case 'ferme':
      return 'Consultation terminée.';
    case 'indisponible':
      return 'Suivi en direct indisponible.';
  }
}

const styles = StyleSheet.create({
  card: { alignItems: 'center', gap: spacing[3], paddingVertical: spacing[8] },
  libelle: { ...typography.bodyStrong, color: colors.ink[900], textAlign: 'center' },
  aide: { ...typography.caption, color: colors.ink[500], textAlign: 'center', paddingHorizontal: spacing[4] },
});
