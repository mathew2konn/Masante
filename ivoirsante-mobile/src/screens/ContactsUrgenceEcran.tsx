import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../components/Screen';
import { ScreenHeader } from '../components/ScreenHeader';
import { Card } from '../components/Card';
import { TextField } from '../components/TextField';
import { PrimaryButton } from '../components/PrimaryButton';
import { LIEN_PARENTE } from '../carnet/registre';
import { creerItem, listerSection, modifierItem } from '../api/carnet';
import { messageErreur } from '../utils/erreurs';
import type { CarnetItem } from '../types/carnet';
import { colors, radius, spacing, typography } from '../theme/theme';

/**
 * ContactsUrgenceEcran (F2.11) — écran DÉDIÉ, hors moteur générique du carnet.
 *
 * Deux blocs dépliables : « Premier contact » (= principal) puis « Second contact » (= secondaire),
 * le rôle découlant de l'ordre d'enregistrement (assigné côté serveur). Réutilise l'API carnet
 * générique par `chemin`. L'indicatif +225 n'est jamais affiché : la saisie est locale (10 chiffres),
 * l'indicatif est ajouté/retiré côté logique. La photo par contact arrivera avec le stockage
 * sécurisé (F2.10) — placeholder désactivé pour l'instant.
 */

const CHEMIN = 'contacts-urgence';
const LIENS = Object.entries(LIEN_PARENTE).map(([value, label]) => ({ value, label }));

type Formulaire = {
  id?: number;
  nom: string;
  lien_parente: string;
  telephone: string; // saisie LOCALE (10 chiffres, sans +225) — un seul numéro par contact
};

type ErreursContact = { nom?: string; telephone?: string };

const vide = (): Formulaire => ({ nom: '', lien_parente: 'papa', telephone: '' });

/** Ne conserve que les chiffres, plafonnés à 10 (numéro ivoirien). */
const chiffres = (v: string) => v.replace(/\D/g, '').slice(0, 10);

/** Retire l'indicatif +225 pour l'affichage (le backend le stocke, on ne l'affiche pas). */
function versLocal(tel?: string | null): string {
  const s = (tel ?? '').replace(/\s/g, '');
  return chiffres(s.startsWith('+225') ? s.slice(4) : s);
}

/** Rebâtit le format backend (+225 + 10 chiffres) à partir de la saisie locale. */
const versE164 = (local: string) => '+225' + chiffres(local);

const telValide = (local: string) => /^[0-9]{10}$/.test(chiffres(local));

/** Transforme un contact reçu du backend en formulaire éditable. */
function depuisItem(i: CarnetItem): Formulaire {
  const str = (v: unknown) => (typeof v === 'string' ? v : '');
  return {
    id: typeof i.id === 'number' ? i.id : undefined,
    nom: str(i.nom),
    lien_parente: LIEN_PARENTE[str(i.lien_parente)] ? str(i.lien_parente) : 'papa',
    telephone: versLocal(str(i.telephone)),
  };
}

export function ContactsUrgenceEcran({ membreId, nomMembre }: { membreId: number; nomMembre?: string }) {
  const [c1, setC1] = useState<Formulaire>(vide());
  const [c2, setC2] = useState<Formulaire>(vide());
  const [secondVisible, setSecondVisible] = useState(false);
  const [ouvert1, setOuvert1] = useState(true);
  const [ouvert2, setOuvert2] = useState(true);

  const [errs, setErrs] = useState<{ c1: ErreursContact; c2: ErreursContact }>({ c1: {}, c2: {} });
  const [chargement, setChargement] = useState(true);
  const [chargementErreur, setChargementErreur] = useState<string | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreurServeur, setErreurServeur] = useState<string | null>(null);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setChargement(true);
        setChargementErreur(null);
        try {
          const items = await listerSection(membreId, CHEMIN);
          if (!actif) return;
          const principal = items.find((i) => i.est_principal === true);
          const secondaire = items.find((i) => i.est_principal !== true);
          setC1(principal ? depuisItem(principal) : vide());
          setC2(secondaire ? depuisItem(secondaire) : vide());
          setSecondVisible(!!secondaire);
        } catch (e) {
          if (actif) setChargementErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, [membreId]),
  );

  // Le second contact est « actif » (donc à valider/enregistrer) s'il existe déjà ou s'il est amorcé.
  const secondActif = secondVisible && (c2.id != null || c2.nom.trim() !== '' || chiffres(c2.telephone) !== '');

  const validerContact = (f: Formulaire): ErreursContact => ({
    nom: f.nom.trim() ? undefined : 'Le nom est obligatoire.',
    telephone: telValide(f.telephone) ? undefined : 'Numéro invalide (10 chiffres).',
  });

  const persister = async (f: Formulaire) => {
    const payload = {
      nom: f.nom.trim(),
      lien_parente: f.lien_parente,
      telephone: versE164(f.telephone),
    };
    if (f.id != null) await modifierItem(membreId, CHEMIN, f.id, payload);
    else await creerItem(membreId, CHEMIN, payload);
  };

  const enregistrer = async () => {
    const e1 = validerContact(c1);
    const e2: ErreursContact = secondActif ? validerContact(c2) : {};
    if (secondActif && !e1.telephone && !e2.telephone && chiffres(c1.telephone) === chiffres(c2.telephone)) {
      e2.telephone = 'Les deux contacts doivent avoir des numéros différents.';
    }
    setErrs({ c1: e1, c2: e2 });
    if (e1.nom || e1.telephone || e2.nom || e2.telephone) {
      if (e2.nom || e2.telephone) setOuvert2(true);
      return;
    }

    setErreurServeur(null);
    setEnvoi(true);
    try {
      await persister(c1); // enregistré en premier → contact principal
      if (secondActif) await persister(c2); // → contact secondaire
      router.back();
    } catch (e) {
      setErreurServeur(messageErreur(e));
    } finally {
      setEnvoi(false);
    }
  };

  if (chargement) {
    return (
      <Screen>
        <ScreenHeader title="Contacts d'urgence" onBack={() => router.back()} />
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      </Screen>
    );
  }

  if (chargementErreur) {
    return (
      <Screen>
        <ScreenHeader title="Contacts d'urgence" onBack={() => router.back()} />
        <Text style={styles.erreur}>{chargementErreur}</Text>
      </Screen>
    );
  }

  return (
    <Screen
      footer={
        <>
          {erreurServeur ? <Text style={styles.erreurServeur}>{erreurServeur}</Text> : null}
          <PrimaryButton label="Ajouter" onPress={enregistrer} loading={envoi} />
        </>
      }
    >
      <ScreenHeader
        title="Contacts d'urgence"
        subtitle={nomMembre ? `Dossier de ${nomMembre}` : 'Personnes à prévenir'}
        onBack={() => router.back()}
      />

      <BlocContact
        numero={1}
        titre="Premier contact"
        aide="Ce contact sera le contact principal."
        ouvert={ouvert1}
        onToggle={() => setOuvert1((v) => !v)}
        valeurs={c1}
        erreurs={errs.c1}
        onChange={setC1}
        bas={
          !secondVisible ? (
            <Pressable
              onPress={() => {
                setSecondVisible(true);
                setOuvert2(true);
              }}
              accessibilityRole="button"
              style={styles.lienBas}
            >
              <Text style={styles.lienBasTxt}>Passer au second contact</Text>
              <Ionicons name="arrow-forward" size={16} color={colors.blue[600]} />
            </Pressable>
          ) : null
        }
      />

      {secondVisible ? (
        <BlocContact
          numero={2}
          titre="Second contact"
          aide="Ce contact sera le contact secondaire."
          ouvert={ouvert2}
          onToggle={() => setOuvert2((v) => !v)}
          valeurs={c2}
          erreurs={errs.c2}
          onChange={setC2}
        />
      ) : null}
    </Screen>
  );
}

/** Un bloc dépliable de contact (en-tête numéroté + formulaire). */
function BlocContact({
  numero,
  titre,
  aide,
  ouvert,
  onToggle,
  valeurs,
  erreurs,
  onChange,
  bas,
}: {
  numero: number;
  titre: string;
  aide: string;
  ouvert: boolean;
  onToggle: () => void;
  valeurs: Formulaire;
  erreurs: ErreursContact;
  onChange: (f: Formulaire) => void;
  bas?: React.ReactNode;
}) {
  const maj = (patch: Partial<Formulaire>) => onChange({ ...valeurs, ...patch });

  return (
    <Card style={styles.bloc}>
      <Pressable onPress={onToggle} accessibilityRole="button" style={styles.blocHeader}>
        <View style={styles.numero}>
          <Text style={styles.numeroTxt}>{numero}</Text>
        </View>
        <Text style={styles.blocTitre}>{titre}</Text>
        <Ionicons name={ouvert ? 'chevron-up' : 'chevron-down'} size={20} color={colors.ink[500]} />
      </Pressable>

      {ouvert ? (
        <View style={styles.blocCorps}>
          <Text style={styles.blocAide}>{aide}</Text>

          <View style={styles.photoRow}>
            <View style={styles.photoCercle}>
              <Ionicons name="person-outline" size={24} color={colors.ink[500]} />
            </View>
            <View style={styles.photoTxt}>
              <Text style={styles.photoTitre}>Photo du contact</Text>
              <Text style={styles.photoAide}>Bientôt disponible (identification rapide).</Text>
            </View>
          </View>

          <TextField
            label="Nom complet"
            value={valeurs.nom}
            onChangeText={(t) => maj({ nom: t })}
            placeholder="Ex : Jean Dupont"
            autoCapitalize="words"
            maxLength={200}
            erreur={erreurs.nom}
          />

          <Dropdown
            label="Lien de parenté"
            value={valeurs.lien_parente}
            onSelect={(v) => maj({ lien_parente: v })}
          />

          <TextField
            label="Numéro de téléphone"
            value={valeurs.telephone}
            onChangeText={(t) => maj({ telephone: chiffres(t) })}
            placeholder="Ex : 07 07 07 07 07"
            keyboardType="phone-pad"
            maxLength={10}
            erreur={erreurs.telephone}
          />

          {bas}
        </View>
      ) : null}
    </Card>
  );
}

/** Menu déroulant (liste fermée) — remplace la grille de puces pour le lien de parenté. */
function Dropdown({
  label,
  value,
  onSelect,
}: {
  label: string;
  value: string;
  onSelect: (v: string) => void;
}) {
  const [ouvert, setOuvert] = useState(false);
  const courant = LIEN_PARENTE[value] ?? 'Papa';

  return (
    <View style={styles.champ}>
      <Text style={styles.label}>{label}</Text>
      <Pressable
        onPress={() => setOuvert(true)}
        accessibilityRole="button"
        accessibilityLabel={`${label} : ${courant}`}
        style={styles.select}
      >
        <Text style={styles.selectTxt}>{courant}</Text>
        <Ionicons name="chevron-down" size={20} color={colors.ink[500]} />
      </Pressable>

      <Modal visible={ouvert} transparent animationType="fade" onRequestClose={() => setOuvert(false)}>
        <Pressable style={styles.backdrop} onPress={() => setOuvert(false)}>
          <View style={styles.menu}>
            <ScrollView keyboardShouldPersistTaps="handled">
              {LIENS.map((o) => {
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
  loader: { marginTop: spacing[8] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text },
  erreurServeur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[2], textAlign: 'center' },

  bloc: { marginBottom: spacing[4] },
  blocHeader: { flexDirection: 'row', alignItems: 'center' },
  numero: {
    width: 28,
    height: 28,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[600],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  numeroTxt: { ...typography.caption, fontWeight: '800', color: colors.surface },
  blocTitre: { ...typography.h2, color: colors.blue[900], flex: 1 },
  blocCorps: { marginTop: spacing[4] },
  blocAide: { ...typography.caption, color: colors.ink[500], marginBottom: spacing[4] },

  photoRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    padding: spacing[3],
    marginBottom: spacing[4],
  },
  photoCercle: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.line,
    borderStyle: 'dashed',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  photoTxt: { flex: 1 },
  photoTitre: { ...typography.bodyStrong, color: colors.ink[700] },
  photoAide: { ...typography.caption, color: colors.ink[500], marginTop: 2 },

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

  lienBas: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-end', paddingVertical: spacing[2] },
  lienBasTxt: { ...typography.bodyStrong, color: colors.blue[600], marginRight: spacing[1] },

  backdrop: { flex: 1, backgroundColor: 'rgba(12,52,99,0.35)', justifyContent: 'center', paddingHorizontal: spacing[6] },
  menu: { backgroundColor: colors.surface, borderRadius: radius.md, maxHeight: '70%', paddingVertical: spacing[1] },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
  },
  optionTxt: { ...typography.body, color: colors.ink[900] },
  optionActive: { ...typography.bodyStrong, color: colors.blue[700] },
});
