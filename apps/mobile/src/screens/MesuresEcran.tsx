import React, { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { CourbeMesure, type PointCourbe } from '../components/CourbeMesure';
import { DateField } from '../components/DateField';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { enregistrerMesure, enregistrerTension, obtenirJournal, supprimerMesure } from '../api/mesures';
import { messageErreur } from '../utils/erreurs';
import { dateVersDateInput, formatDateFr } from '../utils/dates';
import type {
  JournalMesures,
  MesureSante,
  ReferentielMesure,
  StatutNorme,
  TypeMesure,
  TypeSaisie,
} from '../types/mesure';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * MesuresEcran (CdC FN5, Module 5.6) — journal de bord des maladies chroniques.
 *
 * Le mobile ne code AUCUNE norme médicale : unités, décimales, bornes de saisie, couleurs de
 * statut et conseils viennent tous du référentiel renvoyé par le serveur. Corriger un seuil se
 * fait en base, sans mise à jour de l'application.
 *
 * La TENSION est un cas à part : le patient saisit « 12/8 » d'un geste ; le serveur écrit deux
 * lignes liées (systolique + diastolique). L'écran suit le patient, la base suit le CdC.
 *
 * Une mesure ne se modifie pas — c'est un fait daté. Une saisie erronée se supprime (si elle vient
 * du patient) et se ressaisit : corriger une valeur passée réécrirait une courbe médicale.
 */

/** Les 6 gestes de saisie (la tension en couvre deux types stockés). */
const SAISIES: { type: TypeSaisie; libelle: string; icone: keyof typeof Ionicons.glyphMap }[] = [
  { type: 'glycemie', libelle: 'Glycémie', icone: 'water-outline' },
  { type: 'tension', libelle: 'Tension', icone: 'heart-outline' },
  { type: 'poids', libelle: 'Poids', icone: 'barbell-outline' },
  { type: 'temperature', libelle: 'Température', icone: 'thermometer-outline' },
  { type: 'pouls', libelle: 'Pouls', icone: 'pulse-outline' },
  { type: 'saturation_o2', libelle: 'Saturation', icone: 'fitness-outline' },
];

/** Couleur d'un statut (celui calculé par le serveur — jamais redéduit ici). */
function teinte(statut: StatutNorme) {
  if (statut === 'critique') return colors.danger;
  if (statut === 'normal') return colors.success;
  return colors.warning;
}

const LIBELLE_STATUT: Record<StatutNorme, string> = {
  normal: 'Normal',
  eleve: 'Élevé',
  bas: 'Bas',
  critique: 'Critique',
};

/** Type STOCKÉ correspondant au geste (la tension pointe vers sa systolique, tête de courbe). */
function typeStocke(saisie: TypeSaisie): TypeMesure {
  return saisie === 'tension' ? 'tension_systolique' : saisie;
}

/**
 * Horodatage envoyé au serveur : une mesure ne peut pas être future. Pour aujourd'hui on prend
 * l'heure courante ; pour un jour passé, midi (l'heure exacte d'une saisie a posteriori est
 * inconnue, et midi ne risque pas de basculer dans le futur).
 */
function horodatage(jour: string): string {
  const maintenant = new Date();
  const p = (n: number) => String(n).padStart(2, '0');

  if (jour === dateVersDateInput(maintenant)) {
    return `${jour} ${p(maintenant.getHours())}:${p(maintenant.getMinutes())}:00`;
  }

  return `${jour} 12:00:00`;
}

export function MesuresEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [journal, setJournal] = useState<JournalMesures | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  // Type de mesure affiché (courbe + journal filtré) et saisie en cours.
  const [selection, setSelection] = useState<TypeSaisie>('glycemie');
  const [formOuvert, setFormOuvert] = useState(false);
  const [valeur, setValeur] = useState('');
  const [systolique, setSystolique] = useState('');
  const [diastolique, setDiastolique] = useState('');
  const [jour, setJour] = useState<string | null>(dateVersDateInput(new Date()));
  const [note, setNote] = useState('');
  const [envoi, setEnvoi] = useState(false);

  const aujourdhui = useMemo(() => new Date(), []);
  // Le journal couvre 90 jours glissants : on n'autorise pas de saisie plus ancienne que la fenêtre.
  const minJour = useMemo(() => new Date(Date.now() - 90 * 24 * 3600 * 1000), []);

  const charger = useCallback(async () => {
    setErreur(null);
    try {
      setJournal(await obtenirJournal(membreId));
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

  const seuils = (type: TypeMesure): ReferentielMesure | undefined =>
    journal?.referentiels.find((r) => r.type_mesure === type);

  const seuilsSelection = seuils(typeStocke(selection));
  const seuilsDiastolique = seuils('tension_diastolique');

  /** Mesures du type affiché, de la plus ancienne à la plus récente (l'ordre d'une courbe). */
  const serie = useMemo<MesureSante[]>(() => {
    if (!journal) return [];
    return journal.mesures
      .filter((m) => m.type_mesure === typeStocke(selection))
      .slice()
      .sort((a, b) => a.date_mesure.localeCompare(b.date_mesure));
  }, [journal, selection]);

  const points = useMemo<PointCourbe[]>(
    () => serie.map((m) => ({ t: Date.parse(m.date_mesure), valeur: m.valeur, statut: m.statut_norme })),
    [serie],
  );

  /** Journal affiché : du plus récent au plus ancien, en regroupant les deux lignes d'une tension. */
  const lignes = useMemo(() => {
    if (!journal) return [];

    const duType = journal.mesures.filter((m) =>
      selection === 'tension'
        ? m.type_mesure === 'tension_systolique' || m.type_mesure === 'tension_diastolique'
        : m.type_mesure === selection,
    );

    if (selection !== 'tension') {
      return duType.map((m) => ({ cle: String(m.id), principale: m, secondaire: null as MesureSante | null }));
    }

    // Une prise de tension = deux lignes de même `groupe_uuid` : on les réunit pour afficher 12/8.
    const systoliques = duType.filter((m) => m.type_mesure === 'tension_systolique');
    return systoliques.map((m) => ({
      cle: String(m.id),
      principale: m,
      secondaire:
        duType.find((d) => d.type_mesure === 'tension_diastolique' && d.groupe_uuid === m.groupe_uuid) ?? null,
    }));
  }, [journal, selection]);

  const changerType = (type: TypeSaisie) => {
    setSelection(type);
    setFormOuvert(false);
    setValeur('');
    setSystolique('');
    setDiastolique('');
    setNote('');
  };

  const enregistrer = async () => {
    if (!jour) return;
    setEnvoi(true);
    try {
      const commun = { date_mesure: horodatage(jour), note: note.trim() || undefined };

      const reponse =
        selection === 'tension'
          ? await enregistrerTension(membreId, {
              systolique: Number(systolique.replace(',', '.')),
              diastolique: Number(diastolique.replace(',', '.')),
              ...commun,
            })
          : await enregistrerMesure(membreId, {
              type_mesure: selection,
              valeur: Number(valeur.replace(',', '.')),
              ...commun,
            });

      setValeur('');
      setSystolique('');
      setDiastolique('');
      setNote('');
      setFormOuvert(false);
      await charger();

      // L'alerte « valeur anormale » (FN5) : le texte vient du serveur, pas de l'app.
      if (reponse.alerte) {
        Alert.alert(
          'Valeur critique',
          reponse.alerte.conseil ?? 'Cette valeur est hors norme : demandez un avis médical sans attendre.',
        );
      }
    } catch (e) {
      Alert.alert('Enregistrement impossible', messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  const confirmerSuppression = (mesure: MesureSante) => {
    if (mesure.source !== 'patient') {
      Alert.alert(
        'Suppression impossible',
        'Cette mesure a été enregistrée par une structure de santé : elle appartient à votre dossier médical.',
      );
      return;
    }

    Alert.alert(
      'Supprimer cette mesure ?',
      mesure.groupe_uuid
        ? 'La tension sera supprimée entièrement (systolique et diastolique).'
        : 'La mesure sera retirée de votre journal. Vous pourrez la ressaisir.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Supprimer',
          style: 'destructive',
          onPress: async () => {
            try {
              await supprimerMesure(membreId, mesure.id);
              await charger();
            } catch (e) {
              Alert.alert('Suppression impossible', messageErreur(e));
            }
          },
        },
      ],
    );
  };

  const saisieValide =
    jour !== null &&
    (selection === 'tension' ? systolique.trim() !== '' && diastolique.trim() !== '' : valeur.trim() !== '');

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Journal de santé" subtitle={nomMembre} onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (erreur || !journal) {
    return (
      <Screen>
        <ScreenHeader title="Journal de santé" subtitle={nomMembre} onBack={() => router.back()} />
        <Text style={styles.erreur}>{erreur ?? 'Journal indisponible.'}</Text>
      </Screen>
    );
  }

  return (
    <Screen>
      <ScreenHeader title="Journal de santé" subtitle={nomMembre} onBack={() => router.back()} />

      {/* Dernière valeur connue par type : ce qu'on veut voir en ouvrant l'écran. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Dernières mesures</Text>
        <View style={styles.resumeGrille}>
          {journal.resume
            .filter((r) => r.type_mesure !== 'tension_diastolique')
            .map((r) => {
              const couleur = r.statut_norme ? teinte(r.statut_norme) : null;
              return (
                <View key={r.type_mesure} style={styles.resumeCase}>
                  <Text style={styles.resumeLbl} numberOfLines={1}>
                    {r.type_mesure === 'tension_systolique' ? 'Tension' : r.libelle}
                  </Text>
                  {r.valeur !== null ? (
                    <>
                      <Text style={[styles.resumeVal, couleur ? { color: couleur.text } : null]}>
                        {r.valeur}
                        <Text style={styles.resumeUnite}> {r.unite}</Text>
                      </Text>
                      <Text style={styles.resumeDate}>{formatDateFr(r.date_mesure)}</Text>
                    </>
                  ) : (
                    <Text style={styles.resumeVide}>Aucune</Text>
                  )}
                </View>
              );
            })}
        </View>
      </Card>

      {/* Choix du type suivi : courbe + journal + saisie portent tous sur ce type. */}
      <Card style={styles.bloc}>
        <View style={styles.typesRangee}>
          {SAISIES.map((s) => {
            const actif = selection === s.type;
            return (
              <Pressable
                key={s.type}
                onPress={() => changerType(s.type)}
                accessibilityRole="radio"
                accessibilityState={{ selected: actif }}
                accessibilityLabel={s.libelle}
                style={[styles.typeChip, actif && styles.typeChipActif]}
              >
                <Ionicons name={s.icone} size={16} color={actif ? colors.surface : colors.blue[700]} />
                <Text style={[styles.typeTxt, actif && styles.typeTxtActif]}>{s.libelle}</Text>
              </Pressable>
            );
          })}
        </View>

        {seuilsSelection ? (
          points.length > 0 ? (
            <CourbeMesure
              points={points}
              normalMin={seuilsSelection.normal_min}
              normalMax={seuilsSelection.normal_max}
              unite={seuilsSelection.unite}
              legendeDebut={formatDateFr(serie[0]?.date_mesure)}
              legendeFin={formatDateFr(serie[serie.length - 1]?.date_mesure)}
            />
          ) : (
            <Text style={styles.videTxt}>
              Aucune mesure de {seuilsSelection.libelle.toLowerCase()} sur les 90 derniers jours.
            </Text>
          )
        ) : null}

        {formOuvert ? (
          <View style={styles.form}>
            {selection === 'tension' ? (
              <View style={styles.tensionRangee}>
                <View style={styles.tensionChamp}>
                  <TextField
                    label="Systolique (mmHg)"
                    value={systolique}
                    onChangeText={setSystolique}
                    keyboardType="numeric"
                    maxLength={5}
                  />
                </View>
                <View style={styles.tensionChamp}>
                  <TextField
                    label="Diastolique (mmHg)"
                    value={diastolique}
                    onChangeText={setDiastolique}
                    keyboardType="numeric"
                    maxLength={5}
                  />
                </View>
              </View>
            ) : (
              <TextField
                label={`${seuilsSelection?.libelle ?? 'Valeur'} (${seuilsSelection?.unite ?? ''})`}
                value={valeur}
                onChangeText={setValeur}
                keyboardType="numeric"
                maxLength={8}
              />
            )}

            <DateField
              label="Date de la mesure"
              value={jour}
              onChange={setJour}
              min={minJour}
              max={aujourdhui}
              obligatoire
            />
            <TextField
              label="Note (facultatif)"
              value={note}
              onChangeText={setNote}
              autoCapitalize="sentences"
              maxLength={500}
              multiline
            />
            <PrimaryButton label="Enregistrer" onPress={enregistrer} loading={envoi} disabled={!saisieValide} />
            <View style={styles.sep} />
            <SecondaryButton label="Annuler" onPress={() => setFormOuvert(false)} />
          </View>
        ) : (
          <View style={styles.ajouterRow}>
            <PrimaryButton
              label={`Ajouter une mesure — ${SAISIES.find((s) => s.type === selection)?.libelle}`}
              onPress={() => setFormOuvert(true)}
            />
          </View>
        )}

        {/* Bornes de saisie et conseil : contenu médical de la base, affiché tel quel. */}
        {seuilsSelection ? (
          <Text style={styles.aide}>
            Saisie acceptée entre {seuilsSelection.valeur_min} et {seuilsSelection.valeur_max}{' '}
            {seuilsSelection.unite}
            {selection === 'tension' && seuilsDiastolique
              ? ` (diastolique : ${seuilsDiastolique.valeur_min} à ${seuilsDiastolique.valeur_max} ${seuilsDiastolique.unite})`
              : ''}
            .
          </Text>
        ) : null}
      </Card>

      {/* Journal du type suivi. */}
      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Historique</Text>
        {lignes.length === 0 ? (
          <Text style={styles.videTxt}>Aucune saisie enregistrée.</Text>
        ) : (
          lignes.map((l, i) => {
            const couleur = teinte(l.principale.statut_norme);
            const valeurTxt = l.secondaire
              ? `${l.principale.valeur}/${l.secondaire.valeur} ${l.principale.unite}`
              : `${l.principale.valeur} ${l.principale.unite}`;

            return (
              <Pressable
                key={l.cle}
                onLongPress={() => confirmerSuppression(l.principale)}
                accessibilityRole="button"
                accessibilityLabel={`Mesure du ${formatDateFr(l.principale.date_mesure)} : ${valeurTxt}. Appui long pour supprimer.`}
                style={[styles.ligne, i > 0 && styles.ligneBordure]}
              >
                <View style={styles.ligneInfos}>
                  <Text style={styles.ligneValeur}>{valeurTxt}</Text>
                  <Text style={styles.ligneDate}>
                    {formatDateFr(l.principale.date_mesure)}
                    {l.principale.source !== 'patient' ? ' · saisie par une structure' : ''}
                  </Text>
                  {l.principale.note ? <Text style={styles.ligneNote}>{l.principale.note}</Text> : null}
                </View>
                <View style={[styles.badge, { backgroundColor: couleur.bg }]}>
                  <Text style={[styles.badgeTxt, { color: couleur.text }]}>
                    {LIBELLE_STATUT[l.principale.statut_norme]}
                  </Text>
                </View>
              </Pressable>
            );
          })
        )}
        {lignes.length > 0 ? (
          <Text style={styles.aide}>
            Appui long sur une mesure pour la supprimer. Une mesure ne se modifie pas : supprimez-la et
            ressaisissez-la.
          </Text>
        ) : null}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },

  bloc: { marginBottom: spacing[5] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  sep: { height: spacing[3] },
  form: { marginTop: spacing[4] },
  ajouterRow: { marginTop: spacing[4] },
  videTxt: { ...typography.body, color: colors.ink[500], marginTop: spacing[3] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: spacing[3] },

  // Résumé
  resumeGrille: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  resumeCase: {
    flexGrow: 1,
    flexBasis: '30%',
    backgroundColor: colors.blue[100],
    borderRadius: radius.sm,
    padding: spacing[3],
  },
  resumeLbl: { ...typography.caption, color: colors.blue[700] },
  resumeVal: { ...typography.h2, color: colors.blue[900], marginTop: spacing[1] },
  resumeUnite: { ...typography.caption },
  resumeDate: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  resumeVide: { ...typography.body, color: colors.ink[500], marginTop: spacing[1] },

  // Sélecteur de type
  typesRangee: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  typeChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[1],
    paddingVertical: spacing[2],
    paddingHorizontal: spacing[3],
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
  },
  typeChipActif: { backgroundColor: colors.blue[600] },
  typeTxt: { ...typography.caption, color: colors.blue[700] },
  typeTxtActif: { color: colors.surface },

  tensionRangee: { flexDirection: 'row', gap: spacing[3] },
  tensionChamp: { flex: 1 },

  // Historique
  ligne: { flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  ligneBordure: { borderTopWidth: 1, borderTopColor: colors.line },
  ligneInfos: { flex: 1 },
  ligneValeur: { ...typography.bodyStrong, color: colors.blue[900] },
  ligneDate: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  ligneNote: { ...typography.body, color: colors.ink[900], marginTop: spacing[1] },
  badge: { paddingVertical: spacing[1], paddingHorizontal: spacing[2], borderRadius: radius.pill },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
});
