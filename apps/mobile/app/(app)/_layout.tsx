import React, { useEffect } from 'react';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { enregistrerCetAppareil } from '../../src/push/enregistrement';
import { colors } from '../../src/theme/theme';

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
