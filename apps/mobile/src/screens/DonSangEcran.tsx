import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { SecondaryButton } from '../components/SecondaryButton';
import {
  declarerDon,
  inscrireDonneur,
  obtenirBesoins,
  obtenirCentres,
  obtenirDonSang,
  retirerDonneur,
} from '../api/donSang';
import { listerMembres } from '../api/membres';
import { obtenirPosition } from '../utils/geoloc';
import { messageErreur } from '../utils/erreurs';
import { dateVersDateInput, formatDateFr } from '../utils/dates';
import type { BesoinSang, DonSangVue } from '../types/donSang';
import type { Membre } from '../types/membre';
import type { Structure } from '../types/structure';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * DonSangEcran (CdC FN6, Module 5.7) — don de sang.
 *
 * Quatre besoins du CdC, dans l'ordre où ils comptent pour l'utilisateur :
 *  1. les URGENCES qui le concernent (ciblage calculé serveur : le mobile ne compare aucun groupe
 *     sanguin lui-même — une erreur de compatibilité tue) ;
 *  2. ses MEMBRES DONNEURS : inscription (consentement explicite), délai de carence, retrait ;
 *  3. les GROUPES DEMANDÉS, publics ;
 *  4. les CENTRES DE COLLECTE proches — ce sont les structures de l'annuaire (Module 3) portant un
 *     service `don_sang` : on réutilise la recherche géolocalisée existante, on ne refait pas de carte.
 */
export function DonSangEcran() {
  const [vue, setVue] = useState<DonSangVue | null>(null);
  const [besoins, setBesoins] = useState<BesoinSang[]>([]);
  const [membres, setMembres] = useState<Membre[]>([]);
  const [centres, setCentres] = useState<Structure[] | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [centresEnCours, setCentresEnCours] = useState(false);
  const [action, setAction] = useState<number | null>(null);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      const [donSang, publics, mesMembres] = await Promise.all([
        obtenirDonSang(),
        obtenirBesoins(),
        listerMembres(),
      ]);
      setVue(donSang);
      setBesoins(publics);
      setMembres(mesMembres);
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  /** Membres déjà donneurs, par identifiant — pour distinguer inscription et gestion. */
  const donneurParMembre = new Map((vue?.donneurs ?? []).map((d) => [d.membre_id, d]));

  const chargerCentres = async () => {
    // P6.8a — le code de spécialité vient du serveur (`regles.specialite_centre`), plus d'une
    // constante du client. `vue` est déjà chargée quand ce bouton devient actionnable.
    const specialite = vue?.regles.specialite_centre;
    if (!specialite) return;

    setCentresEnCours(true);
    const position = await obtenirPosition();
    try {
      // Sans autorisation de position, on liste quand même les centres (sans tri par distance) :
      // refuser la géoloc ne doit pas priver d'une information de santé publique.
      setCentres(await obtenirCentres(specialite, position.ok ? position.coords : undefined));
    } catch (e) {
      Alert.alert('Centres indisponibles', messageErreur(e));
    } finally {
      setCentresEnCours(false);
    }
  };

  const inscrire = async (membre: Membre) => {
    setAction(membre.id);
    try {
      await inscrireDonneur(membre.id);
      await charger();
      Alert.alert(
        'Inscription enregistrée',
        `${membre.prenom} est inscrit(e) comme donneur volontaire. Vous serez prévenu(e) en cas d'urgence `
          + 'compatible. Vous pouvez retirer cette inscription à tout moment.',
      );
    } catch (e) {
      Alert.alert('Inscription impossible', messageErreur(e));
    } finally {
      setAction(null);
    }
  };

  const retirer = (membre: Membre) => {
    Alert.alert(
      'Retirer l\'inscription ?',
      `${membre.prenom} ne sera plus alerté(e) en cas d'urgence transfusionnelle. Vous pourrez vous `
        + 'réinscrire à tout moment.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Retirer',
          style: 'destructive',
          onPress: async () => {
            try {
              await retirerDonneur(membre.id);
              await charger();
            } catch (e) {
              Alert.alert('Retrait impossible', messageErreur(e));
            }
          },
        },
      ],
    );
  };

  const noterDon = (membre: Membre) => {
    Alert.alert(
      'Vous avez donné ?',
      `Enregistrer un don pour ${membre.prenom} aujourd'hui. Vous ne serez plus sollicité(e) pendant `
        + `${vue?.regles.delai_jours ?? 90} jours, le temps de récupérer.`,
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: "J'ai donné aujourd'hui",
          onPress: async () => {
            try {
              await declarerDon(membre.id, dateVersDateInput(new Date()));
              await charger();
            } catch (e) {
              Alert.alert('Enregistrement impossible', messageErreur(e));
            }
          },
        },
      ],
    );
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Don de sang" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !vue) {
    return (
      <Screen>
        <ScreenHeader title="Don de sang" onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Information indisponible.'}</Text>
      </Screen>
    );
  }

  return (
    <Screen>
      <ScreenHeader title="Don de sang" onBack={() => router.back()} />

      {/* 1. Les urgences qui NOUS concernent. Le serveur a déjà fait le tri : si c'est affiché,
             c'est qu'un membre donneur du foyer peut réellement fournir cette poche. */}
      {vue.alertes.map(({ besoin, mes_groupes_utiles }) => (
        <Card key={besoin.id} style={[styles.bloc, styles.urgence]}>
          <View style={styles.urgenceEntete}>
            <Ionicons name="water" size={22} color={colors.danger.text} />
            <Text style={styles.urgenceTitre}>Urgence — sang {besoin.groupe_sanguin} recherché</Text>
          </View>
          <Text style={styles.urgenceTxt}>
            {besoin.structure?.nom ?? 'Un établissement'}
            {besoin.structure?.commune ? ` · ${besoin.structure.commune}` : ''} a besoin de sang
            {besoin.groupe_sanguin} et <Text style={styles.gras}>votre don peut convenir</Text> (
            {mes_groupes_utiles.join(', ')}).
          </Text>
          {besoin.message ? <Text style={styles.urgenceMsg}>{besoin.message}</Text> : null}
          <SecondaryButton label="Voir les centres de collecte" onPress={() => void chargerCentres()} />
        </Card>
      ))}

      {/* 2. Mes membres donneurs. L'inscription est un consentement, membre par membre. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Donneurs de mon carnet</Text>
        <Text style={styles.blocAide}>
          Inscrivez un membre majeur ({vue.regles.age_min}–{vue.regles.age_max} ans) dont le groupe sanguin
          est renseigné. Il sera prévenu quand un hôpital aura besoin d'un sang compatible avec le sien —
          jamais désigné : donner reste une décision.
        </Text>

        {membres.length === 0 ? (
          <Text style={styles.videTxt}>Aucun membre dans votre carnet.</Text>
        ) : (
          membres.map((membre, i) => {
            const donneur = donneurParMembre.get(membre.id);

            return (
              <View key={membre.id} style={[styles.membre, i > 0 && styles.bordure]}>
                <View style={styles.membreInfos}>
                  <Text style={styles.membreNom}>
                    {membre.prenom} {membre.nom}
                  </Text>
                  <Text style={styles.membreMeta}>
                    {membre.groupe_sanguin ?? 'Groupe sanguin non renseigné'}
                    {donneur
                      ? donneur.peut_donner
                        ? ' · peut donner'
                        : ` · repos ${donneur.jours_avant_don} j (dernier don le ${formatDateFr(donneur.dernier_don_at)})`
                      : ''}
                  </Text>
                </View>

                {donneur ? (
                  <View style={styles.membreActions}>
                    <Pressable
                      onPress={() => noterDon(membre)}
                      accessibilityRole="button"
                      accessibilityLabel={`Enregistrer un don pour ${membre.prenom}`}
                      style={styles.iconeBtn}
                    >
                      <Ionicons name="checkmark-circle-outline" size={22} color={colors.success.text} />
                    </Pressable>
                    <Pressable
                      onPress={() => retirer(membre)}
                      accessibilityRole="button"
                      accessibilityLabel={`Retirer l'inscription de ${membre.prenom}`}
                      style={styles.iconeBtn}
                    >
                      <Ionicons name="close-circle-outline" size={22} color={colors.ink[500]} />
                    </Pressable>
                  </View>
                ) : (
                  <Pressable
                    onPress={() => void inscrire(membre)}
                    disabled={action === membre.id}
                    accessibilityRole="button"
                    accessibilityLabel={`Inscrire ${membre.prenom} comme donneur`}
                    style={styles.inscrireBtn}
                  >
                    <Text style={styles.inscrireTxt}>Inscrire</Text>
                  </Pressable>
                )}
              </View>
            );
          })
        )}
      </Card>

      {/* 3. Les groupes demandés (public) — l'appel au don, visible de tous. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Groupes les plus demandés</Text>
        {besoins.length === 0 ? (
          <Text style={styles.videTxt}>Aucun besoin signalé actuellement.</Text>
        ) : (
          besoins.map((besoin, i) => (
            <View key={besoin.id} style={[styles.besoin, i > 0 && styles.bordure]}>
              <View
                style={[
                  styles.groupe,
                  { backgroundColor: besoin.niveau === 'urgent' ? colors.danger.bg : colors.blue[100] },
                ]}
              >
                <Text
                  style={[
                    styles.groupeTxt,
                    { color: besoin.niveau === 'urgent' ? colors.danger.text : colors.blue[700] },
                  ]}
                >
                  {besoin.groupe_sanguin}
                </Text>
              </View>
              <View style={styles.besoinInfos}>
                <Text style={styles.besoinNom}>{besoin.structure?.nom ?? 'Établissement'}</Text>
                <Text style={styles.besoinMeta}>
                  {besoin.niveau === 'urgent' ? 'Urgence' : 'Besoin courant'}
                  {besoin.structure?.commune ? ` · ${besoin.structure.commune}` : ''}
                </Text>
              </View>
            </View>
          ))
        )}
      </Card>

      {/* 4. Centres de collecte : structures de l'annuaire portant un service « don_sang ». */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Centres de collecte</Text>
        {centres === null ? (
          <SecondaryButton
            label={centresEnCours ? 'Recherche en cours…' : 'Trouver les centres proches'}
            onPress={() => void chargerCentres()}
            disabled={centresEnCours}
          />
        ) : centres.length === 0 ? (
          <Text style={styles.videTxt}>Aucun centre de collecte référencé pour l'instant.</Text>
        ) : (
          centres.map((centre, i) => (
            <Pressable
              key={centre.id}
              onPress={() =>
                router.push({ pathname: '/(app)/structures/[id]', params: { id: String(centre.id) } })
              }
              accessibilityRole="button"
              accessibilityLabel={centre.nom}
              style={[styles.centre, i > 0 && styles.bordure]}
            >
              <View style={styles.centreInfos}>
                <Text style={styles.centreNom}>{centre.nom}</Text>
                <Text style={styles.centreMeta}>
                  {centre.commune}
                  {centre.distance_km != null ? ` · ${centre.distance_km} km` : ''}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
            </Pressable>
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  blocAide: { ...typography.body, color: colors.ink[700], marginBottom: spacing[4] },
  videTxt: { ...typography.body, color: colors.ink[500] },
  bordure: { borderTopWidth: 1, borderTopColor: colors.line },
  gras: { fontWeight: '700' },

  // Urgence ciblée
  urgence: { borderWidth: 1, borderColor: colors.danger.solid, backgroundColor: colors.danger.bg },
  urgenceEntete: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[2] },
  urgenceTitre: { ...typography.bodyStrong, color: colors.danger.text, flex: 1 },
  urgenceTxt: { ...typography.body, color: colors.ink[900], marginBottom: spacing[2] },
  urgenceMsg: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[3] },

  // Membres donneurs
  membre: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  membreInfos: { flex: 1 },
  membreNom: { ...typography.bodyStrong, color: colors.blue[900] },
  membreMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  membreActions: { flexDirection: 'row', gap: spacing[1] },
  iconeBtn: { padding: spacing[2] },
  inscrireBtn: {
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[4],
    borderRadius: radius.pill,
    backgroundColor: colors.blue[600],
  },
  inscrireTxt: { ...typography.caption, color: colors.surface, fontWeight: '700' },

  // Groupes demandés
  besoin: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  groupe: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  groupeTxt: { ...typography.bodyStrong },
  besoinInfos: { flex: 1 },
  besoinNom: { ...typography.bodyStrong, color: colors.blue[900] },
  besoinMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },

  // Centres
  centre: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  centreInfos: { flex: 1 },
  centreNom: { ...typography.bodyStrong, color: colors.blue[900] },
  centreMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
});
