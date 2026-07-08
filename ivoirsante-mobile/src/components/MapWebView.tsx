import React, { useCallback, useMemo, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { colors, radius } from '../theme/theme';
import type { Coordonnees, StatutDispo, Structure } from '../types/structure';

/**
 * MapWebView — carte OpenStreetMap + Leaflet rendue dans une WebView (Module 3 / 3B.2).
 *
 * Choix carto : OSM + Leaflet en WebView = GRATUIT, sans clé API, compatible Expo Go (les SDK
 * natifs type react-native-maps exigent un dev build). Migration Google Maps/MapLibre possible
 * en prod sans toucher au backend.
 *
 * Sécurité (§8 / A10 SSRF — validation stricte des URL externes) : la WebView ne peut naviguer
 * QUE vers des hôtes explicitement autorisés (CDN Leaflet + tuiles OSM) ; toute autre URL est
 * bloquée. Aucune donnée médicale n'y transite (annuaire public uniquement).
 */

/** Hôtes autorisés dans la WebView (allowlist stricte). */
const HOSTS_AUTORISES = ['unpkg.com', 'tile.openstreetmap.org'];

/** Centre par défaut : district autonome d'Abidjan. */
const CENTRE_ABIDJAN = { lat: 5.347, lng: -4.0244, zoom: 12 };

/** Couleur + libellé du marqueur selon la disponibilité (cohérent avec PastilleDispo §5.5). */
const STATUT: Record<StatutDispo, { couleur: string; label: string }> = {
  disponible: { couleur: colors.success.solid, label: 'Disponible' },
  disponible_apres_14h: { couleur: colors.warning.solid, label: 'Après 14h' },
  complet: { couleur: colors.danger.solid, label: 'Complet' },
  ferme: { couleur: colors.ink[500], label: 'Fermé' },
  inconnu: { couleur: colors.disabled, label: 'Horaires inconnus' },
};

type Marqueur = {
  id: number;
  lat: number;
  lng: number;
  nom: string;
  commune: string;
  couleur: string;
  dispo: string;
};

export function MapWebView({
  structures,
  position,
  onSelect,
  route,
}: {
  structures: Structure[];
  position?: Coordonnees | null;
  onSelect?: (id: number) => void;
  /** Tracé d'itinéraire en [lat, lng] (Module 3 / 3B.3, F3.7). */
  route?: [number, number][] | null;
}) {
  const ref = useRef<WebView>(null);

  const marqueurs = useMemo<Marqueur[]>(
    () =>
      structures.map((s) => ({
        id: s.id,
        lat: s.latitude,
        lng: s.longitude,
        nom: s.nom,
        commune: s.commune,
        couleur: STATUT[s.statut_jour].couleur,
        dispo: STATUT[s.statut_jour].label,
      })),
    [structures],
  );

  /** Pousse les données dans la carte (appelé quand la page signale qu'elle est prête). */
  const envoyerDonnees = useCallback(() => {
    const payload = JSON.stringify({
      marqueurs,
      position: position ?? null,
      route: route ?? null,
      couleurPosition: colors.blue[600],
      couleurRoute: colors.blue[600],
    });
    ref.current?.injectJavaScript(`window.afficherDonnees(${payload}); true;`);
  }, [marqueurs, position, route]);

  // À chaque changement de filtres/position/itinéraire, on ré-injecte (si la page est prête).
  React.useEffect(() => {
    envoyerDonnees();
  }, [envoyerDonnees]);

  function onMessage(e: WebViewMessageEvent) {
    try {
      const msg = JSON.parse(e.nativeEvent.data) as { type: string; id?: number };
      if (msg.type === 'pret') {
        envoyerDonnees();
      } else if (msg.type === 'select' && typeof msg.id === 'number') {
        onSelect?.(msg.id);
      }
    } catch {
      /* message non-JSON ignoré */
    }
  }

  return (
    <View style={styles.wrap}>
      <WebView
        ref={ref}
        source={{ html: HTML }}
        originWhitelist={['*']}
        onMessage={onMessage}
        // §8 A10 : on n'autorise QUE le rendu inline + les hôtes carto de confiance.
        onShouldStartLoadWithRequest={(req) => urlAutorisee(req.url)}
        javaScriptEnabled
        domStorageEnabled
        style={styles.web}
        // La carte gère son propre défilement/zoom : pas de rebond du conteneur.
        scrollEnabled={false}
        startInLoadingState
      />
    </View>
  );
}

/** Validation stricte des URL chargées par la WebView (§8 doc Sécurité). */
function urlAutorisee(url: string): boolean {
  if (url === 'about:blank' || url.startsWith('data:') || url.startsWith('about:')) return true;
  try {
    const hote = new URL(url).hostname;
    return HOSTS_AUTORISES.some((h) => hote === h || hote.endsWith('.' + h));
  } catch {
    return false;
  }
}

/**
 * Page Leaflet servie inline. Expose `window.afficherDonnees({marqueurs, position})` appelée
 * par la couche RN ; remonte les événements via window.ReactNativeWebView.postMessage.
 * Les données utilisateur (noms) sont insérées comme TEXTE (textContent), jamais en HTML → pas
 * d'injection possible dans la page.
 */
const HTML = `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
  <style>
    html, body, #map { height: 100%; margin: 0; padding: 0; }
    .popup-nom { font-weight: 700; font-size: 14px; color: #0C3463; }
    .popup-meta { font-size: 12px; color: #62768A; margin-top: 2px; }
    .popup-btn { display:inline-block; margin-top:8px; padding:6px 12px; background:#1E6BB8;
      color:#fff; border:none; border-radius:999px; font-size:13px; font-weight:700; }
    /* Pastille de dispo (F3.1) : marqueur compatible clustering (L.marker + divIcon). */
    .dot { display:block; width:16px; height:16px; border-radius:50%;
      border:2px solid #fff; box-shadow:0 0 0 1px rgba(0,0,0,0.15); }
  </style>
</head>
<body>
  <div id="map"></div>
  <script>
    var poster = function (obj) {
      if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(JSON.stringify(obj));
    };
    var map = L.map('map', { zoomControl: true, attributionControl: true })
      .setView([${CENTRE_ABIDJAN.lat}, ${CENTRE_ABIDJAN.lng}], ${CENTRE_ABIDJAN.zoom});
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap'
    }).addTo(map);

    // Itinéraire + position : hors cluster. Structures : dans un groupe de clustering (F3.1).
    var couche = L.layerGroup().addTo(map);
    var amas = L.markerClusterGroup({
      showCoverageOnHover: false,   // pas de survol tactile
      maxClusterRadius: 55,         // rayon de regroupement (px)
      spiderfyOnMaxZoom: true       // éclatement des marqueurs superposés au zoom max
    }).addTo(map);

    window.afficherDonnees = function (data) {
      couche.clearLayers();
      amas.clearLayers();
      var bornes = [];

      // Tracé d'itinéraire (sous les marqueurs, jamais clusterisé).
      if (data.route && data.route.length > 1) {
        L.polyline(data.route, { color: data.couleurRoute || '#1E6BB8', weight: 5, opacity: 0.8 }).addTo(couche);
        data.route.forEach(function (p) { bornes.push(p); });
      }

      (data.marqueurs || []).forEach(function (m) {
        // Pastille colorée via divIcon (couleur = palette de dispo contrôlée) — clusterisable.
        var icone = L.divIcon({
          className: '',
          html: '<span class="dot" style="background:' + m.couleur + '"></span>',
          iconSize: [16, 16],
          iconAnchor: [8, 8],
          popupAnchor: [0, -8]
        });
        var marqueur = L.marker([m.lat, m.lng], { icon: icone });
        amas.addLayer(marqueur);

        // Contenu de la popup construit en TEXTE (pas d'injection HTML).
        var wrap = document.createElement('div');
        var nom = document.createElement('div'); nom.className = 'popup-nom'; nom.textContent = m.nom;
        var meta = document.createElement('div'); meta.className = 'popup-meta';
        meta.textContent = m.commune + ' · ' + m.dispo;
        var btn = document.createElement('button'); btn.className = 'popup-btn';
        btn.textContent = 'Voir la fiche';
        btn.onclick = function () { poster({ type: 'select', id: m.id }); };
        wrap.appendChild(nom); wrap.appendChild(meta); wrap.appendChild(btn);
        marqueur.bindPopup(wrap);

        bornes.push([m.lat, m.lng]);
      });

      if (data.position) {
        L.circleMarker([data.position.lat, data.position.lng], {
          radius: 8, color: '#fff', weight: 3, fillColor: data.couleurPosition || '#1E6BB8', fillOpacity: 1
        }).addTo(couche).bindPopup('Vous êtes ici');
        bornes.push([data.position.lat, data.position.lng]);
      }

      if (bornes.length === 1) {
        map.setView(bornes[0], 15);
      } else if (bornes.length > 1) {
        map.fitBounds(bornes, { padding: [40, 40] });
      }
    };

    poster({ type: 'pret' });
  </script>
</body>
</html>`;

const styles = StyleSheet.create({
  wrap: { flex: 1, borderRadius: radius.card, overflow: 'hidden', backgroundColor: colors.surfaceMuted },
  web: { flex: 1, backgroundColor: 'transparent' },
});
