import React, { useCallback, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../../../src/components/Screen';
import { ScreenHeader } from '../../../../src/components/ScreenHeader';
import { Card } from '../../../../src/components/Card';
import { listerAcces } from '../../../../src/api/qr';
import { messageErreur } from '../../../../src/utils/erreurs';
import { libelleTypeAcces, type AccesDossier } from '../../../../src/types/qr';
import { formatDateHeureFr } from '../../../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../../../src/theme/theme';

/**
 * Journal d'accès au dossier d'un membre (droit d'accès patient, §10.3 Sécurité ; loi 2013-450).
 * Lecture seule : le patient voit qui a consulté le dossier, quand et comment. Le journal est
 * immuable côté serveur — rien n'est modifiable ici.
 */
export default function AccesMembreScreen() {
  const { id, prenom, nom } = useLocalSearchParams<{ id: string; prenom?: string; nom?: string }>();
  const membreId = Number(id);

  const [acces, setAcces] = useState<AccesDossier[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const liste = await listerAcces(membreId);
          if (actif) setAcces(liste);
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, [membreId]),
  );

  const nomComplet = [prenom, nom].filter(Boolean).join(' ') || 'ce membre';

  return (
    <Screen>
      <ScreenHeader
        title="Journal d'accès"
        subtitle={`Dossier de ${nomComplet}`}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : acces.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="shield-checkmark-outline" size={32} color={colors.blue[400]} />
          <Text style={styles.videTxt}>Aucun accès enregistré.</Text>
          <Text style={styles.videSous}>
            Chaque consultation du dossier par un agent de santé apparaîtra ici, de façon
            permanente et inaltérable.
          </Text>
        </Card>
      ) : (
        <View style={styles.liste}>
          {acces.map((a) => (
            <AccesItem key={a.id} acces={a} />
          ))}
        </View>
      )}
    </Screen>
  );
}

/** Carte d'une entrée du journal (lecture seule). */
function AccesItem({ acces }: { acces: AccesDossier }) {
  const sections = acces.sections_consultees?.length ? acces.sections_consultees.join(', ') : null;

  return (
    <Card style={styles.item}>
      <View style={styles.itemEntete}>
        <View style={styles.pastille}>
          <Ionicons name="qr-code-outline" size={18} color={colors.blue[600]} />
        </View>
        <View style={styles.itemTexte}>
          <Text style={styles.itemType}>{libelleTypeAcces(acces.type_acces)}</Text>
          <Text style={styles.itemDate}>{formatDateHeureFr(acces.created_at)}</Text>
        </View>
      </View>

      {acces.agent_id !== null ? <Ligne libelle="Agent" valeur={`#${acces.agent_id}`} /> : null}
      {acces.duree_minutes !== null ? <Ligne libelle="Durée" valeur={`${acces.duree_minutes} min`} /> : null}
      {sections ? <Ligne libelle="Sections" valeur={sections} /> : null}
      {acces.ip_address ? <Ligne libelle="Adresse IP" valeur={acces.ip_address} /> : null}
    </Card>
  );
}

function Ligne({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <View style={styles.ligne}>
      <Text style={styles.ligneLib}>{libelle}</Text>
      <Text style={styles.ligneVal}>{valeur}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  vide: { alignItems: 'center' },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[3] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  liste: { gap: spacing[3] },
  item: {},
  itemEntete: { flexDirection: 'row', alignItems: 'center' },
  pastille: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  itemTexte: { flex: 1 },
  itemType: { ...typography.bodyStrong, color: colors.blue[900] },
  itemDate: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  ligne: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: spacing[2] },
  ligneLib: { ...typography.caption, color: colors.ink[500], marginRight: spacing[4] },
  ligneVal: { ...typography.caption, color: colors.ink[900], flex: 1, textAlign: 'right' },
});
