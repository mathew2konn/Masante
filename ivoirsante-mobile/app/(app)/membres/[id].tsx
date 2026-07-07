import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../src/components/Screen';
import { ScreenHeader } from '../../../src/components/ScreenHeader';
import { Card } from '../../../src/components/Card';
import { PrimaryButton } from '../../../src/components/PrimaryButton';
import { SecondaryButton } from '../../../src/components/SecondaryButton';
import { obtenirMembre, supprimerMembre } from '../../../src/api/membres';
import { supprimerPhoto, telechargerPhoto, televerserPhoto } from '../../../src/api/photo';
import { choisirPhotoProfilGalerie, PermissionRefusee, prendrePhotoProfil } from '../../../src/documents/selection';
import { listerSection } from '../../../src/api/carnet';
import { messageErreur } from '../../../src/utils/erreurs';
import { LIBELLE_CMU_STATUT, type Membre } from '../../../src/types/membre';
import type { CarnetItem } from '../../../src/types/carnet';
import { SECTIONS } from '../../../src/carnet/registre';
import { calculerAge, formatDateFr } from '../../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../../src/theme/theme';

/** Détail d'un membre : informations + actions Modifier / Supprimer (F2.1). */
export default function DetailMembreScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const membreId = Number(id);

  const [membre, setMembre] = useState<Membre | null>(null);
  const [contacts, setContacts] = useState<CarnetItem[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [suppression, setSuppression] = useState(false);

  // Photo de profil : URI LOCAL (téléchargée avec le token), occupation et « version » (anti-cache).
  const [photoUri, setPhotoUri] = useState<string | null>(null);
  const [photoOccupee, setPhotoOccupee] = useState(false);
  const [photoVersion, setPhotoVersion] = useState(0);

  // Télécharge la photo dès que le membre en a une (ou après remplacement) : <Image> n'affiche
  // qu'un fichier LOCAL, l'en-tête Bearer n'étant pas fiable sur Android (cf. api/photo.ts).
  useEffect(() => {
    let actif = true;
    if (membre?.a_photo) {
      telechargerPhoto(membreId, photoVersion)
        .then((uri) => actif && setPhotoUri(uri))
        .catch(() => actif && setPhotoUri(null));
    } else {
      setPhotoUri(null);
    }
    return () => {
      actif = false;
    };
  }, [membre?.a_photo, membreId, photoVersion]);

  // Rechargé à chaque retour sur l'écran (ex. après édition).
  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const m = await obtenirMembre(membreId);
          if (actif) setMembre(m);
          // Aperçu des contacts d'urgence : best-effort, n'empêche pas l'affichage de la fiche.
          try {
            const cs = await listerSection(membreId, 'contacts-urgence');
            if (actif) setContacts(cs);
          } catch {
            /* aperçu indisponible : on n'affiche simplement rien */
          }
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, [membreId]),
  );

  const confirmerSuppression = () => {
    if (!membre) return;
    Alert.alert(
      'Supprimer ce membre ?',
      `Le dossier de ${membre.prenom} ${membre.nom} sera définitivement supprimé.`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Supprimer', style: 'destructive', onPress: supprimer },
      ],
    );
  };

  const supprimer = async () => {
    setSuppression(true);
    try {
      await supprimerMembre(membreId);
      router.back(); // retour à la liste (rechargée au focus).
    } catch (e) {
      setSuppression(false);
      Alert.alert('Suppression impossible', messageErreur(e));
    }
  };

  // --- Photo de profil ---
  const appliquerPhoto = async (choisir: () => ReturnType<typeof prendrePhotoProfil>) => {
    try {
      const fichier = await choisir();
      if (!fichier) return; // annulé
      setPhotoOccupee(true);
      const maj = await televerserPhoto(membreId, fichier);
      setMembre(maj);
      setPhotoVersion((v) => v + 1); // force <Image> à recharger la nouvelle photo
    } catch (e) {
      Alert.alert('Photo de profil', e instanceof PermissionRefusee ? e.message : messageErreur(e));
    } finally {
      setPhotoOccupee(false);
    }
  };

  const supprimerPhotoMembre = async () => {
    try {
      setPhotoOccupee(true);
      const maj = await supprimerPhoto(membreId);
      setMembre(maj);
      setPhotoVersion((v) => v + 1);
    } catch (e) {
      Alert.alert('Photo de profil', messageErreur(e));
    } finally {
      setPhotoOccupee(false);
    }
  };

  const gererPhoto = () => {
    if (photoOccupee || !membre) return;
    const boutons: { text: string; style?: 'cancel' | 'destructive'; onPress?: () => void }[] = [
      { text: 'Prendre une photo', onPress: () => appliquerPhoto(prendrePhotoProfil) },
      { text: 'Choisir dans la galerie', onPress: () => appliquerPhoto(choisirPhotoProfilGalerie) },
    ];
    if (membre.a_photo) {
      boutons.push({ text: 'Supprimer la photo', style: 'destructive', onPress: supprimerPhotoMembre });
    }
    boutons.push({ text: 'Annuler', style: 'cancel' });
    Alert.alert('Photo de profil', undefined, boutons);
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Membre" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !membre) {
    return (
      <Screen>
        <ScreenHeader title="Membre" onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Membre introuvable.'}</Text>
      </Screen>
    );
  }

  const age = calculerAge(membre.date_naissance);
  const initiales = `${membre.prenom?.[0] ?? ''}${membre.nom?.[0] ?? ''}`.toUpperCase();

  return (
    <Screen>
      <ScreenHeader title="Fiche du membre" onBack={() => router.back()} />

      <Card style={styles.entete}>
        <Pressable
          onPress={gererPhoto}
          accessibilityRole="button"
          accessibilityLabel="Modifier la photo de profil"
          style={styles.avatar}
        >
          {membre.a_photo && photoUri ? (
            <Image source={{ uri: photoUri }} style={styles.avatarImg} />
          ) : (
            <Text style={styles.avatarTxt}>{initiales}</Text>
          )}
          <View style={styles.avatarBadge}>
            {photoOccupee ? (
              <ActivityIndicator size="small" color={colors.surface} />
            ) : (
              <Ionicons name="camera" size={13} color={colors.surface} />
            )}
          </View>
        </Pressable>
        <Text style={styles.nom}>
          {membre.prenom} {membre.nom}
        </Text>
        <Text style={styles.sous}>
          {age !== null ? `${age} ans` : '—'} · {membre.sexe === 'M' ? 'Masculin' : 'Féminin'}
        </Text>
        {membre.groupe_sanguin ? (
          <View style={styles.badge}>
            <Text style={styles.badgeTxt}>Groupe {membre.groupe_sanguin}</Text>
          </View>
        ) : null}
      </Card>

      <Card style={styles.bloc}>
        <Ligne libelle="Date de naissance" valeur={formatDateFr(membre.date_naissance)} />
        <Ligne libelle="Groupe sanguin" valeur={membre.groupe_sanguin ?? 'Non renseigné'} />
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>CMU (assurance santé)</Text>
        <Ligne libelle="Numéro" valeur={membre.cmu_numero_masque ?? 'Non renseigné'} />
        <Ligne
          libelle="Statut"
          valeur={membre.cmu_statut ? LIBELLE_CMU_STATUT[membre.cmu_statut] : 'Non renseigné'}
        />
        <Ligne libelle="Validité" valeur={formatDateFr(membre.cmu_validite)} />

        {/* Carte CMU numérique (F2.3) : vue présentable + code de présentation. */}
        <Pressable
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/carte-cmu/[id]',
              params: { id: membreId, nom: `${membre.prenom} ${membre.nom}` },
            })
          }
          accessibilityRole="button"
          accessibilityLabel="Carte CMU numérique"
          style={[styles.sectionRow, styles.sectionRowBordure]}
        >
          <View style={styles.sectionPastille}>
            <Ionicons name="card-outline" size={18} color={colors.blue[600]} />
          </View>
          <Text style={styles.sectionTxt}>Carte CMU numérique</Text>
          <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
        </Pressable>
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Carnet de santé</Text>
        {SECTIONS.map((s, i) => (
          <Pressable
            key={s.slug}
            onPress={() =>
              router.push({
                pathname: '/(app)/membres/carnet/[id]/[section]',
                params: { id: membreId, section: s.slug, nom: `${membre.prenom} ${membre.nom}` },
              })
            }
            accessibilityRole="button"
            accessibilityLabel={s.titre}
            style={[styles.sectionRow, i > 0 && styles.sectionRowBordure]}
          >
            <View style={styles.sectionPastille}>
              <Ionicons name={s.icone as keyof typeof Ionicons.glyphMap} size={18} color={colors.blue[600]} />
            </View>
            <Text style={styles.sectionTxt}>{s.titre}</Text>
            <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
          </Pressable>
        ))}

        {/* Contacts d'urgence (F2.11) : écran dédié à 2 blocs, hors moteur générique. */}
        <Pressable
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/contacts-urgence/[id]',
              params: { id: membreId, nom: `${membre.prenom} ${membre.nom}` },
            })
          }
          accessibilityRole="button"
          accessibilityLabel="Contacts d'urgence"
          style={[styles.sectionRow, styles.sectionRowBordure]}
        >
          <View style={styles.sectionPastille}>
            <Ionicons name="call-outline" size={18} color={colors.blue[600]} />
          </View>
          <Text style={styles.sectionTxt}>Contacts d'urgence</Text>
          <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
        </Pressable>

        {/* Documents médicaux (F2.10) : écran dédié (import sécurisé + liste par catégorie). */}
        <Pressable
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/documents/[id]',
              params: { id: membreId, nom: `${membre.prenom} ${membre.nom}` },
            })
          }
          accessibilityRole="button"
          accessibilityLabel="Documents médicaux"
          style={[styles.sectionRow, styles.sectionRowBordure]}
        >
          <View style={styles.sectionPastille}>
            <Ionicons name="folder-outline" size={18} color={colors.blue[600]} />
          </View>
          <Text style={styles.sectionTxt}>Documents médicaux</Text>
          <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
        </Pressable>

        {/* Aperçu : les 1-2 contacts saisis (principal en premier), rechargé au focus. */}
        {contacts.length > 0 ? (
          <View style={styles.contactsResume}>
            {[...contacts]
              .sort((a, b) => (b.est_principal === true ? 1 : 0) - (a.est_principal === true ? 1 : 0))
              .map((c) => (
                <View key={String(c.id)} style={styles.contactLigne}>
                  <View style={styles.contactInfos}>
                    <Text style={styles.contactNom} numberOfLines={1}>
                      {String(c.nom ?? 'Contact')}
                    </Text>
                    <Text style={styles.contactTel}>{telLocal(c.telephone)}</Text>
                  </View>
                  <View
                    style={[styles.contactBadge, { backgroundColor: c.est_principal === true ? colors.success.bg : colors.blue[100] }]}
                  >
                    <Text style={[styles.contactBadgeTxt, { color: c.est_principal === true ? colors.success.text : colors.blue[700] }]}>
                      {c.est_principal === true ? 'Principal' : 'Secondaire'}
                    </Text>
                  </View>
                </View>
              ))}
          </View>
        ) : null}
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Partage sécurisé</Text>
        <Text style={styles.blocAide}>
          Donnez un accès temporaire au dossier via un QR à usage unique, et consultez qui y a accédé.
        </Text>
        <PrimaryButton
          label="Générer un QR de partage"
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/qr/[id]',
              params: { id: membreId, prenom: membre.prenom, nom: membre.nom },
            })
          }
        />
        <View style={styles.sep} />
        <SecondaryButton
          label="Journal d'accès"
          onPress={() =>
            router.push({
              pathname: '/(app)/membres/acces/[id]',
              params: { id: membreId, prenom: membre.prenom, nom: membre.nom },
            })
          }
        />
      </Card>

      <View style={styles.actions}>
        <PrimaryButton
          label="Modifier"
          onPress={() => router.push({ pathname: '/(app)/membres/modifier/[id]', params: { id: membreId } })}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Supprimer" onPress={confirmerSuppression} disabled={suppression} />
      </View>
    </Screen>
  );
}

/** Ligne « libellé / valeur » réutilisée dans la fiche. */
function Ligne({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <View style={styles.ligne}>
      <Text style={styles.ligneLib}>{libelle}</Text>
      <Text style={styles.ligneVal}>{valeur}</Text>
    </View>
  );
}

/** Masque l'indicatif +225 pour l'affichage (stocké en base, jamais montré). */
const telLocal = (t: unknown) => String(t ?? '').replace(/^\+225/, '');

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  entete: { alignItems: 'center', marginBottom: spacing[5] },
  avatar: {
    width: 72,
    height: 72,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing[3],
  },
  avatarImg: { width: 72, height: 72, borderRadius: radius.pill },
  avatarBadge: {
    position: 'absolute',
    right: 0,
    bottom: 0,
    width: 24,
    height: 24,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[600],
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.surface,
  },
  avatarTxt: { ...typography.h1, color: colors.blue[700] },
  nom: { ...typography.h2, color: colors.blue[900] },
  sous: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  badge: {
    marginTop: spacing[3],
    borderRadius: radius.pill,
    backgroundColor: colors.danger.bg,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1],
  },
  badgeTxt: { ...typography.caption, fontWeight: '700', color: colors.danger.text },
  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  blocAide: { ...typography.body, color: colors.ink[700], marginTop: -spacing[1], marginBottom: spacing[4] },
  sectionRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  sectionRowBordure: { borderTopWidth: 1, borderTopColor: colors.line },
  sectionPastille: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  sectionTxt: { ...typography.bodyStrong, color: colors.blue[900], flex: 1 },
  contactsResume: { marginTop: spacing[2], paddingLeft: spacing[8] },
  contactLigne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[2] },
  contactInfos: { flex: 1 },
  contactNom: { ...typography.bodyStrong, color: colors.ink[900] },
  contactTel: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  contactBadge: { borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1] },
  contactBadgeTxt: { ...typography.caption, fontWeight: '700' },
  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: spacing[2] },
  ligneLib: { ...typography.body, color: colors.ink[500], flexShrink: 0, marginRight: spacing[4] },
  ligneVal: { ...typography.bodyStrong, color: colors.ink[900], flex: 1, textAlign: 'right' },
  actions: { marginTop: spacing[1] },
  sep: { height: spacing[3] },
});
