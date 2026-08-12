import React, { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Screen } from '../../src/components/Screen';
import { Card } from '../../src/components/Card';
import { PrimaryButton } from '../../src/components/PrimaryButton';
import { SecondaryButton } from '../../src/components/SecondaryButton';
import { useSession } from '../../src/auth/SessionContext';
import { useT } from '../../src/i18n/useT';
import { listerMembres } from '../../src/api/membres';
import { etatDossierTitulaire } from '../../src/api/titulaire';
import { listerCarnetsPartages } from '../../src/api/delegations';
import { listerCarnetsRevendicables } from '../../src/api/revendication';
import { contributionsEnAttente } from '../../src/api/contributions';
import { messageErreur } from '../../src/utils/erreurs';
import { MAX_MEMBRES, type Membre } from '../../src/types/membre';
import type { CarnetPartage } from '../../src/types/delegation';
import { calculerAge } from '../../src/utils/dates';
import { colors, radius, spacing, typography } from '../../src/theme/theme';

/**
 * Onglet « Carnet » — profil du compte + membres de la famille (F2.1).
 * La liste est rechargée à chaque retour sur l'onglet (création / édition / suppression).
 * Les sections du dossier (antécédents, vaccins…) arriveront à une étape ultérieure.
 */
export default function CarnetTab() {
  const { user, roles, signOut } = useSession();
  const t = useT();
  const [membres, setMembres] = useState<Membre[]>([]);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState<string | null>(null);
  const [deconnexion, setDeconnexion] = useState(false);
  // P6.1 — l'existence du dossier de santé du titulaire est une réponse du BACKEND
  // (ADR-021 §2.1) ; on ne la déduit jamais de la liste des membres. `null` = pas encore su.
  const [dossierTitulaireExiste, setDossierTitulaireExiste] = useState<boolean | null>(null);
  // Carnet familial partagé (A) — carnets qu'un proche m'a délégués. Liste SÉPARÉE de `membres` :
  // ils ne m'appartiennent pas, ils ne comptent pas dans mon quota, et je ne peux pas les modifier.
  const [partages, setPartages] = useState<CarnetPartage[]>([]);
  // Incrément B — nombre de carnets qu'un proche a désignés comme étant celui de ce compte.
  // Le backend ne renvoie quelque chose que s'il n'y a pas encore de dossier titulaire.
  const [aRevendiquer, setARevendiquer] = useState(0);
  // Incrément C — ajouts proposés par des proches, en attente de MA décision.
  const [enAttente, setEnAttente] = useState(0);

  useFocusEffect(
    useCallback(() => {
      let actif = true;
      (async () => {
        setErreur(null);
        try {
          const [liste, etat, recus, revendicables, aValider] = await Promise.all([
            listerMembres(),
            etatDossierTitulaire(),
            listerCarnetsPartages(),
            listerCarnetsRevendicables(),
            contributionsEnAttente(),
          ]);
          if (actif) {
            setMembres(liste);
            setDossierTitulaireExiste(etat.existe);
            setPartages(recus);
            setARevendiquer(revendicables.length);
            setEnAttente(aValider.length);
          }
        } catch (e) {
          if (actif) setErreur(messageErreur(e));
        } finally {
          if (actif) setChargement(false);
        }
      })();
      return () => {
        actif = false;
      };
    }, []),
  );

  const seDeconnecter = async () => {
    setDeconnexion(true);
    await signOut(); // redirection automatique vers (auth).
  };

  const verifie = user?.niveau_compte === 'verifie';

  // P6.1 — le dossier du titulaire est HORS QUOTA (ADR-021 §2.1) : il n'entre ni dans le
  // compteur, ni dans la limite. Il est présenté à part, c'est le dossier du propriétaire.
  const dossierPersonnel = membres.find((m) => m.est_titulaire) ?? null;
  const membresFamille = membres.filter((m) => !m.est_titulaire);
  const plafondAtteint = membresFamille.length >= MAX_MEMBRES;

  // Complétion requise : le backend dit « pas de dossier ». Tant que ce n'est pas fait, on
  // n'affiche ni la famille ni l'ajout — le carnet n'a pas encore de titulaire à rattacher.
  const completionRequise = !chargement && dossierTitulaireExiste === false;

  return (
    <Screen>
      <Text style={styles.titre}>Mon carnet</Text>

      <Card style={styles.compte}>
        <Text style={styles.nomCompte}>
          {user?.prenom} {user?.nom}
        </Text>
        <Text style={styles.tel}>{user?.telephone}</Text>
        {roles.length > 0 ? (
          <Text style={styles.role}>{roles.map((r) => t.roles[r]).join(' · ')}</Text>
        ) : null}
        <View style={[styles.statut, { backgroundColor: verifie ? colors.success.bg : colors.warning.bg }]}>
          <Text style={[styles.statutTxt, { color: verifie ? colors.success.text : colors.warning.text }]}>
            {verifie ? '✓ Compte vérifié' : '● Compte de base'}
          </Text>
        </View>
      </Card>

      {chargement ? (
        <ActivityIndicator color={colors.blue[600]} style={styles.loader} />
      ) : erreur ? (
        <Text style={styles.erreur}>{erreur}</Text>
      ) : completionRequise && aRevendiquer > 0 ? (
        /* Incrément B — un proche a déjà créé un carnet à ce nom. On propose de le RECONNAÎTRE
           avant d'en créer un second : après, il y aurait deux NIS, et un NIS ne se libère
           jamais (P6.1). C'est ici, et nulle part ailleurs, que le doublon est empêché. */
        <Card style={styles.completion}>
          <Ionicons name="person-circle-outline" size={32} color={colors.blue[500]} />
          <Text style={styles.completionTitre}>Un carnet à votre nom existe déjà</Text>
          <Text style={styles.completionTxt}>
            {aRevendiquer === 1
              ? "Un proche a créé un dossier de santé à votre nom. S'il est bien le vôtre, reconnaissez-le plutôt que d'en créer un second."
              : `${aRevendiquer} proches ont créé un dossier de santé à votre nom. Reconnaissez le vôtre plutôt que d'en créer un second.`}
          </Text>
          <PrimaryButton
            label="Voir ce carnet"
            onPress={() => router.push('/(app)/revendiquer-carnet')}
          />
        </Card>
      ) : completionRequise ? (
        /* P6.1 — porte d'entrée : sans dossier titulaire, pas de carnet (ADR-021 §2.1). */
        <Card style={styles.completion}>
          <Ionicons name="shield-checkmark-outline" size={32} color={colors.blue[500]} />
          <Text style={styles.completionTitre}>Créez votre dossier de santé</Text>
          <Text style={styles.completionTxt}>
            Il vous manque deux informations pour ouvrir votre carnet et recevoir votre numéro
            national de santé.
          </Text>
          <PrimaryButton
            label="Compléter mon profil"
            onPress={() => router.push('/(app)/profil-titulaire')}
          />
        </Card>
      ) : (
        <>
          {dossierPersonnel ? (
            <>
              <View style={styles.enteteSection}>
                <Text style={styles.section}>Mon dossier de santé</Text>
              </View>
              <View style={styles.liste}>
                <MembreItem membre={dossierPersonnel} />
              </View>
            </>
          ) : null}

          <View style={styles.enteteSection}>
            <Text style={styles.section}>Membres de la famille</Text>
            <Text style={styles.compteur}>
              {membresFamille.length}/{MAX_MEMBRES}
            </Text>
          </View>

          {membresFamille.length === 0 ? (
            <Card style={styles.vide}>
              <Ionicons name="people-outline" size={32} color={colors.blue[400]} />
              <Text style={styles.videTxt}>Aucun membre pour l'instant.</Text>
              <Text style={styles.videSous}>
                Ajoutez vos proches pour gérer leur carnet de santé.
              </Text>
            </Card>
          ) : (
            <View style={styles.liste}>
              {membresFamille.map((m) => (
                <MembreItem key={m.id} membre={m} />
              ))}
            </View>
          )}

          <View style={styles.ajout}>
            <PrimaryButton
              label="Ajouter un membre"
              onPress={() => router.push('/(app)/membres/nouveau')}
              disabled={plafondAtteint}
            />
            {plafondAtteint ? (
              <Text style={styles.plafond}>Limite de {MAX_MEMBRES} membres atteinte.</Text>
            ) : null}
            <View style={styles.sep} />
            <SecondaryButton
              label="Partager mes carnets"
              onPress={() => router.push('/(app)/partager-carnets')}
            />
          </View>

          {/* Carnet familial partagé (A) — section distincte : ces carnets ne m'appartiennent
              pas. On indique toujours QUI les a partagés, jamais d'ambiguïté sur l'origine. */}
          {partages.length > 0 ? (
            <>
              <View style={styles.enteteSection}>
                <Text style={styles.section}>Carnets partagés avec moi</Text>
                <Text style={styles.compteur}>{partages.length}</Text>
              </View>
              <View style={styles.liste}>
                {partages.map((p) => (
                  <MembreItem
                    key={`partage-${p.delegation_id}`}
                    membre={p.membre}
                    origine={`Partagé par ${p.partage_par.prenom ?? ''} ${p.partage_par.nom ?? ''}`.trim()}
                  />
                ))}
              </View>
            </>
          ) : null}
        </>
      )}

      <View style={styles.deconnexion}>
        {/* Carnet familial partagé (C) — la file du responsable. Le compteur vient du backend :
            on ne déduit jamais localement ce qui est en attente. */}
        <SecondaryButton
          label={
            enAttente > 0 ? `Ajouts à valider (${enAttente})` : 'Ajouts à valider'
          }
          onPress={() => router.push('/(app)/contributions')}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Partages reçus" onPress={() => router.push('/(app)/partages')} />
        <View style={styles.sep} />
        <SecondaryButton
          label="Carte vitale d'urgence"
          onPress={() => router.push('/(app)/parametres/carte-vitale')}
        />
        <View style={styles.sep} />
        <SecondaryButton
          label="Mes alertes d'urgence"
          onPress={() => router.push('/(app)/parametres/alertes-sos')}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Sécurité" onPress={() => router.push('/(app)/parametres/securite')} />
        <View style={styles.sep} />
        <SecondaryButton
          label="Double authentification"
          onPress={() => router.push('/(app)/parametres/mfa')}
        />
        <View style={styles.sep} />
        <SecondaryButton
          label="Changer mon mot de passe"
          onPress={() => router.push('/(app)/parametres/mot-de-passe')}
        />
        <View style={styles.sep} />
        <SecondaryButton label="Se déconnecter" onPress={seDeconnecter} disabled={deconnexion} />
      </View>
    </Screen>
  );
}

/**
 * Carte cliquable d'un membre dans la liste.
 * `origine` (facultatif) : d'où vient ce carnet quand il ne m'appartient pas — le propriétaire
 * tient à ce qu'on sache toujours qui a partagé quoi.
 */
function MembreItem({ membre, origine }: { membre: Membre; origine?: string }) {
  const age = calculerAge(membre.date_naissance);
  const initiales = `${membre.prenom?.[0] ?? ''}${membre.nom?.[0] ?? ''}`.toUpperCase();

  return (
    <Pressable
      onPress={() => router.push({ pathname: '/(app)/membres/[id]', params: { id: membre.id } })}
      accessibilityRole="button"
      accessibilityLabel={
        origine ? `${membre.prenom} ${membre.nom}, ${origine}` : `${membre.prenom} ${membre.nom}`
      }
    >
      <Card style={styles.item}>
        <View style={[styles.avatar, origine ? styles.avatarPartage : null]}>
          <Text style={styles.avatarTxt}>{initiales}</Text>
        </View>
        <View style={styles.itemTexte}>
          <Text style={styles.itemNom}>
            {membre.prenom} {membre.nom}
          </Text>
          <Text style={styles.itemSous}>
            {age !== null ? `${age} ans` : '—'} · {membre.sexe === 'M' ? 'Masculin' : 'Féminin'}
            {membre.groupe_sanguin ? ` · ${membre.groupe_sanguin}` : ''}
          </Text>
          {origine ? <Text style={styles.itemOrigine}>{origine}</Text> : null}
        </View>
        <Ionicons name="chevron-forward" size={20} color={colors.ink[500]} />
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  titre: { ...typography.h1, color: colors.blue[900], marginBottom: spacing[5] },
  compte: { marginBottom: spacing[6] },
  nomCompte: { ...typography.h2, color: colors.blue[900] },
  tel: { ...typography.body, color: colors.ink[700], marginTop: spacing[1] },
  role: { ...typography.caption, color: colors.blue[700], marginTop: spacing[1], fontWeight: '700' },
  statut: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing[3], paddingVertical: spacing[1], marginTop: spacing[3] },
  statutTxt: { ...typography.caption, fontWeight: '700' },
  enteteSection: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing[3] },
  section: { ...typography.h2, color: colors.blue[900] },
  compteur: { ...typography.bodyStrong, color: colors.ink[500] },
  loader: { marginTop: spacing[5], marginBottom: spacing[5] },
  erreur: { ...typography.bodyStrong, color: colors.danger.text, marginBottom: spacing[5] },
  // P6.1 — carte de complétion du profil titulaire.
  completion: { alignItems: 'center', gap: spacing[2], marginBottom: spacing[5] },
  completionTitre: { ...typography.bodyStrong, color: colors.ink[900], marginTop: spacing[2] },
  completionTxt: {
    ...typography.caption,
    color: colors.ink[500],
    textAlign: 'center',
    marginBottom: spacing[2],
  },
  vide: { alignItems: 'center', marginBottom: spacing[5] },
  videTxt: { ...typography.bodyStrong, color: colors.ink[700], marginTop: spacing[3] },
  videSous: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[1] },
  liste: { gap: spacing[3], marginBottom: spacing[5] },
  item: { flexDirection: 'row', alignItems: 'center' },
  avatar: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.blue[100],
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing[3],
  },
  // Carnet partagé : pastille d'une autre teinte, pour distinguer d'un coup d'œil ce qui
  // m'appartient de ce qu'on m'a confié.
  avatarPartage: { backgroundColor: colors.blue[50] },
  avatarTxt: { ...typography.bodyStrong, color: colors.blue[700] },
  itemTexte: { flex: 1 },
  itemNom: { ...typography.bodyStrong, color: colors.blue[900] },
  itemSous: { ...typography.caption, color: colors.ink[700], marginTop: 2 },
  itemOrigine: { ...typography.caption, color: colors.blue[700], marginTop: 2 },
  ajout: { marginBottom: spacing[6] },
  plafond: { ...typography.caption, color: colors.ink[500], textAlign: 'center', marginTop: spacing[2] },
  deconnexion: {},
  sep: { height: spacing[3] },
});
