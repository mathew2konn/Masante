import React, { useRef } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import { PIN_LONGUEUR } from '../auth/verrou';
import { colors, radius, spacing } from '../theme/theme';

/**
 * SaisiePin — saisie d'un code PIN à 6 chiffres. Affiche des pastilles remplies au fil de la frappe,
 * alimentées par un champ masqué (numérique). Le contenu réel n'est jamais affiché en clair.
 */
export function SaisiePin({
  valeur,
  onChange,
  erreur,
  autoFocus = true,
  editable = true,
}: {
  valeur: string;
  onChange: (v: string) => void;
  erreur?: boolean;
  autoFocus?: boolean;
  editable?: boolean;
}) {
  const ref = useRef<TextInput>(null);

  const gerer = (t: string) => {
    const chiffres = t.replace(/\D/g, '').slice(0, PIN_LONGUEUR);
    onChange(chiffres);
  };

  return (
    <Pressable onPress={() => ref.current?.focus()} accessibilityRole="none">
      <View style={styles.pastilles}>
        {Array.from({ length: PIN_LONGUEUR }).map((_, i) => {
          const rempli = i < valeur.length;
          return (
            <View
              key={i}
              style={[
                styles.pastille,
                { borderColor: erreur ? colors.danger.solid : rempli ? colors.blue[600] : colors.line },
                rempli && { backgroundColor: erreur ? colors.danger.solid : colors.blue[600] },
              ]}
            />
          );
        })}
      </View>

      <TextInput
        ref={ref}
        value={valeur}
        onChangeText={gerer}
        keyboardType="number-pad"
        secureTextEntry
        maxLength={PIN_LONGUEUR}
        autoFocus={autoFocus}
        editable={editable}
        caretHidden
        accessibilityLabel="Code PIN à 6 chiffres"
        style={styles.champCache}
      />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  pastilles: { flexDirection: 'row', justifyContent: 'center', gap: spacing[3], paddingVertical: spacing[3] },
  pastille: { width: 18, height: 18, borderRadius: radius.pill, borderWidth: 2 },
  // Champ invisible mais focusable (capte le clavier ; le rendu visible = les pastilles).
  champCache: { position: 'absolute', opacity: 0, height: 1, width: 1 },
});
