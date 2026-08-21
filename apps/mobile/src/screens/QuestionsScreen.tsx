import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { Chip } from '../components/Chip';
import { Segmented } from '../components/Segmented';
import { ScreenHeader } from '../components/ScreenHeader';
import { PrimaryButton } from '../components/PrimaryButton';
import { IntensityScale } from '../components/IntensityScale';
import { colors, radius, spacing, typography } from '../theme/theme';
import { getQuestionsTriage } from '../api/triage';
import type {
  ConstanteSaisie,
  ContextePatient,
  Question,
  Reponse,
  ValeurReponse,
} from '../types/triage';

/**
 * QuestionsScreen — F1.2 : le questionnaire ADAPTATIF (CDC_08 §4.3b, CDC_05 §5.5.2).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * P10b-3-i — CET ÉCRAN NE CONNAÎT PLUS LES QUESTIONS À L'AVANCE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Il les recevait avec la liste des symptômes (`questions_complementaires_json`) et les posait
 * TOUTES, pour tous les symptômes cochés. Elles viennent maintenant d'un protocole versionné et
 * signé, servies **un tour à la fois** : répondre peut en débloquer d'autres.
 *
 * ═══ POURQUOI L'ÉCRAN NE COMPILE PAS L'ARBRE LUI-MÊME ═══
 *
 * Ce serait plus rapide — et ce serait une **règle médicale dans le front**, ce que la règle de
 * frontière interdit (CDC_01 §0.1). « Pose cette question si la gêne persiste au repos » est une
 * décision clinique relue et signée par quatre validateurs (§7) ; la recopier ici en ferait une
 * seconde autorité, capable de diverger de la première sans que rien ne le signale.
 *
 * Le calcul d'impact reste lui aussi 100 % serveur : on n'envoie que les valeurs brutes saisies.
 *
 * ═══ LES BORNES SONT AFFICHÉES, ELLES NE SONT PAS APPLIQUÉES ICI ═══
 *
 * `valeur_min`/`valeur_max` servent à dessiner l'échelle. C'est le serveur qui refuse une valeur
 * hors plage, sur la version publiée — et il la refuse au lieu de l'écrêter, pour qu'un patient ne
 * se retrouve pas avec une réponse qu'il n'a pas donnée.
 */
export function QuestionsScreen({
  symptomes,
  patient,
  reponses,
  reponsesEnvoyees,
  onSetReponse,
  constantes,
  onBack,
  onAnalyser,
}: {
  symptomes: number[];
  patient: ContextePatient;
  reponses: Record<string, ValeurReponse>;
  reponsesEnvoyees: Reponse[];
  onSetReponse: (cle: string, valeur: ValeurReponse) => void;
  /**
   * P10c-1 — Les constantes relevées à l'étape précédente.
   *
   * Elles sont renvoyées à CHAQUE tour, et ce n'est pas de la redondance : une règle peut
   * conditionner une question sur la fièvre (« 39,5 — depuis quand ? »), ce qui est l'adaptativité
   * du §4.3b. Les omettre ici ferait répondre le serveur sans elles, et cette règle ne se
   * déclencherait jamais — sur CET endpoint seulement. C'est le constat Z1, déplacé d'un cran.
   */
  constantes: ConstanteSaisie[];
  onBack: () => void;
  onAnalyser: () => Promise<void>;
}) {
  const [questions, setQuestions] = useState<Question[] | null>(null);
  const [termine, setTermine] = useState(false);
  const [chargement, setChargement] = useState(true);
  const [analyse, setAnalyse] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  /**
   * Un tour de questionnaire.
   *
   * Les réponses déjà données repartent à chaque tour : le serveur ne conserve rien tant que le
   * triage n'est pas lancé — un patient qui abandonne au milieu de l'interrogatoire ne laisse
   * aucune trace, et c'est voulu (il n'a pris aucune décision de santé).
   */
  const chargerTour = useCallback(async () => {
    try {
      setErreur(null);
      setChargement(true);

      const tour = await getQuestionsTriage({
        symptomes,
        ...(reponsesEnvoyees.length ? { reponses: reponsesEnvoyees } : {}),
        ...(constantes.length ? { constantes } : {}),
        patient_age: patient.patient_age ?? null,
        patient_sexe: patient.patient_sexe ?? null,
      });

      setQuestions(tour.questions);
      setTermine(tour.termine);
    } catch (e: any) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
  }, [symptomes, patient, reponsesEnvoyees, constantes]);

  // Premier tour à l'ouverture. Les tours suivants sont déclenchés par « Continuer », jamais
  // automatiquement à chaque frappe : recharger pendant que le patient saisit ferait disparaître
  // le champ sous ses doigts.
  useEffect(() => {
    void chargerTour();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const continuer = useCallback(async () => {
    if (termine) {
      try {
        setErreur(null);
        setAnalyse(true);
        await onAnalyser();
      } catch (e: any) {
        setErreur(messageErreur(e));
        setAnalyse(false);
      }

      return;
    }

    await chargerTour();
  }, [termine, onAnalyser, chargerTour]);

  const enAttente = chargement || analyse;

  return (
    <Screen
      footer={
        <PrimaryButton
          label={termine ? 'Analyser mes symptômes' : 'Continuer'}
          onPress={continuer}
          loading={enAttente}
          accessibilityLabel={
            termine ? "Lancer l'analyse de triage" : 'Passer à la suite du questionnaire'
          }
        />
      }
    >
      <ScreenHeader
        title="Quelques précisions"
        subtitle="Ces réponses affinent l'évaluation. Tout est facultatif."
        onBack={onBack}
      />

      {chargement && questions === null && (
        <Card style={styles.card}>
          <View style={styles.chargement}>
            <ActivityIndicator color={colors.blue[600]} />
            <Text style={styles.body}>Préparation du questionnaire…</Text>
          </View>
        </Card>
      )}

      {questions !== null && questions.length === 0 && (
        <Card style={styles.card}>
          <Text style={styles.body}>
            Aucune précision supplémentaire n'est nécessaire. Vous pouvez lancer l'analyse.
          </Text>
        </Card>
      )}

      {questions !== null && questions.length > 0 && (
        <Card style={styles.card}>
          {questions.map((q) => (
            <QuestionInput
              key={q.cle}
              question={q}
              valeur={reponses[q.cle]}
              onChange={(v) => onSetReponse(q.cle, v)}
            />
          ))}
        </Card>
      )}

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
          min={question.valeur_min ?? 1}
          max={question.valeur_max ?? 10}
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

      {/* Les réponses possibles viennent du protocole : leur VALEUR sert aux règles, leur LIBELLÉ
          au patient. Les confondre ferait dépendre une règle clinique d'un texte d'affichage. */}
      {question.type === 'choix' && (
        <View style={styles.choixRow}>
          {question.reponses.map((opt) => (
            <Chip
              key={opt.valeur}
              label={opt.libelle}
              selected={valeur === opt.valeur}
              onPress={() => onChange(opt.valeur)}
            />
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
  chargement: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
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
