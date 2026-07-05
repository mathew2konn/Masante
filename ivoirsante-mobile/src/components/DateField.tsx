import React, { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { DateWheelPicker } from './DateWheelPicker';
import { dateInputVersDate, dateVersDateInput, formatDateFr } from '../utils/dates';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * DateField — champ de date ouvrant un sélecteur « molette » (DateWheelPicker) dans une feuille
 * modale, avec validation explicite (« Valider »). Rendu identique sur Android et iOS. La valeur
 * entre/sort au format AAAA-MM-JJ (comme l'API) ; `min`/`max` bornent la sélection (et la valeur
 * finale est replafonnée sur cet intervalle).
 */
export function DateField({
  label,
  value,
  onChange,
  placeholder = 'Sélectionner une date',
  erreur,
  min,
  max,
  obligatoire,
}: {
  label: string;
  value: string | null;
  onChange: (v: string | null) => void;
  placeholder?: string;
  erreur?: string | null;
  min?: Date;
  max?: Date;
  obligatoire?: boolean;
}) {
  const [ouvert, setOuvert] = useState(false);
  const dateCourante = dateInputVersDate(value);
  const [temp, setTemp] = useState<Date>(dateCourante ?? max ?? new Date());

  const ouvrir = () => {
    setTemp(dateCourante ?? max ?? new Date());
    setOuvert(true);
  };

  const valider = () => {
    let d = temp;
    // Replafonne sur [min, max] (le wheel ne borne que l'année ; on ferme les bords ici).
    if (max && d.getTime() > max.getTime()) d = max;
    if (min && d.getTime() < min.getTime()) d = min;
    setOuvert(false);
    onChange(dateVersDateInput(d));
  };

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>

      <Pressable
        onPress={ouvrir}
        accessibilityRole="button"
        accessibilityLabel={label}
        style={[styles.champ, { borderColor: erreur ? colors.danger.solid : colors.line, borderWidth: erreur ? 2 : 1 }]}
      >
        <Ionicons name="calendar-outline" size={18} color={colors.ink[500]} />
        <Text style={[styles.valeur, !dateCourante && styles.placeholder]}>
          {dateCourante ? formatDateFr(value) : placeholder}
        </Text>
        {dateCourante && !obligatoire ? (
          <Pressable
            onPress={() => onChange(null)}
            accessibilityRole="button"
            accessibilityLabel="Effacer la date"
            hitSlop={8}
          >
            <Ionicons name="close-circle" size={18} color={colors.ink[500]} />
          </Pressable>
        ) : null}
      </Pressable>

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      <Modal visible={ouvert} transparent animationType="fade" onRequestClose={() => setOuvert(false)}>
        <Pressable style={styles.backdrop} onPress={() => setOuvert(false)}>
          <Pressable style={styles.feuille} onPress={() => {}}>
            <View style={styles.feuilleEntete}>
              <Pressable onPress={() => setOuvert(false)} accessibilityRole="button">
                <Text style={styles.annuler}>Annuler</Text>
              </Pressable>
              <Text style={styles.feuilleTitre}>{label}</Text>
              <Pressable onPress={valider} accessibilityRole="button">
                <Text style={styles.valider}>Valider</Text>
              </Pressable>
            </View>
            <DateWheelPicker
              value={temp}
              onChange={setTemp}
              minYear={min ? min.getFullYear() : undefined}
              maxYear={max ? max.getFullYear() : undefined}
            />
          </Pressable>
        </Pressable>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[1] },
  champ: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[2],
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.sm,
    paddingHorizontal: spacing[3],
    minHeight: 48,
  },
  valeur: { ...typography.body, color: colors.ink[900], flex: 1 },
  placeholder: { color: colors.ink[500] },
  erreur: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },

  backdrop: { flex: 1, backgroundColor: 'rgba(12,52,99,0.35)', justifyContent: 'flex-end' },
  feuille: { backgroundColor: colors.surface, borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg, paddingBottom: spacing[6] },
  feuilleEntete: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
  feuilleTitre: { ...typography.bodyStrong, color: colors.ink[900] },
  annuler: { ...typography.body, color: colors.ink[500] },
  valider: { ...typography.bodyStrong, color: colors.blue[600] },
});
