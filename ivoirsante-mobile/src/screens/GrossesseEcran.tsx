import React, { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { DateField } from '../components/DateField';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import {
  ajouterConsultation,
  ajusterDdg,
  cloturerGrossesse,
  declarerGrossesse,
  obtenirGrossesse,
} from '../api/grossesse';
import { appelerSamu } from '../urgence/sos';
import { SAMU_NUMERO } from '../config/constants';
import { messageErreur } from '../utils/erreurs';
import { dateInputVersDate, formatDateFr } from '../utils/dates';
import type { EtapePrenatale, GrossesseVue, SuiviGrossesse } from '../types/grossesse';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * GrossesseEcran (CdC FN4, Module 5.5) — écran DÉDIÉ, accessible depuis la fiche d'un membre
 * féminin (le backend refuse un membre masculin). Déjà sous le VerrouGate (pile `membres/`).
 *
 * Deux visages selon la réponse de l'API :
 *  - sans grossesse : calendrier ÉDUCATIF des 8 contacts OMS + déclaration + historique clôturé ;
 *  - grossesse en cours : semaine d'aménorrhée (calculée serveur), timeline datée, consultations,
 *    carte « signes de danger » (appel direct au SAMU 185), et clôture définitive.
 * Le mobile n'affiche que ce que le serveur décide : ni terme, ni semaine recalculés ici.
 */

/** Signes de danger imposant une consultation immédiate (CdC FN4). */
const SIGNES_DANGER = [
  'Saignements vaginaux',
  'Fièvre',
  'Maux de tête violents ou vision floue',
  'Ventre dur et douloureux',
  'Le bébé ne bouge plus',
  'Perte des eaux',
];

const DUREE_MS = 24 * 3600 * 1000;

export function GrossesseEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [vue, setVue] = useState<GrossesseVue | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  // Déclaration d'une nouvelle grossesse.
  const [ddg, setDdg] = useState<string | null>(null);
  const [declaration, setDeclaration] = useState(false);

  // Ajustement de la DDG (datation échographique) — révélé à la demande.
  const [ajustOuvert, setAjustOuvert] = useState(false);
  const [ddgAjust, setDdgAjust] = useState<string | null>(null);
  const [ajustEnvoi, setAjustEnvoi] = useState(false);

  // Ajout d'une consultation prénatale (append-only).
  const [consultOuvert, setConsultOuvert] = useState(false);
  const [cDate, setCDate] = useState<string | null>(null);
  const [cMedecin, setCMedecin] = useState('');
  const [cStructure, setCStructure] = useState('');
  const [cNotes, setCNotes] = useState('');
  const [consultEnvoi, setConsultEnvoi] = useState(false);

  // Contacts dont la description/les conseils sont dépliés.
  const [depliees, setDepliees] = useState<Record<number, boolean>>({});

  const aujourdhui = useMemo(() => new Date(), []);
  // DDG plausible : au plus 43 semaines (301 jours) en arrière — miroir de la borne serveur.
  const minDdg = useMemo(() => new Date(Date.now() - 301 * DUREE_MS), []);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      setVue(await obtenirGrossesse(membreId));
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

  const suivi = vue?.suivi ?? null;

  const declarer = async () => {
    if (!ddg) return;
    setDeclaration(true);
    try {
      const { rappels_crees } = await declarerGrossesse(membreId, ddg);
      setDdg(null);
      await charger();
      Alert.alert(
        'Grossesse enregistrée',
        rappels_crees > 0
          ? `${rappels_crees} rendez-vous de suivi ont été ajoutés à vos rappels.`
          : 'Le suivi est ouvert.',
      );
    } catch (e) {
      Alert.alert('Déclaration impossible', messageErreur(e));
    } finally {
      setDeclaration(false);
    }
  };

  const ajuster = async () => {
    if (!suivi || !ddgAjust) return;
    setAjustEnvoi(true);
    try {
      await ajusterDdg(membreId, suivi.id, ddgAjust);
      setAjustOuvert(false);
      setDdgAjust(null);
      await charger();
      Alert.alert('Date mise à jour', 'Le terme et les rappels de suivi ont été recalculés.');
    } catch (e) {
      Alert.alert('Mise à jour impossible', messageErreur(e));
    } finally {
      setAjustEnvoi(false);
    }
  };

  const faireCloture = async (statut: 'termine' | 'interruption') => {
    if (!suivi) return;
    try {
      await cloturerGrossesse(membreId, suivi.id, statut);
      await charger();
    } catch (e) {
      Alert.alert('Clôture impossible', messageErreur(e));
    }
  };

  const cloturer = () => {
    if (!suivi) return;
    Alert.alert(
      'Clôturer le suivi ?',
      'Cette action est définitive. Le dossier reste conservé mais ne pourra plus être modifié, et les rappels de suivi seront désactivés.',
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Accouchement', onPress: () => faireCloture('termine') },
        { text: 'Interruption', style: 'destructive', onPress: () => faireCloture('interruption') },
      ],
    );
  };

  const ajouter = async () => {
    if (!suivi || !cDate) return;
    setConsultEnvoi(true);
    try {
      await ajouterConsultation(membreId, suivi.id, {
        date: cDate,
        medecin: cMedecin.trim() || undefined,
        structure: cStructure.trim() || undefined,
        notes: cNotes.trim() || undefined,
      });
      setCDate(null);
      setCMedecin('');
      setCStructure('');
      setCNotes('');
      setConsultOuvert(false);
      await charger();
    } catch (e) {
      Alert.alert('Ajout impossible', messageErreur(e));
    } finally {
      setConsultEnvoi(false);
    }
  };

  const appeler185 = async () => {
    try {
      await appelerSamu();
    } catch {
      Alert.alert('Appel impossible', `Composez vous-même le ${SAMU_NUMERO}.`);
    }
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Suivi de grossesse" subtitle={nomMembre} onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !vue) {
    return (
      <Screen>
        <ScreenHeader title="Suivi de grossesse" subtitle={nomMembre} onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Suivi indisponible.'}</Text>
      </Screen>
    );
  }

  return (
    <Screen>
      <ScreenHeader title="Suivi de grossesse" subtitle={nomMembre} onBack={() => router.back()} />

      {suivi ? (
        <>
          <EnTete suivi={suivi} />

          {/* Signes de danger : information de sécurité (aucune détection automatique). */}
          <Card style={[styles.bloc, styles.danger]}>
            <View style={styles.dangerEntete}>
              <Ionicons name="warning" size={20} color={colors.danger.text} />
              <Text style={styles.dangerTitre}>Signes de danger — consultez immédiatement</Text>
            </View>
            {SIGNES_DANGER.map((s) => (
              <View key={s} style={styles.dangerLigne}>
                <Text style={styles.dangerPuce}>•</Text>
                <Text style={styles.dangerTxt}>{s}</Text>
              </View>
            ))}
            <Pressable
              onPress={() => void appeler185()}
              accessibilityRole="button"
              accessibilityLabel={`Appeler le SAMU au ${SAMU_NUMERO}`}
              style={({ pressed }) => [styles.samu, pressed && styles.samuPresse]}
            >
              <Ionicons name="call" size={18} color={colors.surface} />
              <Text style={styles.samuTxt}>Appeler le SAMU — {SAMU_NUMERO}</Text>
            </Pressable>
          </Card>

          {/* Calendrier des 8 contacts, daté sur la grossesse. */}
          <Card style={styles.bloc}>
            <Text style={styles.blocTitre}>Calendrier de suivi (8 consultations)</Text>
            {vue.calendrier.map((e, i) => (
              <EtapeLigne
                key={e.numero}
                etape={e}
                premier={i === 0}
                depliee={depliees[e.numero] === true}
                onToggle={() => setDepliees((d) => ({ ...d, [e.numero]: !d[e.numero] }))}
              />
            ))}
          </Card>

          {/* Consultations réalisées (append-only). */}
          <Card style={styles.bloc}>
            <Text style={styles.blocTitre}>Consultations réalisées</Text>
            {suivi.consultations_json && suivi.consultations_json.length > 0 ? (
              suivi.consultations_json.map((c, i) => (
                <View key={`${c.date}-${i}`} style={[styles.consult, i > 0 && styles.consultBordure]}>
                  <Text style={styles.consultDate}>{formatDateFr(c.date)}</Text>
                  {c.medecin || c.structure ? (
                    <Text style={styles.consultLieu}>
                      {[c.medecin, c.structure].filter(Boolean).join(' · ')}
                    </Text>
                  ) : null}
                  {c.notes ? <Text style={styles.consultNotes}>{c.notes}</Text> : null}
                </View>
              ))
            ) : (
              <Text style={styles.videTxt}>Aucune consultation enregistrée pour l'instant.</Text>
            )}

            {consultOuvert ? (
              <View style={styles.form}>
                <DateField
                  label="Date de la consultation"
                  value={cDate}
                  onChange={setCDate}
                  min={dateInputVersDate(suivi.date_debut_grossesse) ?? minDdg}
                  max={aujourdhui}
                  obligatoire
                />
                <TextField label="Médecin (facultatif)" value={cMedecin} onChangeText={setCMedecin} autoCapitalize="words" maxLength={200} />
                <TextField label="Structure (facultatif)" value={cStructure} onChangeText={setCStructure} autoCapitalize="words" maxLength={200} />
                <TextField label="Notes (facultatif)" value={cNotes} onChangeText={setCNotes} autoCapitalize="sentences" maxLength={1000} multiline />
                <PrimaryButton label="Enregistrer" onPress={ajouter} loading={consultEnvoi} disabled={!cDate} />
                <View style={styles.sep} />
                <SecondaryButton label="Annuler" onPress={() => setConsultOuvert(false)} />
              </View>
            ) : (
              <View style={styles.ajouterRow}>
                <SecondaryButton label="Ajouter une consultation" onPress={() => setConsultOuvert(true)} />
              </View>
            )}
          </Card>

          {/* Actions sur le suivi. */}
          <Card style={styles.bloc}>
            <Text style={styles.blocTitre}>Gérer le suivi</Text>
            {ajustOuvert ? (
              <View style={styles.form}>
                <Text style={styles.blocAide}>
                  À utiliser après une échographie de datation : le terme et les rappels seront recalculés.
                </Text>
                <DateField
                  label="Nouvelle date de début de grossesse"
                  value={ddgAjust}
                  onChange={setDdgAjust}
                  min={minDdg}
                  max={aujourdhui}
                  obligatoire
                />
                <PrimaryButton label="Recalculer" onPress={ajuster} loading={ajustEnvoi} disabled={!ddgAjust} />
                <View style={styles.sep} />
                <SecondaryButton label="Annuler" onPress={() => { setAjustOuvert(false); setDdgAjust(null); }} />
              </View>
            ) : (
              <SecondaryButton
                label="Ajuster la date de début (échographie)"
                onPress={() => { setDdgAjust(suivi.date_debut_grossesse.slice(0, 10)); setAjustOuvert(true); }}
              />
            )}
            <View style={styles.sep} />
            <SecondaryButton label="Clôturer le suivi" onPress={cloturer} />
          </Card>
        </>
      ) : (
        <>
          {/* Déclaration d'une grossesse. */}
          <Card style={styles.bloc}>
            <Text style={styles.blocTitre}>Déclarer une grossesse</Text>
            <Text style={styles.blocAide}>
              Renseignez la date de début (premier jour des dernières règles). Elle pourra être ajustée
              après l'échographie de datation.
            </Text>
            <DateField
              label="Date de début de grossesse"
              value={ddg}
              onChange={setDdg}
              min={minDdg}
              max={aujourdhui}
              obligatoire
            />
            <PrimaryButton label="Déclarer" onPress={declarer} loading={declaration} disabled={!ddg} />
          </Card>

          {/* Calendrier éducatif (non daté) des 8 contacts. */}
          <Card style={styles.bloc}>
            <Text style={styles.blocTitre}>Les 8 consultations recommandées</Text>
            <Text style={styles.blocAide}>
              Le calendrier de suivi prénatal de l'OMS. Il se datera automatiquement à la déclaration.
            </Text>
            {vue.calendrier.map((e, i) => (
              <EtapeLigne
                key={e.numero}
                etape={e}
                premier={i === 0}
                depliee={depliees[e.numero] === true}
                onToggle={() => setDepliees((d) => ({ ...d, [e.numero]: !d[e.numero] }))}
              />
            ))}
          </Card>

          {/* Historique des grossesses clôturées. */}
          {vue.historique.length > 0 ? (
            <Card style={styles.bloc}>
              <Text style={styles.blocTitre}>Grossesses précédentes</Text>
              {vue.historique.map((h, i) => (
                <View key={h.id} style={[styles.histoLigne, i > 0 && styles.consultBordure]}>
                  <View style={styles.histoInfos}>
                    <Text style={styles.histoDates}>
                      {formatDateFr(h.date_debut_grossesse)} → {formatDateFr(h.date_terme_prevue)}
                    </Text>
                    <Text style={styles.histoStatut}>
                      {h.statut === 'termine' ? 'Accouchement' : 'Interruption'}
                    </Text>
                  </View>
                </View>
              ))}
            </Card>
          ) : null}
        </>
      )}
    </Screen>
  );
}

/** Bandeau de tête : semaine d'aménorrhée en cours + date de terme. */
function EnTete({ suivi }: { suivi: SuiviGrossesse }) {
  const semaine = suivi.semaine_actuelle;
  return (
    <Card style={[styles.bloc, styles.hero]}>
      <View style={styles.heroPastille}>
        <Text style={styles.heroSemaine}>{semaine ?? '—'}</Text>
        <Text style={styles.heroSemaineLbl}>SA</Text>
      </View>
      <View style={styles.heroInfos}>
        <Text style={styles.heroTitre}>
          {semaine !== null ? `Semaine ${semaine} d'aménorrhée` : 'Suivi en cours'}
        </Text>
        <Text style={styles.heroTerme}>Terme prévu le {formatDateFr(suivi.date_terme_prevue)}</Text>
      </View>
    </Card>
  );
}

/** Une ligne du calendrier : état, semaine, date estimée ; description/conseils dépliables. */
function EtapeLigne({
  etape,
  premier,
  depliee,
  onToggle,
}: {
  etape: EtapePrenatale;
  premier: boolean;
  depliee: boolean;
  onToggle: () => void;
}) {
  const passee = etape.passee === true;
  const teinte = passee ? colors.success : colors.blue;
  const fond = passee ? colors.success.bg : colors.blue[100];
  const trait = passee ? colors.success.text : colors.blue[700];

  return (
    <View style={[styles.etape, !premier && styles.consultBordure]}>
      <Pressable onPress={onToggle} accessibilityRole="button" style={styles.etapeEntete}>
        <View style={[styles.etapePastille, { backgroundColor: fond }]}>
          {passee ? (
            <Ionicons name="checkmark" size={16} color={colors.success.text} />
          ) : (
            <Text style={[styles.etapeNum, { color: trait }]}>{etape.numero}</Text>
          )}
        </View>
        <View style={styles.etapeTexte}>
          <Text style={styles.etapeLibelle}>{etape.libelle}</Text>
          <Text style={styles.etapeMeta}>
            {etape.date_estimee ? `Vers le ${formatDateFr(etape.date_estimee)}` : `Semaine ${etape.semaine_recommandee}`}
            {etape.date_estimee ? ` · ${etape.semaine_recommandee} SA` : ''}
          </Text>
        </View>
        <Ionicons name={depliee ? 'chevron-up' : 'chevron-down'} size={18} color={colors.ink[500]} />
      </Pressable>

      {depliee ? (
        <View style={styles.etapeDetail}>
          <Text style={styles.etapeDesc}>{etape.description}</Text>
          <View style={styles.nutritionBloc}>
            <Text style={styles.nutritionTitre}>Nutrition & conseils</Text>
            <Text style={styles.nutritionTxt}>{etape.conseils_nutrition}</Text>
          </View>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  blocAide: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[4] },
  sep: { height: spacing[3] },
  form: { marginTop: spacing[4] },
  videTxt: { ...typography.body, color: colors.ink[500] },
  ajouterRow: { marginTop: spacing[4] },

  // Bandeau de tête
  hero: { flexDirection: 'row', alignItems: 'center' },
  heroPastille: {
    width: 64,
    height: 64,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[4],
  },
  heroSemaine: { ...typography.h1, color: colors.blue[700] },
  heroSemaineLbl: { ...typography.caption, color: colors.blue[700], marginTop: -spacing[1] },
  heroInfos: { flex: 1 },
  heroTitre: { ...typography.h2, color: colors.blue[900] },
  heroTerme: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },

  // Signes de danger
  danger: { borderWidth: 1, borderColor: colors.danger.solid, backgroundColor: colors.danger.bg },
  dangerEntete: { flexDirection: 'row', alignItems: 'center', gap: spacing[2], marginBottom: spacing[3] },
  dangerTitre: { ...typography.bodyStrong, color: colors.danger.text, flex: 1 },
  dangerLigne: { flexDirection: 'row', gap: spacing[2], paddingVertical: 2 },
  dangerPuce: { ...typography.body, color: colors.danger.text },
  dangerTxt: { ...typography.body, color: colors.ink[900], flex: 1 },
  samu: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing[2],
    backgroundColor: colors.danger.solid,
    borderRadius: radius.md,
    paddingVertical: spacing[3],
    marginTop: spacing[3],
  },
  samuPresse: { opacity: 0.85 },
  samuTxt: { ...typography.button, color: colors.surface },

  // Calendrier / contacts
  etape: { paddingVertical: spacing[3] },
  etapeEntete: { flexDirection: 'row', alignItems: 'center' },
  etapePastille: {
    width: 32,
    height: 32,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  etapeNum: { ...typography.bodyStrong },
  etapeTexte: { flex: 1 },
  etapeLibelle: { ...typography.bodyStrong, color: colors.blue[900] },
  etapeMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  etapeDetail: { paddingLeft: spacing[8], paddingTop: spacing[2] },
  etapeDesc: { ...typography.body, color: colors.ink[900] },
  nutritionBloc: {
    marginTop: spacing[3],
    backgroundColor: colors.success.bg,
    borderRadius: radius.sm,
    padding: spacing[3],
  },
  nutritionTitre: { ...typography.caption, fontWeight: '700', color: colors.success.text, marginBottom: spacing[1] },
  nutritionTxt: { ...typography.body, color: colors.ink[900] },

  // Consultations
  consult: { paddingVertical: spacing[3] },
  consultBordure: { borderTopWidth: 1, borderTopColor: colors.line },
  consultDate: { ...typography.bodyStrong, color: colors.blue[900] },
  consultLieu: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  consultNotes: { ...typography.body, color: colors.ink[900], marginTop: spacing[1] },

  // Historique
  histoLigne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  histoInfos: { flex: 1 },
  histoDates: { ...typography.bodyStrong, color: colors.blue[900] },
  histoStatut: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
});
