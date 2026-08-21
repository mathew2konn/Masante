import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, TextInput, View } from 'react-native';
import { Screen } from '../components/Screen';
import { Card } from '../components/Card';
import { ScreenHeader } from '../components/ScreenHeader';
import { PrimaryButton } from '../components/PrimaryButton';
import { colors, radius, spacing, typography } from '../theme/theme';
import { getConstantesTriage } from '../api/triage';
import type { ConstanteProposable } from '../types/triage';

/**
 * ConstantesScreen — P10c-1 : les constantes cliniques du §5.2.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * TROIS SOURCES, TROIS PHRASES, ET UNE SEULE EST UNE AFFIRMATION SUR LE PRÉSENT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Le carnet **propose**, le patient **confirme**. Une mesure récente pré-remplit le champ **avec sa
 * date** ; une mesure ancienne est montrée pour information (« dernière valeur connue : 38,2 °C,
 * il y a 3 jours ») et **n'est jamais pré-remplie**.
 *
 * *Une température prise il y a trois mois n'est pas une température.* La faire entrer dans une
 * règle clinique la présenterait comme le présent — c'est la faute des trois sources de P6.4b,
 * où la réponse fut de **dire laquelle des trois** on tient : une mesure, une déclaration, un
 * souvenir.
 *
 * ═══ CET ÉCRAN NE DÉCIDE DE RIEN ═══
 *
 * Il ne sait pas ce qu'est une fièvre, ne colore aucune valeur, n'affiche aucun statut. Le
 * référentiel sait pourtant classer 39,5 °C en « critique » — mais ce seuil est gouverné par deux
 * signatures administratives, alors qu'un seuil décidant de l'urgence relève des quatre validations
 * du §7. Le jugement appartient au protocole, côté serveur (ADR-043, décision E2).
 *
 * Les bornes sont AFFICHÉES et non appliquées : le serveur refuse lui-même une valeur hors plage,
 * sur la version publiée, et il la refuse **au lieu de l'écrêter** — pour qu'un patient ne se
 * retrouve pas avec une mesure qu'il n'a pas relevée. Les répliquer ici en ferait une seconde
 * autorité (même partage que les bornes d'échelle du questionnaire, P10b-3-i).
 *
 * ═══ TOUT EST FACULTATIF ═══
 *
 * Le triage fonctionne sans aucune constante depuis le Module 1, et cet incrément ne change pas ce
 * contrat. Quelqu'un qui n'a ni thermomètre ni tensiomètre passe l'étape.
 */
export function ConstantesScreen({
  membreId,
  valeurs,
  onSetValeur,
  onBack,
  onContinue,
}: {
  membreId?: number | null;
  valeurs: Record<string, string>;
  onSetValeur: (type: string, valeur: string) => void;
  onBack: () => void;
  onContinue: () => void;
}) {
  const [constantes, setConstantes] = useState<ConstanteProposable[] | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  const charger = useCallback(async () => {
    try {
      setErreur(null);
      setChargement(true);

      const reponse = await getConstantesTriage(membreId);
      setConstantes(reponse.constantes);

      // ═══ LE PRÉ-REMPLISSAGE VIENT DU SERVEUR, ET SEULEMENT DE `proposition` ═══
      //
      // On ne touche jamais à un champ que le patient a déjà rempli : recharger l'écran ne doit
      // pas effacer ce qu'il vient de taper. Et `contexte` n'est JAMAIS recopié — c'est
      // précisément ce qui distingue un souvenir d'une mesure.
      for (const ligne of reponse.constantes) {
        if (ligne.proposition !== null && valeurs[ligne.type_mesure] === undefined) {
          onSetValeur(ligne.type_mesure, String(ligne.proposition.valeur));
        }
      }
    } catch (e: any) {
      setErreur(messageErreur(e));
    } finally {
      setChargement(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [membreId]);

  useEffect(() => {
    void charger();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Screen
      footer={
        <PrimaryButton
          label="Continuer"
          onPress={onContinue}
          disabled={chargement}
          accessibilityLabel="Passer aux précisions du questionnaire"
        />
      }
    >
      <ScreenHeader
        title="Vos mesures"
        subtitle="Si vous les avez sous la main. Tout est facultatif."
        onBack={onBack}
      />

      {chargement && constantes === null && (
        <Card style={styles.card}>
          <View style={styles.chargement}>
            <ActivityIndicator color={colors.blue[600]} />
            <Text style={styles.body}>Chargement…</Text>
          </View>
        </Card>
      )}

      {constantes !== null && constantes.length > 0 && (
        <Card style={styles.card}>
          {constantes.map((c) => (
            <ConstanteInput
              key={c.type_mesure}
              constante={c}
              valeur={valeurs[c.type_mesure] ?? ''}
              onChange={(v) => onSetValeur(c.type_mesure, v)}
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

/** Un champ de saisie, accompagné de ce que le carnet en dit — jamais d'un jugement. */
function ConstanteInput({
  constante,
  valeur,
  onChange,
}: {
  constante: ConstanteProposable;
  valeur: string;
  onChange: (v: string) => void;
}) {
  return (
    <View style={styles.bloc}>
      <Text style={styles.libelle}>
        {constante.libelle} <Text style={styles.unite}>({constante.unite})</Text>
      </Text>

      <TextInput
        style={styles.champ}
        value={valeur}
        onChangeText={onChange}
        keyboardType="decimal-pad"
        placeholder={`${constante.valeur_min} – ${constante.valeur_max}`}
        placeholderTextColor={colors.disabled}
        accessibilityLabel={`${constante.libelle} en ${constante.unite}`}
      />

      {/*
        LA PROVENANCE EST DITE, ET LES DEUX PHRASES NE SE RESSEMBLENT PAS.

        Une valeur pré-remplie annonce d'où elle vient et QUAND elle a été prise : sans la date,
        le patient validerait une mesure sans savoir de quand elle date. Une valeur hors fenêtre
        n'est PAS dans le champ — elle est en dessous, au passé, et le patient doit la retaper
        s'il la juge encore valable. C'est ce geste qui fait la différence entre une mesure et un
        souvenir.
      */}
      {constante.proposition !== null && (
        <Text style={styles.note}>
          Repris de votre carnet{formatterDate(constante.proposition.date_mesure)}. Corrigez si
          vous venez de la mesurer.
        </Text>
      )}

      {constante.contexte !== null && (
        <Text style={styles.noteAncienne}>
          Dernière valeur connue : {constante.contexte.valeur} {constante.unite}
          {formatterDate(constante.contexte.date_mesure)}. Trop ancienne pour être reprise
          automatiquement.
        </Text>
      )}
    </View>
  );
}

/** « il y a 3 jours » — l'information qui manque le plus à une valeur reprise. */
function formatterDate(iso: string | null): string {
  if (!iso) return '';

  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

  if (minutes < 60) return ` (il y a ${minutes} min)`;
  if (minutes < 60 * 24) return ` (il y a ${Math.round(minutes / 60)} h)`;

  return ` (il y a ${Math.round(minutes / (60 * 24))} j)`;
}

function messageErreur(e: any): string {
  return (
    e?.response?.data?.message ??
    'Impossible de charger la liste des mesures. Vous pouvez continuer sans les renseigner.'
  );
}

const styles = StyleSheet.create({
  card: { marginBottom: spacing[4] },
  chargement: { flexDirection: 'row', alignItems: 'center', gap: spacing[3] },
  body: { ...typography.body, color: colors.ink[700] },
  bloc: { marginTop: spacing[4] },
  libelle: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[3] },
  unite: { ...typography.body, color: colors.ink[500] },
  champ: {
    backgroundColor: colors.surfaceMuted,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    minHeight: 48,
    paddingHorizontal: spacing[3],
    ...typography.body,
    color: colors.ink[900],
  },
  note: { ...typography.caption, color: colors.ink[500], marginTop: spacing[2] },
  noteAncienne: {
    ...typography.caption,
    color: colors.ink[500],
    marginTop: spacing[2],
    fontStyle: 'italic',
  },
  erreurCard: { backgroundColor: colors.danger.bg },
  erreurTxt: { ...typography.body, color: colors.danger.text },
});
