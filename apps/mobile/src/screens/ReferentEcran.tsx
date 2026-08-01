import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { designerReferent, obtenirReferent, rechercherMedecins, revoquerReferent } from '../api/referent';
import { messageErreur } from '../utils/erreurs';
import { formatDateFr } from '../utils/dates';
import type { Medecin, ReferentVue } from '../types/referent';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * ReferentEcran (Module 5.6, voie 2 — doc Sécurité §4.4) — le médecin référent du membre.
 *
 * C'est l'écran le plus engageant du carnet : désigner un référent lui ouvre le dossier EN CONTINU,
 * sans QR. L'écran le dit franchement plutôt que de le cacher derrière un joli bouton — et il
 * rappelle les deux contreparties : chaque consultation est journalisée (« Journal d'accès »), et la
 * révocation est immédiate.
 *
 * Le choix se fait dans l'ANNUAIRE PUBLIC des praticiens (le même que celui des rendez-vous) : le
 * patient cherche par nom le médecin qu'il connaît déjà. Un médecin dont l'établissement n'a pas
 * relié le compte est signalé (« ne consulte pas encore en ligne ») : il peut être désigné, mais il
 * ne verra rien — mieux vaut le savoir avant.
 */
export function ReferentEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [vue, setVue] = useState<ReferentVue | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const [recherche, setRecherche] = useState('');
  const [resultats, setResultats] = useState<Medecin[] | null>(null);
  const [enRecherche, setEnRecherche] = useState(false);
  const [envoi, setEnvoi] = useState(false);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      setVue(await obtenirReferent(membreId));
    } catch (e) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [membreId]);

  useFocusEffect(
    useCallback(() => {
      void charger();
    }, [charger]),
  );

  const chercher = async () => {
    const q = recherche.trim();
    if (q.length < 2) {
      Alert.alert('Recherche', 'Saisissez au moins deux lettres du nom du médecin.');
      return;
    }
    setEnRecherche(true);
    try {
      setResultats(await rechercherMedecins({ q }));
    } catch (e) {
      Alert.alert('Recherche impossible', messageErreur(e));
    } finally {
      setEnRecherche(false);
    }
  };

  const faireDesignation = async (medecin: Medecin) => {
    setEnvoi(true);
    try {
      await designerReferent(membreId, medecin.id);
      setResultats(null);
      setRecherche('');
      await charger();
    } catch (e) {
      Alert.alert('Désignation impossible', messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  const confirmerDesignation = (medecin: Medecin) => {
    const remplacement = vue?.referent
      ? `\n\n${vue.referent.medecin?.nom_complet ?? 'Le référent actuel'} perdra cet accès.`
      : '';
    const avertissement = medecin.consulte_en_ligne
      ? ''
      : "\n\nCe praticien n'a pas encore de compte relié au portail : tant que son établissement ne l'aura pas fait, il ne pourra pas consulter le dossier.";

    Alert.alert(
      `Désigner ${medecin.nom_complet} ?`,
      `Ce médecin pourra consulter le dossier de ${nomMembre ?? 'ce membre'} à tout moment, sans QR Code. `
        + 'Chaque consultation sera enregistrée dans le journal d\'accès, et vous pourrez révoquer '
        + `cet accès quand vous le souhaitez.${remplacement}${avertissement}`,
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Désigner', onPress: () => void faireDesignation(medecin) },
      ],
    );
  };

  const confirmerRevocation = () => {
    const referent = vue?.referent;
    if (!referent) return;

    Alert.alert(
      'Révoquer l\'accès ?',
      `${referent.medecin?.nom_complet ?? 'Ce médecin'} ne pourra plus consulter le dossier. `
        + 'La révocation prend effet immédiatement ; les accès passés restent au journal.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Révoquer',
          style: 'destructive',
          onPress: async () => {
            try {
              await revoquerReferent(membreId, referent.id);
              await charger();
            } catch (e) {
              Alert.alert('Révocation impossible', messageErreur(e));
            }
          },
        },
      ],
    );
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Médecin référent" subtitle={nomMembre} onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !vue) {
    return (
      <Screen>
        <ScreenHeader title="Médecin référent" subtitle={nomMembre} onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Information indisponible.'}</Text>
      </Screen>
    );
  }

  const referent = vue.referent;

  return (
    <Screen>
      <ScreenHeader title="Médecin référent" subtitle={nomMembre} onBack={() => router.back()} />

      {referent ? (
        <Card style={[styles.bloc, styles.actif]}>
          <View style={styles.actifEntete}>
            <View style={styles.pastille}>
              <Ionicons name="medkit-outline" size={20} color={colors.blue[700]} />
            </View>
            <View style={styles.actifInfos}>
              <Text style={styles.actifNom}>{referent.medecin?.nom_complet ?? 'Médecin'}</Text>
              <Text style={styles.actifMeta}>
                {[referent.medecin?.specialite, referent.medecin?.structure?.nom].filter(Boolean).join(' · ')}
              </Text>
            </View>
          </View>

          <Text style={styles.actifDepuis}>Désigné le {formatDateFr(referent.designe_at)}</Text>

          {referent.medecin && !referent.medecin.consulte_en_ligne ? (
            <View style={styles.avertissement}>
              <Ionicons name="information-circle-outline" size={16} color={colors.warning.text} />
              <Text style={styles.avertissementTxt}>
                Ce praticien n'a pas encore de compte relié au portail : il ne peut pas encore consulter le
                dossier en ligne.
              </Text>
            </View>
          ) : (
            <Text style={styles.actifAide}>
              Il peut consulter le dossier à tout moment, sans QR Code. Chaque accès figure au journal
              d'accès du membre.
            </Text>
          )}

          <View style={styles.sep} />
          <SecondaryButton label="Révoquer l'accès" onPress={confirmerRevocation} />
        </Card>
      ) : (
        <Card style={styles.bloc}>
          <Text style={styles.blocTitre}>Aucun médecin référent</Text>
          <Text style={styles.blocAide}>
            Un médecin référent peut consulter le dossier de ce membre à tout moment, sans QR Code : utile
            pour un suivi régulier (diabète, hypertension, grossesse). Chaque consultation est enregistrée au
            journal d'accès, et vous pouvez révoquer cet accès à tout instant.
          </Text>
        </Card>
      )}

      {/* Recherche dans l'annuaire public des praticiens. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>{referent ? 'Changer de médecin' : 'Désigner un médecin'}</Text>
        <Text style={styles.blocAide}>
          Cherchez par nom le médecin qui suit ce membre.
          {referent ? ' Désigner un nouveau médecin révoque automatiquement le précédent.' : ''}
        </Text>

        <TextField
          label="Nom du médecin"
          value={recherche}
          onChangeText={setRecherche}
          autoCapitalize="words"
          maxLength={120}
        />
        <PrimaryButton label="Rechercher" onPress={chercher} loading={enRecherche} disabled={envoi} />

        {resultats !== null ? (
          resultats.length === 0 ? (
            <Text style={styles.videTxt}>
              Aucun médecin ne correspond à cette recherche dans l'annuaire.
            </Text>
          ) : (
            <View style={styles.resultats}>
              {resultats.map((m, i) => (
                <Pressable
                  key={m.id}
                  onPress={() => confirmerDesignation(m)}
                  accessibilityRole="button"
                  accessibilityLabel={`Désigner ${m.nom_complet}`}
                  disabled={envoi}
                  style={[styles.resultat, i > 0 && styles.resultatBordure]}
                >
                  <View style={styles.resultatInfos}>
                    <Text style={styles.resultatNom}>{m.nom_complet}</Text>
                    <Text style={styles.resultatMeta}>
                      {[m.specialite, m.structure?.nom, m.structure?.commune].filter(Boolean).join(' · ')}
                    </Text>
                    {!m.consulte_en_ligne ? (
                      <Text style={styles.resultatHorsLigne}>Ne consulte pas encore en ligne</Text>
                    ) : null}
                  </View>
                  <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
                </Pressable>
              ))}
            </View>
          )
        ) : null}
      </Card>

      {/* Historique des désignations révoquées : la trace que le patient peut opposer. */}
      {vue.historique.length > 0 ? (
        <Card style={styles.bloc}>
          <Text style={styles.blocTitre}>Anciens référents</Text>
          {vue.historique.map((h, i) => (
            <View key={h.id} style={[styles.histo, i > 0 && styles.resultatBordure]}>
              <Text style={styles.histoNom}>{h.medecin?.nom_complet ?? 'Médecin'}</Text>
              <Text style={styles.histoMeta}>
                Du {formatDateFr(h.designe_at)} au {formatDateFr(h.revoquee_at)}
              </Text>
            </View>
          ))}
        </Card>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[2] },
  blocAide: { ...typography.body, color: colors.ink[700], marginBottom: spacing[4] },
  sep: { height: spacing[3] },
  videTxt: { ...typography.body, color: colors.ink[500], marginTop: spacing[4] },

  // Référent actif
  actif: { borderWidth: 1, borderColor: colors.blue[600] },
  actifEntete: { flexDirection: 'row', alignItems: 'center' },
  pastille: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  actifInfos: { flex: 1 },
  actifNom: { ...typography.h2, color: colors.blue[900] },
  actifMeta: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  actifDepuis: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3] },
  actifAide: { ...typography.body, color: colors.ink[700], marginTop: spacing[2] },
  avertissement: {
    flexDirection: 'row',
    gap: spacing[2],
    backgroundColor: colors.warning.bg,
    borderRadius: radius.sm,
    padding: spacing[3],
    marginTop: spacing[3],
  },
  avertissementTxt: { ...typography.caption, color: colors.warning.text, flex: 1 },

  // Résultats de recherche
  resultats: { marginTop: spacing[4] },
  resultat: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  resultatBordure: { borderTopWidth: 1, borderTopColor: colors.line },
  resultatInfos: { flex: 1 },
  resultatNom: { ...typography.bodyStrong, color: colors.blue[900] },
  resultatMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  resultatHorsLigne: { ...typography.caption, color: colors.warning.text, marginTop: 2 },

  // Historique
  histo: { paddingVertical: spacing[3] },
  histoNom: { ...typography.bodyStrong, color: colors.blue[900] },
  histoMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
});
