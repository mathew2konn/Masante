import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import * as Sharing from 'expo-sharing';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { PrimaryButton } from '../components/PrimaryButton';
import { listerDocuments, supprimerDocument, telechargerDocument } from '../api/documents';
import { messageErreur } from '../utils/erreurs';
import { formatTaille } from '../utils/fichiers';
import { formatDateFr } from '../utils/dates';
import { CATEGORIES, STATUT_PRESENTATION, type DocumentMedical } from '../types/document';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * DocumentsEcran (F2.10) — liste des documents d'un membre, regroupés par catégorie.
 *
 * Un document « sain » s'ouvre après téléchargement déchiffré (partage système) ; « en analyse » et
 * « rejeté » sont verrouillés côté serveur (423) et signalés ici. Suppression = soft-delete serveur.
 */
export function DocumentsEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [documents, setDocuments] = useState<DocumentMedical[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [ouvertureId, setOuvertureId] = useState<number | null>(null);

  const recharger = useCallback(() => {
    let actif = true;
    (async () => {
      setErreur(null);
      try {
        const items = await listerDocuments(membreId);
        if (actif) setDocuments(items);
      } catch (e) {
        if (actif) setErreur(messageErreur(e));
      } finally {
        if (actif) setChargement(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, [membreId]);

  useFocusEffect(recharger);

  const ouvrir = async (doc: DocumentMedical) => {
    if (doc.statut_antivirus === 'en_attente') {
      Alert.alert('Analyse en cours', "Ce document est en cours d'analyse antivirus. Réessayez dans un instant.");
      return;
    }
    if (doc.statut_antivirus === 'infecte') {
      Alert.alert('Document rejeté', "Ce fichier a été bloqué par l'analyse antivirus et ne peut pas être ouvert.");
      return;
    }

    setOuvertureId(doc.id);
    try {
      const uri = await telechargerDocument(membreId, doc);
      if (await Sharing.isAvailableAsync()) {
        await Sharing.shareAsync(uri, { mimeType: doc.mime_type, dialogTitle: doc.titre });
      } else {
        Alert.alert('Téléchargé', 'Le document a été enregistré sur l’appareil.');
      }
    } catch (e) {
      Alert.alert('Ouverture impossible', messageErreur(e));
    } finally {
      setOuvertureId(null);
    }
  };

  const confirmerSuppression = (doc: DocumentMedical) => {
    Alert.alert('Supprimer ce document ?', `« ${doc.titre} » sera retiré du dossier.`, [
      { text: 'Annuler', style: 'cancel' },
      {
        text: 'Supprimer',
        style: 'destructive',
        onPress: async () => {
          try {
            await supprimerDocument(membreId, doc.id);
            setDocuments((liste) => liste.filter((d) => d.id !== doc.id));
          } catch (e) {
            Alert.alert('Suppression impossible', messageErreur(e));
          }
        },
      },
    ]);
  };

  const versImport = () =>
    router.push({
      pathname: '/(app)/membres/documents/importer/[id]',
      params: { id: membreId, nom: nomMembre ?? '' },
    });

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Documents médicaux" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  // Regroupement par catégorie, dans l'ordre d'affichage défini, sections vides masquées.
  const groupes = CATEGORIES.map((c) => ({
    ...c,
    items: documents.filter((d) => d.categorie === c.value),
  })).filter((g) => g.items.length > 0);

  return (
    <Screen footer={<PrimaryButton label="Ajouter un document" onPress={versImport} />}>
      <ScreenHeader
        title="Documents médicaux"
        subtitle={nomMembre ? `Dossier de ${nomMembre}` : 'Certificats, résultats, ordonnances…'}
        onBack={() => router.back()}
      />

      {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}

      {documents.length === 0 ? (
        <Card style={styles.vide}>
          <Ionicons name="folder-open-outline" size={40} color={colors.blue[300]} />
          <Text style={styles.videTitre}>Aucun document</Text>
          <Text style={styles.videTxt}>
            Importez un certificat, un résultat d'analyse ou une ordonnance pour le conserver en sécurité.
          </Text>
        </Card>
      ) : (
        groupes.map((g) => (
          <View key={g.value} style={styles.groupe}>
            <Text style={styles.groupeTitre}>{g.label}</Text>
            <Card>
              {g.items.map((doc, i) => (
                <LigneDocument
                  key={doc.id}
                  doc={doc}
                  icone={g.icone as keyof typeof Ionicons.glyphMap}
                  premier={i === 0}
                  occupe={ouvertureId === doc.id}
                  onOuvrir={() => ouvrir(doc)}
                  onSupprimer={() => confirmerSuppression(doc)}
                />
              ))}
            </Card>
          </View>
        ))
      )}
    </Screen>
  );
}

/** Une ligne « document » : icône, titre, méta, badge de statut, action de suppression. */
function LigneDocument({
  doc,
  icone,
  premier,
  occupe,
  onOuvrir,
  onSupprimer,
}: {
  doc: DocumentMedical;
  icone: keyof typeof Ionicons.glyphMap;
  premier: boolean;
  occupe: boolean;
  onOuvrir: () => void;
  onSupprimer: () => void;
}) {
  const statut = STATUT_PRESENTATION[doc.statut_antivirus];
  const ton = colors[statut.ton];

  return (
    <View style={[styles.ligne, !premier && styles.ligneBordure]}>
      <Pressable style={styles.ligneCorps} onPress={onOuvrir} accessibilityRole="button" accessibilityLabel={`Ouvrir ${doc.titre}`}>
        <View style={styles.pastille}>
          <Ionicons name={icone} size={18} color={colors.blue[600]} />
        </View>
        <View style={styles.infos}>
          <Text style={styles.titre} numberOfLines={1}>
            {doc.titre}
          </Text>
          <Text style={styles.meta} numberOfLines={1}>
            {formatTaille(doc.taille_octets)}
            {doc.date_document ? ` · ${formatDateFr(doc.date_document)}` : ''}
          </Text>
          <View style={[styles.badge, { backgroundColor: ton.bg }]}>
            <Text style={[styles.badgeTxt, { color: ton.text }]}>{statut.label}</Text>
          </View>
        </View>
        {occupe ? (
          <ActivityIndicator color={colors.blue[600]} />
        ) : (
          <Ionicons name="download-outline" size={20} color={colors.ink[500]} />
        )}
      </Pressable>
      <Pressable onPress={onSupprimer} accessibilityRole="button" accessibilityLabel={`Supprimer ${doc.titre}`} style={styles.supprimer}>
        <Ionicons name="trash-outline" size={18} color={colors.danger.solid} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },

  vide: { alignItems: 'center', paddingVertical: spacing[8] },
  videTitre: { ...typography.h2, color: colors.blue[900], marginTop: spacing[3] },
  videTxt: { ...typography.body, color: colors.ink[700], textAlign: 'center', marginTop: spacing[2] },

  groupe: { marginBottom: spacing[5] },
  groupeTitre: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[2] },

  ligne: { flexDirection: 'row', alignItems: 'center' },
  ligneBordure: { borderTopWidth: 1, borderTopColor: colors.line },
  ligneCorps: { flex: 1, flexDirection: 'row', alignItems: 'center', paddingVertical: spacing[3] },
  pastille: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  infos: { flex: 1, marginRight: spacing[2] },
  titre: { ...typography.bodyStrong, color: colors.ink[900] },
  meta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },
  badge: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[2], paddingVertical: 2, marginTop: spacing[1] },
  badgeTxt: { ...typography.caption, fontWeight: '700' },
  supprimer: { padding: spacing[2], marginLeft: spacing[1] },
});
