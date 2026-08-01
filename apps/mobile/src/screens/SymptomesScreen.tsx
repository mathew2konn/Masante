import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { Chip } from '../components/Chip';
import { Segmented } from '../components/Segmented';
import { ScreenHeader } from '../components/ScreenHeader';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { colors, radius, spacing, typography } from '../theme/theme';
import { categorieAffichage } from '../config/constants';
import { getSymptomes } from '../api/triage';
import type { ContextePatient, Symptome } from '../types/triage';

/**
 * SymptomesScreen — F1.1 : recherche + sélection des symptômes (chips), groupés par
 * catégorie, plus le contexte patient facultatif (âge → pédiatrie, sexe → gynéco).
 * La liste est mise en cache au niveau App pour préserver le choix au retour (F1.2).
 */
export function SymptomesScreen({
  cached,
  onCached,
  selectedIds,
  onToggle,
  patient,
  onPatientChange,
  onBack,
  onContinue,
}: {
  cached: Symptome[] | null;
  onCached: (s: Symptome[]) => void;
  selectedIds: number[];
  onToggle: (id: number) => void;
  patient: ContextePatient;
  onPatientChange: (p: ContextePatient) => void;
  onBack: () => void;
  onContinue: () => void;
}) {
  const [chargement, setChargement] = useState(!cached);
  const [erreur, setErreur] = useState<string | null>(null);
  const [recherche, setRecherche] = useState('');
  const [ageTexte, setAgeTexte] = useState(patient.patient_age != null ? String(patient.patient_age) : '');

  useEffect(() => {
    if (cached) return;
    let actif = true;
    (async () => {
      try {
        setChargement(true);
        setErreur(null);
        const data = await getSymptomes();
        if (actif) onCached(data.symptomes);
      } catch (e: any) {
        if (actif) setErreur(e?.message ?? 'Impossible de charger les symptômes.');
      } finally {
        if (actif) setChargement(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, [cached, onCached]);

  // Filtrage + regroupement par catégorie (l'ordre vient déjà trié de l'API).
  const groupes = useMemo(() => {
    const liste = cached ?? [];
    const q = recherche.trim().toLowerCase();
    const filtree = q ? liste.filter((s) => s.nom_fr.toLowerCase().includes(q)) : liste;
    const map = new Map<string, Symptome[]>();
    for (const s of filtree) {
      if (!map.has(s.categorie)) map.set(s.categorie, []);
      map.get(s.categorie)!.push(s);
    }
    return Array.from(map.entries());
  }, [cached, recherche]);

  const onAgeChange = (txt: string) => {
    const nettoye = txt.replace(/[^0-9]/g, '').slice(0, 3);
    setAgeTexte(nettoye);
    onPatientChange({ ...patient, patient_age: nettoye === '' ? null : Number(nettoye) });
  };

  return (
    <Screen
      footer={
        <PrimaryButton
          label={`Continuer${selectedIds.length ? ` (${selectedIds.length})` : ''}`}
          onPress={onContinue}
          disabled={selectedIds.length === 0}
          accessibilityLabel="Continuer vers les questions complémentaires"
        />
      }
    >
      <ScreenHeader
        title="Vos symptômes"
        subtitle="Sélectionnez tout ce que vous ressentez."
        onBack={onBack}
      />

      {/* Recherche (§5.3) */}
      <View style={styles.search}>
        <Text style={styles.searchIcon}>🔍</Text>
        <TextInput
          value={recherche}
          onChangeText={setRecherche}
          placeholder="Rechercher un symptôme…"
          placeholderTextColor={colors.ink[500]}
          style={styles.searchInput}
          accessibilityLabel="Rechercher un symptôme"
        />
      </View>

      {chargement && (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.blue[600]} />
          <Text style={styles.muted}>Chargement des symptômes…</Text>
        </View>
      )}

      {erreur && !chargement && (
        <Card style={styles.erreurCard}>
          <Text style={styles.erreurTxt}>{erreur}</Text>
          <View style={{ marginTop: spacing[3] }}>
            <SecondaryButton
              label="Réessayer"
              onPress={() => {
                setErreur(null);
                onCachedRetry(setChargement, setErreur, onCached);
              }}
            />
          </View>
        </Card>
      )}

      {!chargement &&
        !erreur &&
        groupes.map(([cat, items]) => {
          const aff = categorieAffichage(cat);
          return (
            <View key={cat} style={styles.groupe}>
              <Text style={styles.groupeTitre}>
                {aff.icone}  {aff.label}
              </Text>
              <View style={styles.chips}>
                {items.map((s) => (
                  <Chip
                    key={s.id}
                    label={s.nom_fr}
                    selected={selectedIds.includes(s.id)}
                    onPress={() => onToggle(s.id)}
                  />
                ))}
              </View>
            </View>
          );
        })}

      {!chargement && !erreur && groupes.length === 0 && (
        <Text style={styles.muted}>Aucun symptôme ne correspond à « {recherche} ».</Text>
      )}

      {/* Contexte patient facultatif */}
      {!chargement && !erreur && (
        <Card style={styles.patientCard}>
          <Text style={styles.h2}>Patient (facultatif)</Text>
          <Text style={styles.label}>Prénom ou nom</Text>
          <TextInput
            value={patient.patient_nom ?? ''}
            onChangeText={(t) => onPatientChange({ ...patient, patient_nom: t || null })}
            placeholder="Ex. Aya"
            placeholderTextColor={colors.ink[500]}
            style={styles.field}
            accessibilityLabel="Prénom ou nom du patient"
          />

          <Text style={styles.label}>Âge</Text>
          <TextInput
            value={ageTexte}
            onChangeText={onAgeChange}
            placeholder="Ex. 28"
            placeholderTextColor={colors.ink[500]}
            keyboardType="number-pad"
            style={styles.field}
            accessibilityLabel="Âge du patient en années"
          />

          <Text style={styles.label}>Sexe</Text>
          <Segmented<'M' | 'F'>
            options={[
              { value: 'M', label: 'Homme' },
              { value: 'F', label: 'Femme' },
            ]}
            value={patient.patient_sexe ?? null}
            onChange={(v) =>
              onPatientChange({
                ...patient,
                patient_sexe: patient.patient_sexe === v ? null : v,
              })
            }
            accessibilityLabel="Sexe du patient"
          />
        </Card>
      )}
    </Screen>
  );
}

// Petite aide de relance du chargement (évite de dupliquer le bloc d'effet).
function onCachedRetry(
  setChargement: (b: boolean) => void,
  setErreur: (e: string | null) => void,
  onCached: (s: Symptome[]) => void,
) {
  setChargement(true);
  getSymptomes()
    .then((d) => onCached(d.symptomes))
    .catch((e) => setErreur(e?.message ?? 'Impossible de charger les symptômes.'))
    .finally(() => setChargement(false));
}

const styles = StyleSheet.create({
  search: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: spacing[3],
    marginBottom: spacing[5],
  },
  searchIcon: { fontSize: 16, marginRight: spacing[2] },
  searchInput: { flex: 1, minHeight: 48, ...typography.body, color: colors.ink[900] },
  center: { alignItems: 'center', paddingVertical: spacing[10] },
  muted: { ...typography.body, color: colors.ink[500], marginTop: spacing[3], textAlign: 'center' },
  groupe: { marginBottom: spacing[5] },
  groupeTitre: { ...typography.bodyStrong, color: colors.blue[800], marginBottom: spacing[3] },
  chips: { gap: spacing[2] },
  patientCard: { marginTop: spacing[3] },
  h2: { ...typography.h2, color: colors.ink[900], marginBottom: spacing[3] },
  label: { ...typography.caption, color: colors.ink[700], marginBottom: spacing[1], marginTop: spacing[3] },
  field: {
    backgroundColor: colors.surfaceMuted,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    minHeight: 48,
    paddingHorizontal: spacing[3],
    ...typography.body,
    color: colors.ink[900],
  },
  erreurCard: { backgroundColor: colors.danger.bg },
  erreurTxt: { ...typography.body, color: colors.danger.text },
});
