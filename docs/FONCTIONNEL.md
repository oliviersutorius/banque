# Mes Comptes — documentation fonctionnelle

Ce document décrit le comportement du site du point de vue de l'utilisateur : ce que fait chaque page, et les règles
appliquées aux données. Pour l'architecture et les formats de fichiers, voir [TECHNIQUE.md](TECHNIQUE.md).

## Vue d'ensemble

Le site lit les exports CSV du Crédit Agricole, classe chaque opération dans une catégorie et présente les dépenses
et les revenus par mois et par année. Il fonctionne sur le poste local ou en ligne sur un serveur ; dans les deux cas
les données restent dans le dossier `data/` et l'accès est protégé par une connexion (voir « Connexion »).

| Page | Rôle |
|---|---|
| 📅 Mois | Totaux, graphiques et lignes d'un mois ; recatégorisation |
| 🗓️ Année | Mêmes vues sur une année ; matrice catégories × mois |
| 🧺 À trier | Toutes les lignes encore « À catégoriser » ; le badge du menu en donne le nombre |
| 📥 Import | Dépôt d'un CSV, aperçu, corrections, puis enregistrement |
| 🏷️ Catégories | Ajout, renommage, suppression, icônes |
| 🧩 Règles | Test d'un libellé, liste, création, modification, suppression |
| ⚙️ Paramètres | Charte graphique |

## Connexion

Le site n'a qu'un seul compte, le vôtre. Il n'y a pas de page d'inscription : le compte se crée sur la machine qui
héberge le site, en ligne de commande.

### Créer le compte

```bash
php api/bin/compte.php init
```

Le script demande un identifiant et un mot de passe (12 caractères minimum, saisis deux fois), puis prépare la
**double authentification** :

1. Il affiche un QR code (si `qrencode` est installé) ou une clé à saisir à la main. Ajoutez-la dans une application
   d'authentification sur votre téléphone : Aegis, Google Authenticator, 1Password, FreeOTP…
2. Il affiche **10 codes de secours**, chacun valable une seule fois, pour se connecter sans le téléphone. Notez-les
   et rangez-les hors ligne : ils ne seront plus jamais affichés.
3. Il demande le code affiché par l'application, pour vérifier que tout est bien enregistré.

Sur le poste local, le compte peut être créé sans double authentification (voir ci-dessous) :
`php api/bin/compte.php init --sans-totp`.

### Se connecter

Le site s'ouvre sur la page de connexion, en deux temps :

1. identifiant et mot de passe ;
2. le code à 6 chiffres affiché par l'application (il change toutes les 30 secondes et ne sert qu'une fois), ou un
   code de secours. Après un code de secours, le site indique combien il en reste.

Un même message signale un identifiant ou un mot de passe faux : le site ne révèle pas lequel des deux est en cause.
Il faut saisir le code dans les 5 minutes qui suivent le mot de passe, sinon tout est à refaire.

### Sur le poste local : mot de passe seul

Sur votre poste, la double authentification peut être retirée en lançant le serveur avec `BANQUE_TOTP=desactive`
(voir le README) : le mot de passe suffit alors. Ce réglage n'a **aucun effet** sur le site en ligne : il n'est
appliqué que si le site est ouvert à l'adresse `localhost` (ou `127.0.0.1`), sans HTTPS, depuis la machine elle-même.
En ligne, le code est toujours demandé, et un compte créé sans double authentification ne peut pas se connecter.

### Trop d'échecs

Après 5 échecs (mot de passe ou code faux), la connexion est bloquée 15 minutes, depuis cette adresse et pour le
compte. Chaque nouvelle série de 5 échecs double la durée (30 min, 1 h…, au plus 24 h). Une connexion réussie remet
les compteurs à zéro. Pour lever un blocage tout de suite : `php api/bin/compte.php debloquer`.

### Durée de la connexion et déconnexion

- Vous êtes déconnecté après **30 minutes sans activité**, et dans tous les cas **12 heures** après la connexion.
- Le bouton « Se déconnecter », en haut de la page, ferme la session tout de suite.
- Fermer le navigateur ferme aussi la session.

### Changer le mot de passe ou le téléphone

| Situation | Commande |
|---|---|
| Changer de mot de passe | `php api/bin/compte.php mot-de-passe` |
| Nouveau téléphone, téléphone perdu, codes de secours épuisés | `php api/bin/compte.php totp-reset` : nouvelle clé et 10 nouveaux codes de secours ; les anciens ne fonctionnent plus |
| Ajouter la double authentification à un compte créé avec `--sans-totp` | `php api/bin/compte.php totp-reset` |
| Connexion bloquée après trop d'échecs | `php api/bin/compte.php debloquer` |

Toutes les connexions, tous les échecs et toutes les déconnexions sont notés, avec la date et l'adresse IP, dans
`data/journal/connexions.log`.

## Import d'un relevé

### Fichiers acceptés

- Export CSV du Crédit Agricole : `Date;Libellé;Débit euros;Crédit euros`, séparateur `;`.
- Encodage UTF-8 (avec ou sans BOM) ou Windows-1252, détecté automatiquement.
- Les lignes situées avant l'en-tête (titre, numéro de compte, solde) sont ignorées, ainsi que les lignes vides et les
  lignes de fin commençant par « Solde », « Total », « Encours », « Liste des opérations » ou « Téléchargement ».
- Un libellé peut s'étendre sur plusieurs lignes (cas des vrais exports) : les retours à la ligne et les espaces
  multiples sont remplacés par un seul espace.
- Montants : virgule décimale, 0 à 2 décimales, espaces de milliers tolérés (`25`, `69,9`, `2 640,31`).
- Un `;` en trop en fin de ligne est toléré.
- Taille maximale : 2 Mo. Un fichier ne doit contenir qu'un seul compte (les exports multi-comptes sont à nettoyer
  avant l'import).

Un fichier peut mélanger plusieurs mois et plusieurs années, dans n'importe quel ordre : chaque ligne va dans le mois
de sa date.

### Lignes signalées

Une ligne qui ressemble à une opération mais ne peut pas être lue n'est jamais ignorée en silence : l'aperçu affiche
son numéro, son contenu et la raison. Elle n'est pas importée. Raisons possibles :

- date invalide ou au mauvais format (attendu `JJ/MM/AAAA`) ;
- ni débit ni crédit, ou débit et crédit remplis tous les deux ;
- montant illisible ou nul ;
- libellé vide ;
- valeur dans une colonne en trop.

Si l'en-tête `Date;Libellé;…` est introuvable, le fichier entier est refusé.

### Les deux temps de l'import

1. **Aperçu** : le fichier est lu et catégorisé, **rien n'est enregistré**. L'écran montre le résumé (lignes, mois,
   dépenses, revenus, doublons, erreurs, lignes à catégoriser) puis, mois par mois :
   - en tête et surlignées, les lignes à revoir : « À catégoriser », ou catégorisées par une règle « à confirmer »
     (badge *à confirmer*, par exemple AMAZON) ;
   - les autres lignes nouvelles ;
   - les doublons, grisés, qui ne seront pas ajoutés.
2. **Importer** : les lignes nouvelles sont ajoutées aux fichiers mensuels, avec les catégories éventuellement
   corrigées. « Annuler » abandonne l'aperçu sans rien écrire.

> ⚠️ Pendant l'aperçu, la fenêtre « Retenir cette correction ? » **écrit** dans les données si l'on crée une règle
> ou si l'on applique la correction aux mois déjà enregistrés. Pour une correction sans conséquence, choisir
> « Juste cette ligne ».

### Doublons

Une ligne est un doublon si une ligne déjà enregistrée a **la même date, le même libellé, le même sens et le même
montant**. La comparaison se fait par nombre d'occurrences : si le CSV contient deux fois le même café du même jour
et que le mois n'en contient qu'un, un seul est ajouté. Réimporter le même fichier n'ajoute donc rien.

Les catégories des lignes déjà enregistrées ne sont **jamais modifiées** par un import.

## Catégorisation automatique

### Catégories

- Les **dépenses** ont deux niveaux : catégorie 1 (ex. Alimentation) › catégorie 2 (ex. Courses).
- Les **revenus** ont un seul niveau (ex. Salaire).
- « ❔ À catégoriser » (et sa sous-catégorie « Non classé ») reçoit tout ce qu'aucune règle ne reconnaît. Elle ne peut
  être ni renommée ni supprimée.
- Une catégorie n'est jamais créée automatiquement.

### Lecture du libellé

Avant d'appliquer les règles, le libellé est simplifié (le libellé d'origine reste enregistré tel quel) :

1. le **type d'opération** est reconnu au début : Paiement par carte, Prélèvement, Virement en votre faveur, Virement
   reçu, Virement émis, Retrait au distributeur, Règlement, Remise de chèque, Chèque, Frais, Cotisation, Intérêts,
   Remboursement de prêt, Rejet, Régularisation, Avoir (sinon « Autre ») ;
2. le type répété en majuscules est retiré ;
3. sont retirés : le numéro de carte (`X1234`), les dates (`29/01`, `10/04/25`, `01/2026`), les heures (`08H31`), les
   identifiants créancier SEPA (`FR22ZZZ885B55`) et tout mot contenant 5 chiffres consécutifs ou plus (références) ;
4. le texte est mis en majuscules, sans accents, espaces réduits.

Exemple : `Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01` → type *Paiement par carte*,
détail `AUCHAN PEROLS`. Le texte comparé aux motifs est `PAIEMENT PAR CARTE AUCHAN PEROLS`.

La page **Règles › Tester un libellé** montre ce découpage et la règle appliquée.

### Règles

Une règle associe un **motif** à une catégorie, pour un sens (dépense ou revenu). Elle s'applique si :

- le motif apparaît dans le libellé simplifié sous forme de **mots entiers** (`BP` reconnaît « BP » mais pas « BPCE »,
  `CAF` ne reconnaît pas « CAFE »), sans tenir compte de la casse ni des accents ;
- les éventuels **autres mots requis** (`et`) sont aussi présents (ex. `PRET HABITAT` + `ASSU`) ;
- le sens de la ligne est celui de la règle ;
- le **type d'opération** correspond, si la règle en précise un (ex. carburant : paiements par carte seulement).

Une règle peut être marquée **« à confirmer »** : la ligne reçoit sa catégorie mais reste mise en évidence à l'import.

**Priorités**, quand plusieurs règles conviennent :

1. les règles **apprises** passent avant les règles **initiales** ;
2. à priorité égale, le **motif le plus long** gagne (le plus spécifique) ;
3. à égalité, l'ordre du fichier des règles.

Une règle qui vise une catégorie supprimée est ignorée. **Sans règle**, la catégorie se déduit du type d'opération :

| Type | Sens | Catégorie |
|---|---|---|
| Retrait au distributeur | dépense | Retraits d'espèces › Distributeur |
| Virement émis | dépense | Épargne & virements › Virement vers un autre compte |
| Virement en votre faveur / reçu | revenu | Virements reçus |
| autre | — | À catégoriser |

### Règles initiales

Elles reprennent le cahier des charges, avec ces ajustements décidés sur les vrais relevés :

- `COTISATION` (frais bancaires) ne vaut que pour les opérations de type *Cotisation* : le mot apparaît aussi dans les
  prélèvements d'assurance (« ECHEANCE COTISATION DU CONTRAT »).
- `AUCHAN CARBURANT` → Transport › Carburant (sinon `AUCHAN` le classerait en Courses).
- `TOTALENERGIES` → Logement › Électricité & gaz (les prélèvements s'écrivent « TotalEnergies Electricite »).
- `PRET HABITAT` exige aussi le mot `ASSU`.
- Carburant (`TOTAL`, `ESSO`, `BP`, `SHELL`, `AVIA`) : paiements par carte seulement.
- `AMAZON` est « à confirmer ».
- Pas de règle pour le salaire : elle se crée au premier import en corrigeant la ligne.

Les catégories et règles initiales sont écrites dans `data/` au premier lancement seulement ; ensuite, seuls les
fichiers de `data/` font foi.

## Corriger une catégorie (apprentissage)

Partout où une ligne apparaît (Import, Mois, Année, À trier), cliquer sur sa pastille de catégorie ouvre la liste
des catégories. Après le choix, la fenêtre **« Retenir cette correction ? »** propose :

- **un motif**, pré-rempli avec le détail du libellé (ex. `AUCHAN PEROLS`), à raccourcir pour couvrir plus de lignes
  (ex. `AUCHAN`) ;
- **Créer une règle pour les prochains imports** : la règle est enregistrée comme « apprise » (une règle apprise aux
  mêmes conditions est remplacée) ;
- **Seulement pour les … (type d'opération)** : limite la règle à ce type ;
- **Appliquer aux lignes similaires** : les lignes du même sens reconnues par le motif, dans le lot affiché — l'import
  en cours, le mois, l'année ou toutes les lignes à trier selon la page ; le nombre est indiqué ;
- **Aussi aux mois déjà enregistrés** : applique la règle aux lignes encore « À catégoriser » de tous les mois. Les
  lignes déjà catégorisées ne sont pas touchées (pour elles, utiliser « lignes similaires » depuis la vue Année).

« Juste cette ligne » (ou Échap) ne corrige que la ligne choisie.

## Vues Mois et Année

- Cartes **Dépenses**, **Revenus** et **Solde** ; un message d'encouragement s'affiche quand le solde est positif.
- Deux blocs, **Dépenses par catégorie** et **Revenus par catégorie**, avec trois affichages :
  - **Bâtons** : une barre par catégorie, de la plus grosse à la plus petite ;
  - **Camembert** (anneau) : total au centre, pourcentage et montant au survol, légende cliquable ;
  - **Tableau** :
    - *Mois* : les lignes en ordre chronologique, avec un sous-total par catégorie 1 ;
    - *Année* : matrice catégories × mois de janvier à décembre, totaux par ligne et par colonne ; « — » pour un mois
      non importé, « · » pour un mois importé sans montant ; les en-têtes de mois mènent à la vue mensuelle.
- Un clic sur une catégorie 1 (barre, part, sous-total, ligne de la matrice) ouvre son **détail** : répartition par
  catégorie 2 et liste des lignes, recatégorisables.
- Chaque catégorie garde toujours la même couleur ; « À catégoriser » est toujours en gris.

## À trier

Toutes les lignes « À catégoriser » de tous les mois, séparées en dépenses et revenus. Les lignes corrigées quittent
la liste. Le badge du menu est recompté après chaque import, correction, règle ou modification de catégorie.

## Catégories

- **Ajouter** une catégorie de dépense (avec sa première sous-catégorie), une sous-catégorie ou une catégorie de revenu.
- **Changer l'icône** (emoji) d'une catégorie 1 ou d'un revenu. Les sous-catégories n'ont pas d'icône.
- **Renommer** : la fenêtre indique combien de lignes enregistrées et de règles utilisent la catégorie ; le nom est
  changé dans **tous** les mois et **toutes** les règles. Un nom déjà pris est refusé.
- **Supprimer** : si la catégorie est utilisée, il faut choisir une catégorie de **remplacement**, vers laquelle partent
  ses lignes et ses règles. La dernière sous-catégorie d'une catégorie ne se supprime pas (supprimer la catégorie).

## Règles

- **Tester un libellé** : type d'opération, texte comparé aux motifs, catégorie obtenue et règle responsable.
- Liste filtrable (toutes, apprises, initiales) et recherche par motif ou catégorie.
- **Créer** (règle apprise), **modifier** (l'origine est conservée) et **supprimer** une règle. Supprimer une règle ne
  change pas les lignes déjà enregistrées.

## Paramètres

Choix de la charte graphique : **Guimauve** (pastel, par défaut), **Sauge & Lin** (naturel), **Nuit Lavande** (sombre).

## Données et sauvegardes

- Un fichier par mois dans `data/mois/` ; catégories, règles et paramètres dans `data/`.
- Le compte (`data/compte.json`) contient l'empreinte du mot de passe, jamais le mot de passe lui-même ; la clé de la
  double authentification y est chiffrée.
- Avant chaque modification d'un fichier, une copie horodatée est faite dans `data/sauvegardes/` (les 20 dernières par
  fichier). Pour revenir en arrière : arrêter le serveur et recopier la sauvegarde voulue à la place du fichier.
- Pour essayer sans toucher à ses données : lancer une instance d'essai avec `BANQUE_DATA_DIR` (voir le README).
