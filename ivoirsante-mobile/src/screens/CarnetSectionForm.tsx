import React, { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { Chip } from '../components/Chip';
import { Segmented } from '../components/Segmented';
import { PrimaryButton } from '../components/PrimaryButton';
import { SecondaryButton } from '../components/SecondaryButton';
import { sectionParSlug } from '../carnet/registre';
import { creerItem, modifierItem, obtenirItem } from '../api/carnet';
import { messageErreur } from '../utils/erreurs';
import { heureCourte, isoVersDateInput, validerDate, validerHeure } from '../utils/dates';
import type { Champ, Medicament, ParametreResultat, SectionDescriptor } from '../types/carnet';
import { colors, spacing, typography } from '../theme/theme';

/**
 * CarnetSectionForm — formulaire générique création / édition d'un élément de section,
 * piloté par le schéma du registre (src/carnet/registre.ts). Aucune logique spécifique à
 * une section ici : rendu, validation et payload sont dérivés des champs déclarés.
 */
export function CarnetSectionForm({
  membreId,
  slug,
  itemId,
  nomMembre,
}: {
  membreId: number;
  slug: string;
  itemId?: number;
  nomMembre?: string;
}) {
  const section = sectionParSlug(slug);
  const edition = itemId !== undefined;

  const [valeurs, setValeurs] = useState<Record<string, unknown>>({});
  const [erreurs, setErreurs] = useState<Record<string, string | null>>({});
  const [chargement, setChargement] = useState(edition);
  const [chargementErreur, setChargementErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreurServeur, setErreurServeur] = useState<string | null>(null);

  // Valeurs initiales (création) ou préchargement (édition).
  useEffect(() => {
    if (!section) return;
    let actif = true;
    if (!edition) {
      setValeurs(initiales(section, null));
      return;
    }
    (async () => {
      try {
        const item = await obtenirItem(membreId, section.chemin, itemId!);
        if (actif) setValeurs(initiales(section, item));
      } catch (e) {
        if (actif) setChargementErreur(messageErreur(e));
      } finally {
        if (actif) setChargement(false);
      }
    })();
    return () => {
      actif = false;
    };
  }, [section, edition, membreId, itemId]);

  const setVal = (cle: string, v: unknown) => setValeurs((prev) => ({ ...prev, [cle]: v }));

  if (!section) {
    return (
      <Screen>
        <ScreenHeader title="Carnet" onBack={() => router.back()} />
        <Text style={styles.erreur}>Section inconnue.</Text>
      </Screen>
    );
  }

  const soumettre = async () => {
    const errs = validerTout(section, valeurs);
    setErreurs(errs);
    if (Object.values(errs).some((v) => v)) return;

    const payload = construirePayload(section, valeurs);
    setErreurServeur(null);
    setEnvoi(true);
    try {
      if (edition) {
        await modifierItem(membreId, section.chemin, itemId!, payload);
      } else {
        await creerItem(membreId, section.chemin, payload);
      }
      router.back();
    } catch (e) {
      setErreurServeur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <Screen>
      <ScreenHeader
        title={edition ? 'Modifier' : 'Ajouter'}
        subtitle={[section.titre, nomMembre].filter(Boolean).join(' · ')}
        onBack={() => router.back()}
      />

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : chargementErreur ? (
        <Text style={styles.erreur}>{chargementErreur}</Text>
      ) : (
        <>
          <Card style={styles.carte}>
            {section.champs.map((champ) => (
              <ChampVue
                key={champ.cle}
                champ={champ}
                valeur={valeurs[champ.cle]}
                erreur={erreurs[champ.cle]}
                onChange={(v) => setVal(champ.cle, v)}
              />
            ))}
          </Card>

          {erreurServeur ? <Text style={styles.erreurServeur}>{erreurServeur}</Text> : null}

          <PrimaryButton
            label={edition ? 'Enregistrer' : 'Ajouter'}
            onPress={soumettre}
            loading={envoi}
          />
        </>
      )}
    </Screen>
  );
}

/** Rendu d'un champ selon son kind. */
function ChampVue({
  champ,
  valeur,
  erreur,
  onChange,
}: {
  champ: Champ;
  valeur: unknown;
  erreur?: string | null;
  onChange: (v: unknown) => void;
}) {
  switch (champ.kind) {
    case 'texte':
      return (
        <TextField
          label={libelle(champ)}
          value={(valeur as string) ?? ''}
          onChangeText={onChange}
          multiline={champ.multiligne}
          maxLength={champ.max}
          autoCapitalize={champ.autoCap ?? 'sentences'}
          erreur={erreur}
        />
      );
    case 'date':
      return (
        <TextField
          label={libelle(champ)}
          value={(valeur as string) ?? ''}
          onChangeText={onChange}
          placeholder="AAAA-MM-JJ"
          keyboardType="numbers-and-punctuation"
          maxLength={10}
          erreur={erreur}
        />
      );
    case 'heure':
      return (
        <TextField
          label={libelle(champ)}
          value={(valeur as string) ?? ''}
          onChangeText={onChange}
          placeholder="HH:MM"
          keyboardType="numbers-and-punctuation"
          maxLength={5}
          erreur={erreur}
        />
      );
    case 'select':
      return (
        <View style={styles.bloc}>
          <Text style={styles.label}>{libelle(champ)}</Text>
          <View style={styles.chips}>
            {champ.options.map((o) => (
              <Chip
                key={o.value}
                label={o.label}
                selected={valeur === o.value}
                onPress={() => onChange(valeur === o.value && !champ.obligatoire ? '' : o.value)}
              />
            ))}
          </View>
          {erreur ? <Text style={styles.champErreur}>{erreur}</Text> : null}
        </View>
      );
    case 'booleen':
      return (
        <View style={styles.bloc}>
          <Text style={styles.label}>{champ.label}</Text>
          <Segmented
            options={[
              { value: true, label: 'Oui' },
              { value: false, label: 'Non' },
            ]}
            value={valeur === true}
            onChange={onChange}
            accessibilityLabel={champ.label}
          />
        </View>
      );
    case 'medicaments':
      return (
        <RepeaterMedicaments
          label={libelle(champ)}
          rows={(valeur as Medicament[]) ?? []}
          onChange={onChange}
          erreur={erreur}
        />
      );
    case 'resultats':
      return (
        <RepeaterResultats
          label={libelle(champ)}
          rows={(valeur as ParametreResultat[]) ?? []}
          onChange={onChange}
        />
      );
  }
}

/** Répéteur de médicaments (nom obligatoire + posologie). */
function RepeaterMedicaments({
  label,
  rows,
  onChange,
  erreur,
}: {
  label: string;
  rows: Medicament[];
  onChange: (v: Medicament[]) => void;
  erreur?: string | null;
}) {
  const maj = (idx: number, champ: keyof Medicament, val: string) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, [champ]: val } : r)));
  const ajouter = () => onChange([...rows, { nom: '', posologie: '' }]);
  const retirer = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  return (
    <View style={styles.bloc}>
      <Text style={styles.label}>{label}</Text>
      {rows.map((r, idx) => (
        <View key={idx} style={styles.repeaterRow}>
          <TextField label={`Médicament ${idx + 1}`} value={r.nom} onChangeText={(t) => maj(idx, 'nom', t)} autoCapitalize="sentences" placeholder="Nom" />
          <TextField label="Posologie" value={r.posologie ?? ''} onChangeText={(t) => maj(idx, 'posologie', t)} placeholder="ex. 1 cp matin et soir" autoCapitalize="sentences" />
          {rows.length > 1 ? (
            <Pressable onPress={() => retirer(idx)} accessibilityRole="button" accessibilityLabel={`Retirer le médicament ${idx + 1}`} style={styles.retirer}>
              <Ionicons name="trash-outline" size={18} color={colors.danger.text} />
              <Text style={styles.retirerTxt}>Retirer</Text>
            </Pressable>
          ) : null}
        </View>
      ))}
      {erreur ? <Text style={styles.champErreur}>{erreur}</Text> : null}
      <View style={styles.ajouterLigne}>
        <SecondaryButton label="Ajouter un médicament" onPress={ajouter} />
      </View>
    </View>
  );
}

/** Répéteur paramètre/valeur (résultats d'analyse, facultatif). */
function RepeaterResultats({
  label,
  rows,
  onChange,
}: {
  label: string;
  rows: ParametreResultat[];
  onChange: (v: ParametreResultat[]) => void;
}) {
  const maj = (idx: number, champ: keyof ParametreResultat, val: string) =>
    onChange(rows.map((r, i) => (i === idx ? { ...r, [champ]: val } : r)));
  const ajouter = () => onChange([...rows, { parametre: '', valeur: '' }]);
  const retirer = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  return (
    <View style={styles.bloc}>
      <Text style={styles.label}>{label}</Text>
      {rows.length === 0 ? <Text style={styles.aide}>Aucune ligne. Ajoutez vos résultats si besoin.</Text> : null}
      {rows.map((r, idx) => (
        <View key={idx} style={styles.repeaterRow}>
          <TextField label="Paramètre" value={r.parametre} onChangeText={(t) => maj(idx, 'parametre', t)} placeholder="ex. Glycémie" autoCapitalize="sentences" />
          <TextField label="Valeur" value={r.valeur} onChangeText={(t) => maj(idx, 'valeur', t)} placeholder="ex. 0.95 g/L" autoCapitalize="none" />
          <Pressable onPress={() => retirer(idx)} accessibilityRole="button" accessibilityLabel={`Retirer la ligne ${idx + 1}`} style={styles.retirer}>
            <Ionicons name="trash-outline" size={18} color={colors.danger.text} />
            <Text style={styles.retirerTxt}>Retirer</Text>
          </Pressable>
        </View>
      ))}
      <View style={styles.ajouterLigne}>
        <SecondaryButton label="Ajouter une ligne" onPress={ajouter} />
      </View>
    </View>
  );
}

// --- Helpers (hors composant) ---

const libelle = (c: Champ) => (c.obligatoire ? `${c.label} *` : c.label);

/** Construit l'état initial du formulaire à partir d'un item (édition) ou des défauts. */
function initiales(section: SectionDescriptor, item: Record<string, unknown> | null): Record<string, unknown> {
  const v: Record<string, unknown> = {};
  for (const c of section.champs) {
    const brut = item?.[c.cle];
    switch (c.kind) {
      case 'texte':
      case 'select':
        v[c.cle] = typeof brut === 'string' ? brut : '';
        break;
      case 'date':
        v[c.cle] = isoVersDateInput(typeof brut === 'string' ? brut : '');
        break;
      case 'heure':
        v[c.cle] = heureCourte(typeof brut === 'string' ? brut : '');
        break;
      case 'booleen':
        v[c.cle] = item ? brut === true : c.defaut ?? false;
        break;
      case 'medicaments': {
        const arr = Array.isArray(brut) ? (brut as Medicament[]) : [];
        v[c.cle] = arr.length ? arr.map((m) => ({ nom: m.nom ?? '', posologie: m.posologie ?? '' })) : [{ nom: '', posologie: '' }];
        break;
      }
      case 'resultats': {
        const arr = Array.isArray(brut) ? (brut as ParametreResultat[]) : [];
        v[c.cle] = arr.map((r) => ({ parametre: r.parametre ?? '', valeur: r.valeur ?? '' }));
        break;
      }
    }
  }
  return v;
}

/** Valide tous les champs ; renvoie une map cle -> message (ou null). */
function validerTout(section: SectionDescriptor, valeurs: Record<string, unknown>): Record<string, string | null> {
  const errs: Record<string, string | null> = {};
  for (const c of section.champs) {
    const val = valeurs[c.cle];
    switch (c.kind) {
      case 'texte':
      case 'select':
        errs[c.cle] = c.obligatoire && !String(val ?? '').trim() ? 'Ce champ est obligatoire.' : null;
        break;
      case 'date': {
        let e = validerDate(String(val ?? ''), { obligatoire: c.obligatoire, futurInterdit: c.futurInterdit });
        if (!e && c.apresChamp && String(val ?? '').trim()) {
          const ref = String(valeurs[c.apresChamp] ?? '').trim();
          if (ref && String(val).trim() < ref) e = 'La date de fin doit suivre la date de début.';
        }
        errs[c.cle] = e;
        break;
      }
      case 'heure':
        errs[c.cle] = validerHeure(String(val ?? ''), c.obligatoire);
        break;
      case 'medicaments': {
        const arr = (val as Medicament[]) ?? [];
        errs[c.cle] = c.obligatoire && !arr.some((m) => m.nom.trim()) ? 'Ajoutez au moins un médicament.' : null;
        break;
      }
      default:
        errs[c.cle] = null;
    }
  }
  return errs;
}

/** Construit le payload API à partir des valeurs validées. */
function construirePayload(section: SectionDescriptor, valeurs: Record<string, unknown>): Record<string, unknown> {
  const p: Record<string, unknown> = {};
  for (const c of section.champs) {
    const val = valeurs[c.cle];
    switch (c.kind) {
      case 'texte': {
        const t = String(val ?? '').trim();
        p[c.cle] = c.obligatoire ? t : t || null;
        break;
      }
      case 'select':
        p[c.cle] = String(val ?? '') || null;
        break;
      case 'date':
      case 'heure':
        p[c.cle] = String(val ?? '').trim() || null;
        break;
      case 'booleen':
        p[c.cle] = val === true;
        break;
      case 'medicaments': {
        const arr = (val as Medicament[]) ?? [];
        p[c.cle] = arr
          .filter((m) => m.nom.trim())
          .map((m) => (m.posologie?.trim() ? { nom: m.nom.trim(), posologie: m.posologie.trim() } : { nom: m.nom.trim() }));
        break;
      }
      case 'resultats': {
        const arr = (val as ParametreResultat[]) ?? [];
        const nettoye = arr.filter((r) => r.parametre.trim()).map((r) => ({ parametre: r.parametre.trim(), valeur: r.valeur.trim() }));
        p[c.cle] = nettoye.length ? nettoye : null;
        break;
      }
    }
  }
  if (section.ajoutParPatient) p.added_by = 'patient';
  return p;
}

const styles = StyleSheet.create({
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  erreurServeur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[3] },
  carte: { marginBottom: spacing[5] },
  bloc: { marginBottom: spacing[4] },
  label: { ...typography.bodyStrong, color: colors.ink[700], marginBottom: spacing[2] },
  aide: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[2] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing[2] },
  champErreur: { ...typography.caption, color: colors.danger.text, marginTop: spacing[1] },
  repeaterRow: { borderTopWidth: 1, borderTopColor: colors.line, paddingTop: spacing[3], marginTop: spacing[2] },
  retirer: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start', paddingVertical: spacing[1] },
  retirerTxt: { ...typography.caption, color: colors.danger.text, marginLeft: spacing[1], fontWeight: '700' },
  ajouterLigne: { marginTop: spacing[3] },
});
