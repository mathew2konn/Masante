import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { Card } from '../../src/components/Card';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { listerMembres } from '../../src/api/membres';
import { messageErreur } from '../../src/utils/erreurs';
import { MAX_MEMBRES, type Membre } from '../../src/types/membre';
import { calculerAge } from '../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/**
 * Onglet « Carnet » — profil du compte + membres de la famille (F2.1).
 * La liste est rechargée à chaque retour sur l'onglet (création / édition / suppression).
 * Les sections du dossier (antécédents, vaccins…) arriveront à une étape ultérieure.
 */
export default function CarnetTab() {
  const { user, signOut } = useSession();
  const [membres, setMembres] = useState<Membre[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [deconnexion, setDeconnexion] = useState(false);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const liste = await listerMembres();
          if (actif) setMembres(liste);
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, []),
  );

  const seDeconnecter = async () => {
    setDeconnexion(true);
    await signOut(); // redirection automatique vers (auth).
  };

  const verifie = user?.niveau_compte === 'verifie';
  const plafondAtteint = membres.length >= MAX_MEMBRES;

  return (
    <Screen>
      <Text style={styles.titre}>Mon carnet</Text>

      <Card style={styles.compte}>
        <Text style={styles.nomCompte}>
          {user?.prenom} {user?.nom}
        </Text>
        <Text style={styles.tel}>{user?.telephone}</Text>
        <View style={[styles.statut, { backgroundColor: verifie ? colors.success.bg : colors.warning.bg }]}>
          <Text style={[styles.statutTxt, { color: verifie ? colors.success.text : colors.warning.text }]}>
            {verifie ? '✓ Compte vérifié' : '● Compte de base'}
          </Text>
        </View>
      </Card>

      <View style={styles.enteteSection}>
        <Text style={styles.section}>Membres de la famille</Text>
        <Text style={styles.compteur}>
          {membres.length}/{MAX_MEMBRES}
        </Text>
      </View>

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : membres.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="people-outline" size={32} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun membre pour l'instant.</Text>
          <Text style={styles.videSous}>Ajoutez vos proches pour gérer leur carnet de santé.</Text>
        </Card>
      ) : (
        <View style={styles.liste}>
          {membres.map((m) => (
            <MembreItem key={m.id} membre={m} />
          ))}
        </View>
      )}

      <View style={styles.ajout}>
        <PrimaryButton
          label="Ajouter un membre"
          onPress={() => router.push('/(app)/membres/nouveau')}
          disabled={plafondAtteint}
        />
        {plafondAtteint ? (
          <Text style={styles.plafond}>Limite de {MAX_MEMBRES} membres atteinte.</Text>
        ) : null}
      </View>

      <View style={styles.deconnexion}>
        <SecondaryButton label="Partages reçus" onPress={() => router.push('/(app)/partages')} />
        <View style={styles.sep} />
        <SecondaryButton
          label="Carte vitale d'urgence"
          onPress={() => router.push('/(app)/parametres/carte-vitale')}
        />
        <View style={styles.sep} />
        <SecondaryButton
          label="Mes alertes d'urgence"
          onPress={() => router.push('/(app)/parametres/alertes-sos')}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Sécurité" onPress={() => router.push('/(app)/parametres/securite')} />
        <View style={styles.sep} />
        <SecondaryButton
          label="Changer mon mot de passe"
          onPress={() => router.push('/(app)/parametres/mot-de-passe')}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Se déconnecter" onPress={seDeconnecter} disabled={deconnexion} />
      </View>
    </Screen>
  );
}

/** Carte cliquable d'un membre dans la liste. */
function MembreItem({ membre }: { membre: Membre }) {
  const age = calculerAge(membre.date_naissance);
  const initiales = `${membre.prenom?.[0] ?? ''}${membre.nom?.[0] ?? ''}`.toUpperCase();

  return (
    <Pressable
      onPress={() => router.push({ pathname: '/(app)/membres/[id]', params: { id: membre.id } })}
      accessibilityRole="button"
      accessibilityLabel={`${membre.prenom} ${membre.nom}`}
    >
      <Card style={styles.item}>
        <View style={styles.avatar}>
          <Text style={styles.avatarTxt}>{initiales}</Text>
        </View>
        <View style={styles.itemTexte}>
          <Text style={styles.itemNom}>
            {membre.prenom} {membre.nom}
          </Text>
          <Text style={styles.itemSous}>
            {age !== null ? `${age} ans` : '—'} · {membre.sexe === 'M' ? 'Masculin' : 'Féminin'}
            {membre.groupe_sanguin ? ` · ${membre.groupe_sanguin}` : ''}
          </Text>
        </View>
        <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[5] },
  compte: { marginBottom: spacing[6] },
  nomCompte: { ...typography.h2, color: colors.blue[900] },
  tel: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  statut: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1], marginTop: spacing[3] },
  statutTxt: { ...typography.caption, fontWeight: '700' },
  enteteSection: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing[3] },
  section: { ...typography.h2, color: colors.blue[900] },
  compteur: { ...typography.bodyStrong, color: colors.ink[500] },
  loader: { marginTop: spacing[5], marginBottom: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[5] },
  vide: { alignItems: 'center', marginBottom: spacing[5] },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[3] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  liste: { gap: spacing[3], marginBottom: spacing[5] },
  item: { flexDirection: 'row', alignItems: 'center' },
  avatar: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  avatarTxt: { ...typography.bodyStrong, color: colors.blue[700] },
  itemTexte: { flex: 1 },
  itemNom: { ...typography.bodyStrong, color: colors.blue[900] },
  itemSous: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  ajout: { marginBottom: spacing[6] },
  plafond: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[2] },
  deconnexion: {},
  sep: { height: spacing[3] },
});
