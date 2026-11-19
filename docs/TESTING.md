# Plan de vérification manuelle

Ce document guide les testeurs page par page, avec le jeu de données du groupe de fixtures `test`. Les tests
automatiques (`symfony php bin/phpunit`) parcourent déjà toutes les pages avec ces données
(`tests/Controller/FixturesSmokeTest.php`) ; ce plan sert à vérifier **ce qu'on voit à l'écran**.

## Préparation

```bash
docker compose up -d
symfony console doctrine:migrations:migrate
symfony console doctrine:fixtures:load --group=test --append   # --append est obligatoire
symfony serve -d
```

Trois utilisateurs sont créés, avec le même mot de passe (voir `src/DataFixtures/TestUserFixtures.php`) :

| Utilisateur | Rôle pour le test |
| --- | --- |
| `test@finora.test` | **Utilisateur complet** : toutes les pages sont remplies, cas limites compris. C'est avec lui qu'on fait la plupart des vérifications. |
| `autre@finora.test` | **Autre utilisateur** : ses données ne doivent jamais apparaître chez l'utilisateur complet (et inversement). Tous ses libellés contiennent « (autre utilisateur) ». |
| `vide@finora.test` | **Utilisateur sans donnée** : sert à vérifier les pages vides. |

Les dates sont relatives à aujourd'hui : le mois en cours est toujours rempli et l'historique couvre les huit mois
précédents. Recharger le groupe `test` remet ces trois utilisateurs à zéro (utile après des essais de modification
ou de suppression). Le jeu `demo` (`--group=demo`) est un jeu de présentation calé sur les maquettes.

Pour chaque page, vérifier aussi : aucun message d'erreur, textes en français, montants au format « 1 250,00 € ».

## Connexion et compte

- **Inscription** (`/register`) : créer un compte ; refus d'une adresse déjà utilisée, d'un mot de passe de moins de
  10 caractères ou de deux mots de passe différents ; après inscription, connexion possible.
- **Connexion** (`/login`) : mauvais mot de passe → message d'erreur ; après 5 échecs en une minute, la connexion est
  bloquée même avec le bon mot de passe.
- **Mot de passe oublié** (lien sur la page de connexion) : la réponse est identique que l'adresse existe ou non. L'e-mail
  arrive dans Mailpit (interface web : `docker compose port mailer 8025`) ; le lien est à usage unique et expire après 1 h ;
  une deuxième demande dans les 10 minutes n'envoie pas d'e-mail. Le worker doit tourner (`symfony serve -d` le lance).
- **Déconnexion**, puis tentative d'ouvrir une page protégée → retour à la connexion.

## Accueil (`/`)

- Bonjour « Testeur », solde total, revenus, dépenses et épargne du mois ; navigation entre les mois (flèches).
- Courbe sur 6 mois et répartition des dépenses (4 catégories principales + « Autres »).
- Le solde total ne compte **pas** le compte en dollars (aucune conversion entre devises).
- Mobile : carte de solde, accès rapides, aperçu du mois.

## Comptes (`/account`)

- 5 comptes, un par type : Compte courant, **Livret A (5 212,50 €)**, Espèces, **Compte voyage en dollars ($)**,
  **Compte à découvert (−350,00 €, le seul solde négatif)**.
- Créer, modifier, supprimer. La suppression d'un compte qui contient des transactions est refusée avec un message.
- Vider un champ obligatoire en modifiant → message d'erreur (pas de page d'erreur).

## Transactions (`/transaction`)

- Plus de 100 transactions regroupées par mois, sur 9 mois ; onglets Toutes / Revenus / Dépenses ; recherche
  (essayer « Supermarché », « Café », « logement » : le libellé et la catégorie sont cherchés).
- Cas limites à repérer :
  - « **Café du jour** » (aujourd'hui) et « **Paiement programmé (futur)** » (dans deux jours) ;
  - `Test <b>HTML</b> & "guillemets" 'apostrophes'` : doit s'afficher **tel quel**, sans mise en gras, y compris sa note
    qui contient une balise `<script>` ;
  - un libellé très long : la ligne ne doit pas déborder, même sur mobile ;
  - une catégorie au nom très long, une catégorie sans icône ni couleur ;
  - les transactions générées par une récurrence portent la mention « mensuelle », « quotidienne »…
- Créer, modifier, supprimer ; le formulaire propose uniquement les comptes et catégories de l'utilisateur ;
  montant négatif ou nul refusé.

## Export CSV (`/export/transactions`)

- **Bouton de téléchargement** en haut de la page Transactions (icône de flèche, à côté du « + ») : le fichier
  `finora-transactions-AAAA-MM-JJ.csv` se télécharge. Avec un onglet (Revenus / Dépenses) ou une recherche actifs, **seules les
  lignes filtrées** sont exportées ; sans filtre, tout. Le lien « Exporter toutes mes transactions (CSV) » du profil exporte tout.
- **Plus de 300 transactions** : la liste s'arrête à 300 lignes, l'export contient tout.
- **Ouvrir dans Excel ou LibreOffice** (français) : colonnes bien séparées, accents corrects, dépenses en négatif et colonne
  « Montant » additionnable. Avec `?format=intl` (à saisir dans l'adresse), le séparateur est la virgule et le point décimal.
- **Cas du jeu de test** : la transaction `Test <b>HTML</b> & "guillemets" 'apostrophes'` et sa note sur deux lignes arrivent intactes ;
  les transactions de l'autre utilisateur n'apparaissent jamais.
- **Injection de formules** : créer une transaction dont le libellé est `=1+1` (ou `@SUM(A1)`, `+33…`, `-2+3`) et l'exporter :
  la cellule doit afficher le texte avec une apostrophe devant, **sans calculer** de formule. Ne jamais valider une ouverture
  qui lancerait une formule ou un lien.
- **Période** (dans l'adresse) : `?from=2026-11-01&to=2026-11-30` (bornes incluses) ; `?type=income` ; `?q=loyer`. Une valeur invalide
  (`?type=virement`, `?from=31/12/2026`, fin avant début) renvoie une erreur 422.
- Déconnecté, l'adresse redirige vers la connexion.

## Récurrences (`/recurring`)

- 12 règles : une de chaque fréquence (quotidienne, hebdomadaire, mensuelle, annuelle) et de chaque état.
- États : **active** (prochaine échéance affichée), **en pause** (« Salle de sport »), **terminée** (« Abonnement magazine »),
  **pas encore commencée** (« Nouvelle assurance auto », « Prime annuelle »), **fin de mois** (« le 31 » : les échéances
  tombent le 28, 29 ou 30 les mois courts), **autre devise** (« Abonnement cloud (USD) »).
- Totaux mensuels : revenus récurrents **2 600,00 €** (salaire + prime annuelle ÷ 12) ; dépenses ≈ 937 €. Les règles en
  pause, terminées ou en dollars ne comptent pas.
- Mettre en pause puis reprendre : à la reprise, les échéances manquées sont rattrapées. Modifier une règle ne change pas
  les transactions déjà générées. Supprimer une règle conserve ses transactions.
- Créer une règle dont la première échéance est dans le passé : les transactions passées sont créées tout de suite
  (message de confirmation).

## Budgets (`/budget`)

- Mois en cours : **Loisirs 180/150 (120 %, barre rouge)**, **Transport 82,50/200 (41 %)**, **Shopping 85/100 (85 %, orange)**,
  **Voyage ≈ 470/500 $ (≈ 94 %, orange, en dollars)**, Alimentation et Restaurants (pourcentage variable selon la date).
- Mois précédent : 3 budgets ; mois suivant : Alimentation à **0 %**.
- Créer un second budget pour la même catégorie et le même mois → refusé.

## Objectifs (`/goal`)

- 7 objectifs : Voiture **64 %**, Vacances **100 %** (atteint), Nouveau PC **0 %** (sans échéance), Voyage aux États-Unis **17 %** ($),
  « dépassé » **100 %** (250 € épargnés pour 100 € visés, barre plafonnée), « en retard » **40 %** (échéance passée),
  un nom très long qui ne doit pas déborder.

## Calendrier (`/calendar`)

- Mois en cours : le jour d'aujourd'hui est entouré et déjà sélectionné ; son détail liste « Café du jour ».
- Pastilles vertes (revenus), rouges (dépenses), en pointillés (récurrences prévues) ; totaux du mois et « À venir ».
- Mois suivant : récurrences prévues (« Nouvelle assurance auto » le 8) ; mois dans 3 mois : « Prime annuelle » le 20.
- Cliquer un jour → détail ; « Ajouter une transaction » ouvre le formulaire avec **la date du jour choisi**.
- Mobile : les montants sont remplacés par de petits points.

## Statistiques (`/stats`)

- Dépenses puis Revenus : donut, pourcentages et montants par catégorie, total au centre ; navigation entre les mois.
- Un mois sans transaction affiche un message, pas un graphique vide.

## Catégories et paramètres

- `/category` : 14 catégories (icône et couleur facultatives). Un nom déjà utilisé est refusé ; supprimer une catégorie
  qui contient des transactions est refusé.
- `/settings` (« Plus » sur mobile) : liens vers toutes les sections, profil, déconnexion.

## Profil (`/profile`)

- En-tête avec le prénom et l'e-mail ; « Mes données » : **5** comptes, plus de 100 transactions, **12** récurrences, **10** budgets,
  **7** objectifs, **14** catégories (chaque tuile mène à la page correspondante).
- Changer le prénom ; un prénom vide est refusé et le menu garde l'ancien.
- Choisir une **icône de profil** (10 pictogrammes ou « Initiale ») : elle remplace l'initiale dans la barre latérale, l'accueil mobile, « Plus » et l'en-tête du profil. L'utilisateur de test a la fusée.
- Changer l'e-mail : le mot de passe actuel est exigé ; une adresse déjà utilisée ou invalide est refusée ; on reste connecté.
- Changer le mot de passe : mot de passe actuel exigé, 10 caractères minimum, confirmation identique ; **les autres sessions
  (autre navigateur) sont déconnectées**, celle-ci reste ouverte.
- Supprimer le compte (essayer avec un utilisateur créé pour l'occasion, pas avec `test@finora.test`) : mot de passe exigé,
  tout est supprimé, retour à la connexion, reconnexion impossible.

## Cloisonnement des données

- Connecté en `test@finora.test`, aucune page ne doit montrer « (autre utilisateur) ».
- Connecté en `autre@finora.test`, seules ses données apparaissent (« Compte secret », « Achat privé »…).
- Relever un identifiant dans l'URL d'un élément de l'autre utilisateur (ex. `/account/<id>`) : accès refusé (403).

## Pages vides (`vide@finora.test`)

- Chaque liste affiche un message (« Aucun compte », « Aucune transaction »…) ; l'accueil et les statistiques n'affichent pas de
  graphique cassé ; le profil indique 0 partout ; le formulaire de transaction ne propose aucun compte.

## Affichage

- Tester en fenêtre large (barre latérale) et étroite (< 900 px : barre d'onglets en bas), sans défilement horizontal.

## Animations, interactions et éléments 3D

**Vérification automatique d'abord** : `node tools/browser/check.mjs` (serveur lancé, jeu de test chargé) contrôle dans un vrai
navigateur les pages, les débordements mobiles et les interactions décrites ci-dessous, et écrit des captures dans
`var/screenshots/`. Ce qui suit sert à **regarder** le résultat, ce qu'un script ne juge pas (fluidité, goût).

À vérifier avec l'utilisateur complet, animations en mode *Auto* (ou *Complètes*) :

- **Apparition** : en ouvrant une page ou en défilant, les cartes et les lignes de liste apparaissent à tour de rôle, sans saccade.
- **Accueil** (bureau) : les montants des quatre cartes montent de 0 à leur valeur et s'arrêtent exactement dessus ; la courbe se trace de
  gauche à droite, le donut se dessine part par part. Survoler une carte : elle s'incline vers le pointeur avec un reflet ; la quitter la
  remet à plat.
- **Courbe** : survoler un mois affiche un trait pointillé et une info-bulle (mois, revenus, dépenses) qui ne sort jamais du graphique ; au
  clavier (Tab), chaque mois reçoit le focus et affiche la même info-bulle.
- **Donut** (accueil et statistiques) : survoler une part ou une ligne de légende fait ressortir la catégorie, estompe les autres et affiche
  son nom et son montant au centre ; quitter rétablit le total. Même chose au clavier sur la légende.
- **Budgets et objectifs** : les barres se remplissent en arrivant à l'écran.
- **Objectifs** : le bouton rond (flèches) d'une carte la retourne en 3D. Au dos : montant épargné, reste à épargner, échéance (« Dans N
  mois », « Ce mois-ci », « dépassée »), effort mensuel nécessaire (≈ reste ÷ mois restants), et les boutons Voir / Modifier / Retour. Échap
  ou Retour ramène à l'avant. Cas du jeu de test : objectif atteint (« Objectif atteint », pas d'effort), en retard (« dépassée », pas
  d'effort), sans échéance (« Aucune »). Au clavier, seule la face visible est atteignable.
- **Connexion / inscription** : la pièce 3D tourne et flotte ; elle ne gêne pas la saisie.
- **Ajout rapide** (bouton « + » en bas à droite, sur les pages de liste et d'accueil, pas sur les formulaires, le profil ni les
  paramètres) : il déploie 5 raccourcis, le « + » devient une croix et la page s'assombrit derrière ; Échap, un clic ailleurs ou un
  raccourci le referment ; chaque raccourci mène à la bonne page de création. Sur mobile il reste au-dessus de la barre d'onglets.
- **Messages** : en modifiant le prénom du profil, la confirmation glisse, peut être fermée par la croix, et disparaît seule après 6 s ; un
  message d'erreur ne disparaît pas tout seul.
- **Navigation** : passer d'une page à l'autre se fait en fondu, sans rechargement complet, et sans éclair de thème clair en mode sombre.
- **Changement de thème** : le passage clair ↔ sombre se fait en fondu doux (pas de bascule brutale).
- **Mobile** : pas d'inclinaison au toucher (réservée à la souris) ; la carte de solde et les listes apparaissent normalement ; aucun défilement
  horizontal (essayer aussi à 320 px de large).

**Animations réduites** (Paramètres → Apparence → Animations) :

- *Réduites* : plus aucun mouvement (pas de fondu, de tracé, de compteur, d'inclinaison, de pièce qui tourne) mais **rien ne disparaît** :
  tous les textes, graphiques et cartes sont là, dans leur état final. Les interactions restent possibles (info-bulle, retournement, menu).
  Le choix survit au rechargement.
- *Auto* avec le réglage du système « réduire les animations » activé (Windows : Paramètres → Accessibilité → Effets visuels → Effets
  d'animation) : même résultat. *Complètes* : les animations reviennent malgré ce réglage.
- Sans JavaScript (le désactiver dans le navigateur) : les pages restent complètes et lisibles ; le bouton « + » mène à « Nouvelle
  transaction ».

## Thème clair / sombre

À vérifier **sur toutes les pages** dans les deux thèmes (le test automatique ne voit pas le rendu) :

- **Choix** : Paramètres → Apparence (Auto / Clair / Sombre), ou le bouton lune / soleil à côté du nom dans la barre latérale.
  Le choix actif est mis en évidence ; « Auto » suit le réglage de l'appareil (le changer dans le système bascule l'application
  sans recharger la page).
- **Mémorisation** : recharger la page et ouvrir un nouvel onglet : le thème choisi est conservé, **sans éclair de thème clair**
  au chargement. Un autre navigateur n'est pas affecté (le choix est enregistré dans le navigateur, pas sur le compte).
- **Lisibilité** : aucun texte difficile à lire, y compris les textes secondaires (gris), les montants verts et rouges, les
  badges (« En pause », « Prévue », « Terminée »), les messages d'erreur et de confirmation, les liens.
- **Graphiques** : courbe de l'accueil (grille, textes, points) et donuts (anneau de fond) lisibles ; les couleurs des
  catégories restent distinctes.
- **Formulaires** : champs, listes déroulantes et sélecteurs de date sombres (pas de bloc blanc), focus visible.
- **Calendrier** : cases du mois, jour sélectionné, jour courant et jours hors du mois distincts ; pastilles lisibles.
- **Pages publiques** : connexion, inscription et mot de passe oublié suivent le thème (sans bouton : réglage de l'appareil ou
  choix déjà enregistré).
- **Navigateur mobile** : la barre d'état prend la couleur du fond du thème.
