import React from 'react';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../../src/theme/theme';

/**
 * Navigation basse à onglets (§5.6 Design System) : barre blanche fixe, onglet actif
 * en blue-600, inactifs en ink-500, icône + libellé (redondance §6). L'onglet « Carte »
 * (géolocalisation) arrive au Module 3 — on n'anticipe pas.
 */
export default function AppTabsLayout() {
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
      {/* Pile « Membres » (détail / formulaires) : navigable mais hors barre d'onglets. */}
      <Tabs.Screen name="membres" options={{ href: null }} />
    </Tabs>
  );
}
