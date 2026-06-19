import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Screen } from '../../src/components/Screen';
import { Card } from '../../src/components/Card';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/**
 * Onglet « Carnet » — pour l'instant : profil du compte + déconnexion. La gestion des
 * membres et des sections du dossier arrive à l'étape 2B.2.
 */
export default function CarnetTab() {
  const { user, signOut } = useSession();
  const [chargement, setChargement] = useState(false);

  const seDeconnecter = async () => {
    setChargement(true);
    await signOut(); // redirection automatique vers (auth).
  };

  const verifie = user?.niveau_compte === 'verifie';

  return (
    <Screen>
      <Text style={styles.titre}>Mon carnet</Text>

      <Card style={styles.carte}>
        <Text style={styles.nom}>
          {user?.prenom} {user?.nom}
        </Text>
        <Text style={styles.tel}>{user?.telephone}</Text>
        <View style={[styles.badge, { backgroundColor: verifie ? colors.success.bg : colors.warning.bg }]}>
          <Text style={[styles.badgeTxt, { color: verifie ? colors.success.text : colors.warning.text }]}>
            {verifie ? '✓ Compte vérifié' : '● Compte de base'}
          </Text>
        </View>
      </Card>

      <Text style={styles.aVenir}>La gestion des membres de la famille et des dossiers arrive bientôt.</Text>

      <View style={styles.actions}>
        <SecondaryButton label="Se déconnecter" onPress={seDeconnecter} disabled={chargement} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[5] },
  carte: { marginBottom: spacing[5] },
  nom: { ...typography.h2, color: colors.blue[900] },
  tel: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  badge: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1], marginTop: spacing[3] },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
  aVenir: { ...typography.body, color: colors.ink[500], marginBottom: spacing[6] },
  actions: {},
});
