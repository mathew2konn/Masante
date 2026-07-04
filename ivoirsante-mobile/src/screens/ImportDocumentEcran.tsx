import React, { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import {
  choisirDansGalerie,
  choisirFichier,
  PermissionRefusee,
  prendrePhoto,
} from '../documents/selection';
import { importerDocument, type FichierAImporter } from '../api/documents';
import { CATEGORIES, type CategorieDocument } from '../types/document';
import { formatTaille } from '../utils/fichiers';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * ImportDocumentEcran (F2.10) — import d'un document médical.
 *
 * Trois sources : appareil photo, galerie (images compressées avant envoi) ou fichier (PDF, DOCX…).
 * Le MIME réel est revalidé côté serveur (liste blanche). La date du document n'est pas saisie ici :
 * elle relève du futur sélecteur jour/mois/année uniforme (item différé du plan).
 */
export function ImportDocumentEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [fichier, setFichier] = useState<FichierAImporter | null>(null);
  const [tailleOctets, setTailleOctets] = useState<number | null>(null);
  const [categorie, setCategorie] = useState<CategorieDocument>('certificat_medical');
  const [titre, setTitre] = useState('');

  const [envoi, setEnvoi] = useState(false);
  const [progression, setProgression] = useState(0);
  const [erreur, setErreur] = useState<string | null>(null);

  const selectionner = async (source: () => Promise<FichierAImporter | null>) => {
    setErreur(null);
    try {
      const choisi = await source();
      if (choisi) {
        setFichier(choisi);
        setTailleOctets(null); // taille réelle confirmée par le serveur après upload
      }
    } catch (e) {
      setErreur(e instanceof PermissionRefusee ? e.message : "Sélection impossible. Réessayez.");
    }
  };

  const importer = async () => {
    if (!fichier) return;
    setErreur(null);
    setEnvoi(true);
    setProgression(0);
    try {
      await importerDocument(
        membreId,
        fichier,
        { categorie, titre: titre.trim() || undefined },
        (ratio) => setProgression(ratio),
      );
      router.back(); // la liste se recharge au focus
    } catch (e) {
      setErreur(e instanceof Error ? e.message : "L'import a échoué. Réessayez.");
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen
      footer={
        <>
          {erreur ? <Text style={styles.erreur}>{erreur}</Text> : null}
          {envoi ? (
            <View style={styles.barre}>
              <View style={[styles.barreProgres, { width: `${Math.round(progression * 100)}%` }]} />
            </View>
          ) : null}
          <PrimaryButton
            label={fichier ? 'Importer le document' : 'Choisissez un fichier'}
            onPress={importer}
            disabled={!fichier}
            loading={envoi}
          />
        </>
      }
    >
      <ScreenHeader
        title="Ajouter un document"
        subtitle={nomMembre ? `Dossier de ${nomMembre}` : undefined}
        onBack={() => router.back()}
      />

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Source</Text>
        <View style={styles.sources}>
          <SourceBouton icone="camera-outline" label="Photo" onPress={() => selectionner(prendrePhoto)} disabled={envoi} />
          <SourceBouton icone="images-outline" label="Galerie" onPress={() => selectionner(choisirDansGalerie)} disabled={envoi} />
          <SourceBouton icone="folder-outline" label="Fichier" onPress={() => selectionner(choisirFichier)} disabled={envoi} />
        </View>

        {fichier ? (
          <View style={styles.fichierRow}>
            <Ionicons name="document-attach-outline" size={22} color={colors.blue[600]} />
            <View style={styles.fichierInfos}>
              <Text style={styles.fichierNom} numberOfLines={1}>
                {fichier.nom}
              </Text>
              <Text style={styles.fichierMeta}>
                {fichier.mimeType ?? 'type inconnu'}
                {tailleOctets != null ? ` · ${formatTaille(tailleOctets)}` : ''}
              </Text>
            </View>
            <Pressable onPress={() => setFichier(null)} accessibilityLabel="Retirer le fichier" disabled={envoi}>
              <Ionicons name="close-circle" size={22} color={colors.ink[500]} />
            </Pressable>
          </View>
        ) : (
          <Text style={styles.aide}>Formats acceptés : PDF, image, Word, Excel, CSV… (20 Mo max).</Text>
        )}
      </Card>

      <Card style={styles.bloc}>
        <Text style={styles.blocTitre}>Classement</Text>
        <DropdownCategorie value={categorie} onSelect={setCategorie} />
        <TextField
          label="Titre (optionnel)"
          value={titre}
          onChangeText={setTitre}
          placeholder="Ex : Résultat prise de sang"
          maxLength={200}
        />
        <Text style={styles.aide}>Sans titre, le nom du fichier sera utilisé.</Text>
      </Card>
    </Screen>
  );
}

/** Un bouton de source (photo / galerie / fichier). */
function SourceBouton({
  icone,
  label,
  onPress,
  disabled,
}: {
  icone: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [styles.source, pressed && styles.sourcePressed, disabled && styles.sourceDisabled]}
    >
      <Ionicons name={icone} size={24} color={colors.blue[600]} />
      <Text style={styles.sourceTxt}>{label}</Text>
    </Pressable>
  );
}

/** Menu déroulant (liste fermée) de la catégorie. */
function DropdownCategorie({
  value,
  onSelect,
}: {
  value: CategorieDocument;
  onSelect: (v: CategorieDocument) => void;
}) {
  const [ouvert, setOuvert] = useState(false);
  const courant = CATEGORIES.find((c) => c.value === value)?.label ?? 'Catégorie';

  return (
    <View style={styles.champ}>
      <Text style={styles.label}>Catégorie</Text>
      <Pressable
        onPress={() => setOuvert(true)}
        accessibilityRole="button"
        accessibilityLabel={`Catégorie : ${courant}`}
        style={styles.select}
      >
        <Text style={styles.selectTxt}>{courant}</Text>
        <Ionicons name="chevron-down" size={20} color={colors.ink[500]} />
      </Pressable>

      <Modal visible={ouvert} transparent animationType="fade" onRequestClose={() => setOuvert(false)}>
        <Pressable style={styles.backdrop} onPress={() => setOuvert(false)}>
          <View style={styles.menu}>
            <ScrollView keyboardShouldPersistTaps="handled">
              {CATEGORIES.map((o) => {
                const actif = o.value === value;
                return (
                  <Pressable
                    key={o.value}
                    onPress={() => {
                      onSelect(o.value);
                      setOuvert(false);
                    }}
                    accessibilityRole="button"
                    style={styles.option}
                  >
                    <Ionicons name={o.icone as keyof typeof Ionicons.glyphMap} size={18} color={colors.blue[600]} />
                    <Text style={[styles.optionTxt, actif && styles.optionActive]}>{o.label}</Text>
                    {actif ? <Ionicons name="checkmark" size={18} color={colors.blue[600]} /> : null}
                  </Pressable>
                );
              })}
            </ScrollView>
          </View>
        </Pressable>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[2], textAlign: 'center' },
  barre: { height: 6, borderRadius: radius.pill, backgroundColor: colors.blue[100], marginBottom: spacing[3], overflow: 'hidden' },
  barreProgres: { height: 6, borderRadius: radius.pill, backgroundColor: colors.blue[600] },

  bloc: { marginBottom: spacing[4] },
  blocTitre: { ...typography.h2, color: colors.blue[900], marginBottom: spacing[3] },
  aide: { ...typography.caption, color: colors.ink[500], marginTop: spacing[1] },

  sources: { flexDirection: 'row', gap: spacing[3], marginBottom: spacing[3] },
  source: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing[4],
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.line,
  },
  sourcePressed: { backgroundColor: colors.blue[50] },
  sourceDisabled: { opacity: 0.5 },
  sourceTxt: { ...typography.caption, color: colors.blue[900], fontWeight: '700', marginTop: spacing[1] },

  fichierRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[3],
    backgroundColor: colors.blue[50],
    borderRadius: radius.md,
    padding: spacing[3],
  },
  fichierInfos: { flex: 1 },
  fichierNom: { ...typography.bodyStrong, color: colors.ink[900] },
  fichierMeta: { ...typography.caption, color: colors.ink[500], marginTop: 2 },

  champ: { marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[1] },
  select: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: spacing[3],
    minHeight: 48,
  },
  selectTxt: { ...typography.body, color: colors.ink[900] },

  backdrop: { flex: 1, backgroundColor: 'rgba(12,52,99,0.35)', justifyContent: 'center', paddingHorizontal: spacing[6] },
  menu: { backgroundColor: colors.surface, borderRadius: radius.md, maxHeight: '70%', paddingVertical: spacing[1] },
  option: { flexDirection: 'row', alignItems: 'center', gap: spacing[3], paddingHorizontal: spacing[4], paddingVertical: spacing[3] },
  optionTxt: { ...typography.body, color: colors.ink[900], flex: 1 },
  optionActive: { ...typography.bodyStrong, color: colors.blue[700] },
});
