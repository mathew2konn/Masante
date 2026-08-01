import React, { useState } from 'react';
import { StyleSheet, Text, TextInput, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { Chip } from '../components/Chip';
import { Segmented } from '../components/Segmented';
import { ScreenHeader } from '../components/ScreenHeader';
import { PrimaryButton } from '../components/PrimaryButton';
import { IntensityScale } from '../components/IntensityScale';
import { colors, radius, spacing, typography } from '../theme/theme';
import type { Question, Symptome, ValeurReponse } from '../types/triage';

/** Clé unique d'une réponse dans la map d'état (symptôme + question). */
export const cleReponse = (symptomeId: number, cle: string) => `${symptomeId}:${cle}`;

/**
 * QuestionsScreen — F1.2 : questionnaire complémentaire des symptômes sélectionnés.
 * Rend le bon contrôle selon le type (nombre / échelle 1-10 / booléen / choix). Le
 * calcul d'impact reste 100 % serveur : on n'envoie que les valeurs brutes saisies.
 */
export function QuestionsScreen({
  symptomesAvecQuestions,
  reponses,
  onSetReponse,
  onBack,
  onAnalyser,
}: {
  symptomesAvecQuestions: Symptome[];
  reponses: Record<string, ValeurReponse>;
  onSetReponse: (key: string, valeur: ValeurReponse) => void;
  onBack: () => void;
  onAnalyser: () => Promise<void>;
}) {
  const [loading, setLoading] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  const analyser = async () => {
    try {
      setErreur(null);
      setLoading(true);
      await onAnalyser();
    } catch (e: any) {
      setErreur(messageErreur(e));
      setLoading(false);
    }
  };

  return (
    <Screen
      footer={
        <PrimaryButton
          label="Analyser mes symptômes"
          onPress={analyser}
          loading={loading}
          accessibilityLabel="Lancer l'analyse de triage"
        />
      }
    >
      <ScreenHeader
        title="Quelques précisions"
        subtitle="Ces réponses affinent l'évaluation. Tout est facultatif."
        onBack={onBack}
      />

      {symptomesAvecQuestions.length === 0 && (
        <Card style={styles.card}>
          <Text style={styles.body}>
            Aucune précision n'est nécessaire pour les symptômes choisis. Vous pouvez lancer
            l'analyse directement.
          </Text>
        </Card>
      )}

      {symptomesAvecQuestions.map((s) => (
        <Card key={s.id} style={styles.card}>
          <Text style={styles.symptomeNom}>{s.nom_fr}</Text>
          {(s.questions_complementaires_json ?? []).map((q) => (
            <QuestionInput
              key={q.cle}
              question={q}
              valeur={reponses[cleReponse(s.id, q.cle)]}
              onChange={(v) => onSetReponse(cleReponse(s.id, q.cle), v)}
            />
          ))}
        </Card>
      ))}

      {erreur && (
        <Card style={styles.erreurCard}>
          <Text style={styles.erreurTxt}>{erreur}</Text>
        </Card>
      )}
    </Screen>
  );
}

/** Rend le contrôle adapté au type de question (§5.7 + Segmented). */
function QuestionInput({
  question,
  valeur,
  onChange,
}: {
  question: Question;
  valeur: ValeurReponse | undefined;
  onChange: (v: ValeurReponse) => void;
}) {
  return (
    <View style={styles.qBlock}>
      <Text style={styles.qLibelle}>{question.libelle}</Text>

      {question.type === 'echelle' && (
        <IntensityScale
          value={typeof valeur === 'number' ? valeur : null}
          onChange={(v) => onChange(v)}
          min={question.min ?? 1}
          max={question.max ?? 10}
        />
      )}

      {question.type === 'nombre' && (
        <View style={styles.nombreRow}>
          <TextInput
            value={valeur != null ? String(valeur) : ''}
            onChangeText={(t) => {
              const n = t.replace(/[^0-9]/g, '').slice(0, 4);
              onChange(n === '' ? '' : Number(n));
            }}
            placeholder="0"
            placeholderTextColor={colors.ink[500]}
            keyboardType="number-pad"
            style={styles.nombreField}
            accessibilityLabel={question.libelle}
          />
          {question.unite ? <Text style={styles.unite}>{question.unite}</Text> : null}
        </View>
      )}

      {question.type === 'booleen' && (
        <Segmented<boolean>
          options={[
            { value: true, label: 'Oui' },
            { value: false, label: 'Non' },
          ]}
          value={typeof valeur === 'boolean' ? valeur : null}
          onChange={(v) => onChange(v)}
          accessibilityLabel={question.libelle}
        />
      )}

      {question.type === 'choix' && (
        <View style={styles.choixRow}>
          {(question.options ?? []).map((opt) => (
            <Chip key={opt} label={opt} selected={valeur === opt} onPress={() => onChange(opt)} />
          ))}
        </View>
      )}
    </View>
  );
}

/** Extrait un message lisible d'une erreur axios (validation 422 incluse). */
function messageErreur(e: any): string {
  const data = e?.response?.data;
  if (data?.errors) {
    const premier = Object.values(data.errors)[0];
    if (Array.isArray(premier) && premier[0]) return String(premier[0]);
  }
  return data?.message ?? e?.message ?? "L'analyse a échoué. Réessayez.";
}

const styles = StyleSheet.create({
  card: { marginBottom: spacing[4] },
  body: { ...typography.body, color: colors.ink[700] },
  symptomeNom: { ...typography.h2, color: colors.ink[900], marginBottom: spacing[2] },
  qBlock: { marginTop: spacing[4] },
  qLibelle: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[3] },
  nombreRow: { flexDirection: 'row', alignItems: 'center', gap: spacing[2] },
  nombreField: {
    backgroundColor: colors.surfaceMuted,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    minHeight: 48,
    width: 96,
    paddingHorizontal: spacing[3],
    ...typography.body,
    color: colors.ink[900],
  },
  unite: { ...typography.body, color: colors.ink[500] },
  choixRow: { gap: spacing[2] },
  erreurCard: { backgroundColor: colors.danger.bg },
  erreurTxt: { ...typography.body, color: colors.danger.text },
});
