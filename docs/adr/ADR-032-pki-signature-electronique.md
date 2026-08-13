# ADR-032 — PKI et signature électronique des prescriptions (P6.5b)

**Statut** : accepté · **Date** : 2026-08-13 · **Corpus** : CDC_09 §5.3/§5.4 · CDC_10 §4.1/§4.3/§4.5 · CDC_04 §5.2
**Dépend de** : ADR-031 (référentiel professionnel) · **Achève** : P6.5, étape 5 de CDC_09 §14

---

## 1. Contexte

Le G0 de P6.5 avait trouvé un défaut que P6.5a a outillé sans le refermer :

> `ordonnances.medecin_nom` est un **texte libre saisi par le client**, y compris quand c'est le
> soignant lui-même qui écrit depuis le portail. Une ordonnance peut donc porter le nom de
> n'importe quel médecin.

P6.5a a construit l'identité professionnelle — numéro national, ordre, **autorisation d'exercer**
réservée à un compte habilité, rôle `medecin` ouvert au portail. P6.5b y attache une clé, un
certificat et une signature. C'est l'ordre annoncé au plan G1 : **l'identité d'abord, la clé
ensuite** — signer avant aurait garanti l'intégrité d'un document dont l'auteur déclaré restait une
chaîne de caractères.

---

## 2. La décision centrale — le serveur seul ne peut pas signer

**Décision propriétaire P1 du plan G1.** La clé privée d'un praticien est chiffrée en AES-256-GCM
par une clé **dérivée de son secret**, lequel n'est stocké nulle part. Ni l'accès à la base, ni
l'accès au `.env`, ni les deux réunis ne permettent de produire une signature : il faut que le
praticien la saisisse.

**Ce n'est pas une politique, c'est une propriété du code.** Retirer le paramètre `$secret` de
`ServiceSignature::signer()` ne rendrait pas le service permissif — il le rendrait inopérant.

Le chemin le plus simple aurait été de chiffrer avec `APP_KEY`. Le serveur aurait alors pu signer à
la place de n'importe quel médecin, et la non-répudiation affichée aurait été **fausse**. C'est la
différence entre une garantie et une mention.

**Deux vecteurs la prouvent, et aucun ne suffit seul** : le coffre s'ouvre avec le bon secret ; il
reste fermé avec un secret qui ne diffère que d'une majuscule.

**AAD non décorative** : le cryptogramme est lié à *son* certificat (numéro de série + identifiant
du praticien). Recopier `cle_privee_chiffree` d'une ligne vers une autre — ce qu'un accès en
écriture permettrait — **échoue**, au lieu d'attribuer silencieusement la clé d'un médecin à un
autre.

**PBKDF2-HMAC-SHA256, 210 000 itérations** (recommandation OWASP 2023). Ce n'est pas un réglage de
performance : c'est ce qui rend coûteuse une attaque par dictionnaire si la base fuitait. Le coût
est payé une fois par signature, par un humain qui vient de taper son secret.

---

## 3. Ce que la signature signe

**Une canonicalisation déterministe du contenu EN CLAIR**, jamais les octets stockés.

`ordonnances.medicaments_json` est un cast `encrypted:array`. Signer le cryptogramme aurait cassé la
signature au premier rechiffrement — rotation de clé, reprise de données — **sans qu'aucune donnée
n'ait bougé**. C'est le piège évité en P6.4c pour l'empreinte des images, et il est plus grave ici :
une signature qui casse toute seule ne prouve plus rien, et pire, **elle accuse**.

`EmpreinteReferentiel::canoniser` (P6.3) est réutilisée telle quelle : elle résout exactement le
problème de déterminisme, l'écrire une seconde fois aurait créé deux façons de hacher.

**Ce qui n'entre pas dans la signature est aussi une décision** : `updated_at`, `photo_url`,
`pdf_url`, `triage_id`, `source`, `added_by`. Un patient qui ajoute la photo de son ordonnance
papier ne doit pas casser la signature du médecin — une signature qui se brise au moindre geste
n'apprend plus rien à personne. Trois vecteurs en miroir le prouvent : dosage modifié → **altérée** ;
photo ajoutée → **intègre** ; rechiffrement du même clair → **intègre**.

**La signature n'empêche pas la modification, elle la RÉVÈLE.** C'est la définition de l'intégrité
au §5.3, pas un effet de bord.

---

## 4. Les cinq contrôles du §5.4, en classe pure

> « Le système vérifie : identité, certificat, autorisation d'exercer, expiration, révocation. Une
> signature est refusée si l'un de ces contrôles échoue, et l'échec est journalisé. »

`ReglesVerificationSignature` ne fait aucune requête, aucune écriture, n'a pas d'horloge cachée.
Motif de `ReglesReversement` et `ReglesRapprochement` côté paiement. Ce qui compte n'est pas
l'esthétique : **le jugement est rendu à un seul endroit**. Le disperser en accesseurs sur les
modèles créerait des vérités concurrentes, et le jour où elles divergeraient, c'est la signature
qui trancherait à la place du corpus.

**L'ordre est délibéré** : identité → certificat → **révocation** → expiration → autorisation
d'exercer. Un certificat révoqué la semaine dernière et expiré depuis doit être refusé **pour
révocation** ; le motif journalisé serait sinon « expiré » — vrai, mais il masquerait le fait qui
compte en litige.

Un sixième contrôle s'ajoute aux cinq : l'**habilitation au type de document**. Un kinésithérapeute
n'est pas prescripteur. C'est un fait administratif transcrit depuis `ProfessionsSante::PRESCRIPTEURS`,
pas une règle médicale — le corpus interdit la seconde (CDC_00 §4), pas la première.

---

## 5. L'autorité de certification — dit sans enjoliver

**Auto-signée.** Aucune autorité de certification nationale ivoirienne n'a été consultée, aucune
chaîne de confiance publique n'existe. Un navigateur ne reconnaîtra pas ces certificats, et c'est
normal : ils ne servent pas à authentifier un serveur mais à **lier une prescription à un praticien
identifié dans cette plateforme**.

L'appeler « autorité nationale » ou lui donner des airs officiels serait le genre de fausseté qui ne
se fait pas corriger parce qu'elle a l'air juste. La commande le rappelle à chaque création, et
l'écran du praticien aussi.

**Sa clé est protégée par une phrase de passe d'environnement**, sans valeur par défaut : une valeur
de repli serait un secret du dépôt (CDC_10 §5), et pire, un secret que tout le monde croirait avoir
remplacé. Absente, la création **échoue bruyamment** — même principe que la commission sans seed en
P5.5a.

Le serveur peut donc signer **en tant que CA**, ce qui est nécessaire : émettre un certificat est
une opération serveur déclenchée par un humain. Ce qu'aucun chemin ne permet, c'est de signer **en
tant que praticien**.

**Idempotente par refus, pas par silence.** Régénérer la CA invaliderait tous les certificats émis,
donc toutes les signatures posées. Une commande qui aurait « silencieusement rien fait » se
rejouerait sans crainte — et un jour, avec un `--force` ajouté à la hâte.

**Un praticien demande son propre certificat, et ce n'est pas une auto-certification** : l'autorité
ne certifie que ce que le référentiel affirme déjà, et `autorisation_statut` n'est écrite que par un
compte portant `professionnel.habiliter` (ADR-031 §4). La vraie porte est en amont, et elle est
gardée. Faire passer l'émission par un tiers aurait ajouté une file d'attente sans ajouter de
garantie : ce tiers n'aurait eu, pour décider, que l'information déjà au référentiel.

---

## 6. Le registre des documents signables

Interface `DocumentSignable` + liste blanche fermée, motif `SourceReferentiel`. **Brancher un type
de document est un ajout de classe, jamais une modification du moteur** — c'est ce qui rend tenable
l'engagement du plan G1 : les cinq entités documentaires manquantes se brancheront ici, une classe
et une ligne chacune.

**Les sept types de CDC_10 §4.5 sont tous nommés**, avec l'état de chacun : un branché
(l'ordonnance), cinq sans entité — chacun avec sa raison écrite — et la facture, **signée ailleurs
par nature** (service de paiement, P5.2b ; §10 l'attribue à « l'administration », pas au médecin).

**Nommer un manque ne le comble pas, mais un manque nommé ne s'oublie pas** — et l'on ne prétend
nulle part que « la signature couvre les documents médicaux ».

---

## 7. Le prescripteur cesse d'être déclaré par le client

`EcritureSoignantService` réécrit désormais `medecin_nom` et `structure_sanitaire` depuis la fiche
professionnelle — même mouvement que `source` et `added_by` en P7-C/D0, et pour la même raison :
**ce que le serveur sait n'a pas à être redemandé à celui qu'on contrôle**.

**Le chemin du patient n'est pas touché.** Quelqu'un qui recopie une ordonnance papier continue de
saisir le nom du médecin qui la lui a remise ; le lui imposer depuis un compte qu'il n'a pas serait
absurde, et ce serait une régression sur un module validé G5.

**La signature reste facultative à l'écriture**, et c'est assumé : P7-D0 est validé G5, un praticien
sans certificat doit continuer d'écrire au carnet. Ce qui est **inconditionnel**, c'est le nom du
prescripteur — le trou du G0 se referme pour toutes les ordonnances, signées ou non.

Un échec de signature **ne défait pas l'écriture** : l'ordonnance est déjà au carnet, déjà notifiée,
déjà journalisée. L'annuler parce qu'un secret a été mal tapé priverait le patient de sa
prescription pour une raison qui ne le concerne pas.

---

## 8. Ce que le G2 live a trouvé

**Le contrôle de révocation était inatteignable.** `ServiceSignature` interrogeait
`certificatActif()` ; après une révocation, celle-ci ne renvoie plus rien. Les règles concluaient
donc « **aucun certificat n'a été émis pour ce professionnel** » — ce qui est faux — et le journal
enregistrait `controle: certificat` au lieu de `controle: revocation`.

Deux conséquences, la seconde plus grave que la première : un contrôle que le corpus **exige
nommément** ne pouvait jamais se déclencher ; et en litige, la trace aurait dit autre chose que le
fait.

**Mon test ne l'avait pas vu parce qu'il ne vérifiait que « ça refuse », pas « ça refuse pour la
bonne raison ».** Il passait malgré le défaut. Corrigé par `dernierCertificat()` — le service
rassemble l'état, **les règles jugent** — et le vecteur a été réécrit pour asserter le contrôle
journalisé.

**PHP ne peut pas générer de clé RSA sans `openssl.cnf`** sur cet environnement. WAMP en livre un,
mais s'y fier ferait dépendre la PKI d'un chemin propre à un poste : on embarque
`config/pki/openssl.cnf` et on le passe explicitement.

---

## 9. Conséquences

**Acquis.** Une prescription est juridiquement traçable : authenticité (le certificat désigne un
praticien du référentiel), intégrité (toute modification devient détectable), non-répudiation (le
serveur ne peut pas signer à la place du médecin). Les refus sont journalisés dans une chaîne
append-only. **P6.5 est complet.**

**Limites annoncées.**

- **Aucun HSM** (CDC_10 §4.3) — clé chiffrée logicielle, point d'extension classé « conçu ».
- **CA auto-signée** : aucune confiance publique, aucune autorité nationale consultée.
- **Aucun horodatage qualifié** (pas de TSA) : l'heure est celle du serveur, journalisée.
- **Pas de CRL publiée, pas d'OCSP** : la révocation est une colonne, la vérification est locale.
- **Un seul des sept documents du §4.5 est signé.**
- **La signature n'est pas obligatoire** : une ordonnance non signée reste licite, et l'écran de
  vérification le dit explicitement pour ne pas faire douter de documents parfaitement valides.
- **Un certificat perdu ne se récupère pas** : le secret n'étant stocké nulle part, il faut révoquer
  et réémettre. L'écran le dit **avant** la saisie, pas après.
- **Le seuil de verrouillage est temporaire** — un verrou définitif transformerait une faute de
  frappe répétée en perte de certificat pour un praticien en exercice.

---

## 10. Preuves

**G3** — 50 tests dédiés (`SignatureElectroniqueTest` 39, `PrescripteurSignatureTest` 11) ; suite
complète **641 tests / 14 981 assertions, 0 échec** ; typechecks shared + web + mobile verts.

**G2 live MySQL — 14 vecteurs (W1–W14).** Sans phrase de passe → refus nommant la variable ;
autorité créée puis rejeu refusé ; certificat émis, chaîne vérifiée, sujet portant `PRO000001` ; clé
privée absente du JSON ; ordonnance signée → **intègre** ; dosage modifié → **altérée** ; contenu
restauré → **intègre de nouveau** ; `photo_url` ajoutée → **intègre** ; mauvais secret → refus
générique + refus journalisé + aucune signature ; autorisation retirée → refus avec
`controle: autorisation_exercer` ; révocation → refus **pour révocation** (après correctif), la
signature posée **reste valide**, le certificat révoqué **est conservé** ; chaîne du journal intacte
→ altération du seul `acteur_nom` détectée → rétablie ; **zéro contenu clinique, zéro secret, zéro
clé privée** dans le journal et dans `laravel.log`. Invariants I1–I5 vérifiés. **Base et `.env`
restaurés.**

**Aucune dépendance nouvelle** : `openssl`, `hash_pbkdf2` et le hachage sont natifs à PHP 8.3.

Guide : `GUIDE_TEST_PROFESSIONNELS.md` partie 2.
