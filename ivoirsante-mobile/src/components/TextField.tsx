import React, { useState } from 'react';
import { StyleSheet, Text, TextInput, View, type KeyboardTypeOptions } from 'react-native';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * TextField — champ de saisie du Design System (§5.3).
 * Fond surface-muted, rayon 8, bordure `line` ; au focus, bordure blue-600 sur 2 dp.
 * Libellé toujours présent (lisibilité §6) + accessibilityLabel implicite.
 */
export function TextField({
  label,
  value,
  onChangeText,
  placeholder,
  secureTextEntry,
  keyboardType,
  autoCapitalize = 'none',
  maxLength,
  erreur,
}: {
  label: string;
  value: string;
  onChangeText: (t: string) => void;
  placeholder?: string;
  secureTextEntry?: boolean;
  keyboardType?: KeyboardTypeOptions;
  autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters';
  maxLength?: number;
  erreur?: string | null;
}) {
  const [focus, setFocus] = useState(false);

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.ink[500]}
        secureTextEntry={secureTextEntry}
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
        maxLength={maxLength}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        accessibilityLabel={label}
        style={[
          styles.input,
          { borderColor: erreur ? colors.danger.solid : focus ? colors.blue[600] : colors.line, borderWidth: focus || erreur ? 2 : 1 },
        ]}
      />
      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[1] },
  input: {
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.sm,
    paddingHorizontal: spacing[3],
    minHeight: 48,
    ...typography.body,
    color: colors.ink[900],
  },
  erreur: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
});
