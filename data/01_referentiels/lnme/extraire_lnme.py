"""
Extraction complète de la Liste Nationale des Médicaments Essentiels et du Matériel
Biomédical de Côte d'Ivoire — Édition 2024 (95 pages).

Méthode : reconstruction des colonnes à partir des positions horizontales des mots.
L'extraction de tableaux de pdfplumber fusionne une ligne sur deux dans une cellule
unique sur ce PDF ; le positionnement des mots est fiable, lui, car la mise en page
est strictement colonnaire et l'en-tête se répète sur chaque page.
"""

import json
import re
import unicodedata
import warnings
from collections import defaultdict

import pdfplumber

warnings.filterwarnings('ignore')

SRC = '/root/.claude/uploads/be8d48a3-2372-5e06-a843-33c01244d313/245a9c95-1787406704124_cotedivoire_neml_2024_compressed.pdf'

# Le PDF encode les Q avec une ligature latine « Ǫ ». On restaure.
REMPLACEMENTS = {'Ǫ': 'Q', 'ǫ': 'q'}

MARQUEUR_ENFANT = '℗'

# Le PDF écrit « Categorisation » dans les annexes 1 et 2, « Catégorisation » dans l'annexe 3.
EN_TETES = ['Désignation/Substance', 'Dosage', 'Voie', 'Forme', 'Unité', 'Niveau',
            'Categorisation', 'Catégorisation']

RE_SECTION = re.compile(r'^(\d{2})\s+(.+)$')
RE_SOUS_SECTION = re.compile(r'^(\d{2}\.\d{2})\s+(.+)$')
RE_ANNEXE = re.compile(r'^ANNEXE\s+(\d+)\s*[-–]\s*(.+)$', re.I)
RE_LISTE = re.compile(r'^LISTE\s+(PRINCIPALE|COMPL[EÉ]MENTAIRE)\s*$', re.I)


def nettoyer(t: str) -> str:
    for a, b in REMPLACEMENTS.items():
        t = t.replace(a, b)
    t = unicodedata.normalize('NFC', t)
    return re.sub(r'\s+', ' ', t).strip()


def lignes_de_page(page, tolerance=3.5):
    """
    Regroupe les mots en lignes visuelles par agglomération sur la coordonnée verticale.
    Un arrondi fixe (top/3) coupait des lignes en deux et en fusionnait d'autres ;
    l'agglomération suit les écarts réels entre lignes.
    """
    mots = page.extract_words(use_text_flow=False, keep_blank_chars=False)
    if not mots:
        return []
    mots.sort(key=lambda w: (w['top'], w['x0']))
    lignes, courante, reference = [], [mots[0]], mots[0]['top']
    for m in mots[1:]:
        if abs(m['top'] - reference) <= tolerance:
            courante.append(m)
        else:
            lignes.append(sorted(courante, key=lambda w: w['x0']))
            courante, reference = [m], m['top']
    lignes.append(sorted(courante, key=lambda w: w['x0']))
    return lignes


def bornes_colonnes(lignes):
    """Déduit les frontières de colonnes depuis la ligne d'en-tête de la page."""
    for ligne in lignes:
        textes = [w['text'] for w in ligne]
        if 'Désignation/Substance' in textes:
            ancres = []
            for cible in EN_TETES:
                for w in ligne:
                    if w['text'] == cible:
                        ancres.append((cible, w['x0']))
                        break
            ancres = [a for a in ancres if a[0] != 'Unité' or not any(x[0] == 'Forme' for x in ancres)]
            ancres.sort(key=lambda a: a[1])
            noms = [a[0] for a in ancres]
            xs = [a[1] for a in ancres]
            # Le texte est aligné à gauche dans chaque colonne et peut déborder
            # jusqu'au début de la suivante : la frontière se place donc juste
            # avant l'ancre de chaque colonne, pas à mi-chemin entre deux ancres.
            # Colonne 0 : le marqueur ℗ seul. Colonne 1 : la désignation.
            marge = 6
            bornes = [xs[0] - 30] + [x - marge for x in xs] + [10_000]
            return noms, bornes
    return None, None


def colonne(x, bornes):
    if x < bornes[0]:
        return 0
    for i in range(len(bornes) - 1):
        if bornes[i] <= x < bornes[i + 1]:
            return i
    return len(bornes) - 2


def extraire():
    entrees = []
    contexte = {'annexe': None, 'liste': None, 'section_code': None, 'section': None,
                'sous_section_code': None, 'sous_section': None}
    sections = []
    pages_sans_tableau = []

    with pdfplumber.open(SRC) as pdf:
        total = len(pdf.pages)
        for num, page in enumerate(pdf.pages, start=1):
            lignes = lignes_de_page(page)
            noms, bornes = bornes_colonnes(lignes)
            if not noms:
                pages_sans_tableau.append(num)
                continue

            derniere = None
            for ligne in lignes:
                texte_brut = nettoyer(' '.join(w['text'] for w in ligne))
                if not texte_brut:
                    continue

                # Bandeaux de page à ignorer
                if texte_brut.startswith('Désignation/Substance'):
                    continue
                if texte_brut.startswith("℗ : Destiné à l'enfant") or re.match(r'^Page \d+$', texte_brut):
                    continue
                if 'Liste Nationale des Médicaments Essentiels' in texte_brut:
                    continue
                if re.match(r'^(Médicaments essentiels|Matériel Biomédical essentiel)\s*[–-]', texte_brut):
                    continue

                # Structure documentaire
                m = RE_ANNEXE.match(texte_brut)
                if m:
                    contexte.update(annexe=f'Annexe {m.group(1)} — {nettoyer(m.group(2)).title()}',
                                    liste=None, section_code=None, section=None,
                                    sous_section_code=None, sous_section=None)
                    sections.append(('annexe', contexte['annexe'], num))
                    derniere = None
                    continue
                if RE_LISTE.match(texte_brut):
                    contexte['liste'] = texte_brut.title()
                    sections.append(('liste', contexte['liste'], num))
                    derniere = None
                    continue

                # Les titres de (sous-)section occupent la seule colonne de gauche
                cols_occupees = {colonne(w['x0'], bornes) for w in ligne}
                titre_seul = cols_occupees <= {0, 1}

                m = RE_SOUS_SECTION.match(texte_brut)
                if m and titre_seul:
                    contexte['sous_section_code'] = m.group(1)
                    contexte['sous_section'] = nettoyer(m.group(2))
                    sections.append(('sous_section', f"{m.group(1)} {contexte['sous_section']}", num))
                    derniere = None
                    continue
                m = RE_SECTION.match(texte_brut)
                if m and titre_seul:
                    contexte['section_code'] = m.group(1)
                    contexte['section'] = nettoyer(m.group(2))
                    contexte['sous_section_code'] = None
                    contexte['sous_section'] = None
                    sections.append(('section', f"{m.group(1)} {contexte['section']}", num))
                    derniere = None
                    continue

                # Ligne de données
                cellules = ['' for _ in range(len(bornes) - 1)]
                for w in ligne:
                    i = colonne(w['x0'], bornes)
                    cellules[i] = (cellules[i] + ' ' + w['text']).strip()
                cellules = [nettoyer(c) for c in cellules]

                enfant = MARQUEUR_ENFANT in cellules[0]
                designation = cellules[1]
                reste = cellules[2:]

                # Ligne de continuation : pas de niveau, pas de désignation nouvelle en tête
                # Ne chercher le niveau QUE dans sa colonne : des mots courants comme
                # « DE » ou « AB » ailleurs dans la ligne ressemblent à un code de niveau.
                i_niveau = noms.index('Niveau') - 1 if 'Niveau' in noms else -1
                cell_niveau = reste[i_niveau] if 0 <= i_niveau < len(reste) else ''
                a_niveau = bool(re.fullmatch(r'[A-E]{1,5}', cell_niveau))
                # Un titre de section peut déborder sur plusieurs colonnes ou plusieurs
                # lignes. Tout en capitales et sans niveau : c'est un titre, pas une entrée.
                # À traiter AVANT la continuation, sinon il se colle à l'entrée précédente.
                if not a_niveau and texte_brut.upper() == texte_brut and designation:
                    suite = nettoyer(re.sub(r'^\d{2}(\.\d{2})?\s+', '', texte_brut))
                    if contexte['sous_section']:
                        contexte['sous_section'] = nettoyer(contexte['sous_section'] + ' ' + suite)
                    elif contexte['section']:
                        contexte['section'] = nettoyer(contexte['section'] + ' ' + suite)
                    derniere = None
                    continue

                if not a_niveau and derniere is not None and (designation or any(reste)):
                    if designation:
                        derniere['designation'] = nettoyer(derniere['designation'] + ' ' + designation)
                    for idx, val in enumerate(reste):
                        if val:
                            cle = noms[idx + 1] if idx + 1 < len(noms) else f'col{idx}'
                            derniere['_brut'][cle] = nettoyer(derniere['_brut'].get(cle, '') + ' ' + val)
                    continue

                if not designation and not any(reste):
                    continue
                if not designation:
                    continue

                brut = {}
                for idx, val in enumerate(reste):
                    cle = noms[idx + 1] if idx + 1 < len(noms) else f'col{idx}'
                    brut[cle] = val

                entree = {
                    'annexe': contexte['annexe'],
                    'liste': contexte['liste'],
                    'section_code': contexte['section_code'],
                    'section': contexte['section'],
                    'sous_section_code': contexte['sous_section_code'],
                    'sous_section': contexte['sous_section'],
                    'designation': designation,
                    'usage_pediatrique': enfant,
                    'page_pdf': num,
                    '_brut': brut,
                }
                entrees.append(entree)
                derniere = entree

    # Normalisation finale des colonnes
    for e in entrees:
        b = e.pop('_brut')
        e['dosage'] = b.get('Dosage', '')
        e['voie'] = b.get('Voie', '')
        e['forme'] = b.get('Forme', b.get('Unité', ''))
        e['niveau'] = b.get('Niveau', '')
        e['categorisation'] = b.get('Categorisation', '')
        # Un niveau mal aligné se retrouve parfois dans « forme »
        if not e['niveau']:
            for cle in ('forme', 'voie'):
                if re.fullmatch(r'[A-E]{1,5}', e[cle]):
                    e['niveau'] = e[cle]
                    e[cle] = ''

    return entrees, sections, total, pages_sans_tableau


if __name__ == '__main__':
    entrees, sections, total, sans = extraire()
    json.dump({'entrees': entrees, 'sections': sections, 'pages': total,
               'pages_sans_tableau': sans},
              open('/root/extraction/lnme_brut.json', 'w'), ensure_ascii=False, indent=1)
    print(f'{len(entrees)} entrées extraites sur {total} pages')
    print(f'pages sans tableau : {sans}')
    niveaux = defaultdict(int)
    for e in entrees:
        niveaux[e['niveau']] += 1
    print('niveaux :', dict(sorted(niveaux.items(), key=lambda x: -x[1])[:12]))
    print('pédiatriques :', sum(1 for e in entrees if e['usage_pediatrique']))
    print('sans niveau :', sum(1 for e in entrees if not e['niveau']))
