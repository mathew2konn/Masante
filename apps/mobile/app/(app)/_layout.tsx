import React, { useEffect } from 'react';
import { Alert } from 'react-native';
import { Tabs } from 'expo-router';
import * as Location from 'expo-location';
import { Ionicons } from '@expo/vector-icons';
import { enregistrerCetAppareil } from '../../src/push/enregistrement';
import { colors } from '../../src/theme/theme';
import { useLocalisation } from '../../src/store/localisation';

/**
 * Navigation basse à onglets (§5.6 Design System) : barre blanche fixe, onglet actif
 * en blue-600, inactifs en ink-500, icône + libellé (redondance §6). 4 onglets :
 * Accueil, Triage, Carnet, Carte (géolocalisation des structures, Module 3).
 */
export default function AppTabsLayout() {
  // Incrément D1 — une seule tentative, à l'entrée de la zone authentifiée. `enregistrerCetAppareil`
  // ne lève jamais et renvoie `null` quand le push n'est pas disponible : sous Expo Go Android,
  // c'est le cas NORMAL (SDK 53+). L'application n'en dépend pas.
  useEffect(() => {
    void enregistrerCetAppareil();
  }, []);

  // P6.4b — la ville de l'utilisateur est déterminée à l'entrée de la zone authentifiée, parce
  // que les contenus en dépendent (décision propriétaire du 2026-08-13).
  //
  // UNE EXPLICATION AVANT L'INVITE DU SYSTÈME, et seulement la première fois : une autorisation
  // demandée sans motif se refuse par réflexe, et sur Android comme sur iOS un refus est
  // difficile à revenir. On explique donc d'abord, on demande ensuite — et si la permission a
  // déjà été accordée ou refusée, on ne réexplique rien.
  //
  // Jamais bloquant : un refus mène au sélecteur de ville, pas à une impasse.
  useEffect(() => {
    void (async () => {
      const { status } = await Location.getForegroundPermissionsAsync();

      if (status !== 'undetermined') {
        await useLocalisation.getState().initialiser();
        return;
      }

      Alert.alert(
        'Votre ville',
        "MaSante affiche les établissements de votre ville. Autorisez la localisation pour "
          + 'la déterminer automatiquement — vous pourrez sinon la choisir vous-même.',
        [
          {
            text: 'Choisir moi-même',
            style: 'cancel',
            // On ne déclenche PAS l'invite du système : l'utilisateur a dit non à l'explication.
            // Lui imposer la boîte de dialogue native derrière produirait le refus définitif
            // qu'on cherche justement à éviter.
            onPress: () => useLocalisation.setState({ choixRequis: true }),
          },
          { text: 'Autoriser', onPress: () => void useLocalisation.getState().initialiser() },
        ],
      );
    })();
  }, []);

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.blue[600],
        tabBarInactiveTintColor: colors.ink[500],
        tabBarStyle: { backgroundColor: colors.surface, borderTopColor: colors.line },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Accueil',
          tabBarIcon: ({ color, size }) => <Ionicons name="home-outline" color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="triage"
        options={{
          title: 'Triage',
          tabBarIcon: ({ color, size }) => <Ionicons name="pulse-outline" color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="carnet"
        options={{
          title: 'Carnet',
          tabBarIcon: ({ color, size }) => <Ionicons name="folder-outline" color={color} size={size} />,
        }}
      />
      <Tabs.Screen
        name="carte"
        options={{
          title: 'Carte',
          tabBarIcon: ({ color, size }) => <Ionicons name="location-outline" color={color} size={size} />,
        }}
      />
      {/*
        Piles détail : navigables, mais HORS de la barre d'onglets.

        Toute route de ce dossier qui n'est pas déclarée ici avec `href: null` reçoit son propre
        onglet — la barre n'aurait plus quatre entrées mais douze, et le commentaire ci-dessus
        deviendrait faux. Les écrans ajoutés par le carnet familial partagé (A à D) sont donc
        listés ici au même titre que les anciens.
      */}
      <Tabs.Screen name="sos" options={{ href: null }} />
      <Tabs.Screen name="alertes" options={{ href: null }} />
      <Tabs.Screen name="membres" options={{ href: null }} />
      <Tabs.Screen name="structures" options={{ href: null }} />
      <Tabs.Screen name="parametres" options={{ href: null }} />
      <Tabs.Screen name="partages" options={{ href: null }} />
      <Tabs.Screen name="don-sang" options={{ href: null }} />
      <Tabs.Screen name="medicaments" options={{ href: null }} />
      <Tabs.Screen name="profil-titulaire" options={{ href: null }} />
      <Tabs.Screen name="partager-carnets" options={{ href: null }} />
      <Tabs.Screen name="revendiquer-carnet" options={{ href: null }} />
      <Tabs.Screen name="contributions" options={{ href: null }} />
      <Tabs.Screen name="notifications" options={{ href: null }} />
    </Tabs>
  );
}
