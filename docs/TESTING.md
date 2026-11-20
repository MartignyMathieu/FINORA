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
- **Prévision de fin de mois** (mois en cours seulement : elle disparaît en changeant de mois avec les flèches) : « Solde prévu » à la fin du
  mois = solde d'aujourd'hui + opérations à venir. Vérifier le calcul : solde prévu = solde aujourd'hui + revenus à venir − dépenses à venir.
  Le jeu de test a des opérations à venir (« Paiement programmé (futur) », récurrences dont l'échéance tombe d'ici la fin du mois) :
  « N opérations à venir » se déplie en liste (récurrences marquées « récurrence prévue »). Les comptes en dollars ne comptent pas.
  - Un solde qui risque de passer sous zéro affiche un avertissement rouge avec le montant le plus bas et sa date ; sinon un message vert.
  - Les soldes de la carte suivent l'œil « Masquer le solde total » ; les revenus et dépenses à venir restent visibles.
- **Masquer le solde total** : l'œil de la carte de solde (mobile) ou de la carte « Solde total » (bureau) remplace le montant par « •••••• » ; l'œil devient barré et le bouton annonce « Afficher le solde total ». Le choix est mémorisé (il tient après rechargement et sur les autres pages) et s'applique aux deux cartes. Revenus, dépenses et épargne du mois restent visibles.

## Comptes (`/account`)

- 5 comptes, un par type : Compte courant, **Livret A (5 512,50 €)** (dont 300 € de virements reçus), Espèces, **Compte voyage en dollars ($)**,
  **Compte à découvert (−350,00 €, le seul solde négatif)**.
- Créer, modifier, supprimer. La suppression d'un compte qui contient des transactions est refusée avec un message.
- Vider un champ obligatoire en modifiant → message d'erreur (pas de page d'erreur).

## Virements (`/transfer`)

- Depuis « Comptes » (icône à deux flèches) ou le bouton « + » (« Nouveau virement ») : la liste est groupée par mois, le plus récent d'abord.
  Le jeu de test contient deux virements de 150 € du Compte courant vers le Livret A (« Épargne du mois »).
- Créer : compte débité, compte crédité, montant, date, note. **Refusés** : deux fois le même compte, deux devises différentes
  (Compte voyage en dollars → un compte en euros), montant nul ou négatif ; le formulaire ne propose que vos comptes.
- Après un virement : le solde du compte débité baisse, celui du compte crédité monte du même montant ; **le solde total, les revenus,
  les dépenses et l'épargne du mois ne bougent pas**, et le virement n'apparaît pas dans la liste des transactions ni dans l'export CSV.
- Modifier ou supprimer un virement (page de modification) rétablit les soldes. Un compte qui a des virements ne peut pas être supprimé
  (message d'erreur). Le compte d'un autre utilisateur est inaccessible (403).

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
- **Filtres avancés** (bouton « Filtres », ouvert dès qu'un critère est actif ; un badge indique le nombre de filtres) :
  - **Période** : champs « Du » / « Au » (bornes incluses) ou boutons rapides « Ce mois-ci », « Mois dernier », « 30 derniers jours », « Cette année ».
  - **Montants** : « Montant min. » / « Montant max. » sur la valeur absolue (une dépense de 85,40 € et un revenu de 85,40 € sont égaux) ;
    les montants ne sont pas convertis (le compte en dollars compte en dollars).
  - **Compte** et **Catégorie** : listes de l'utilisateur uniquement.
  - Tout se combine avec les onglets Toutes / Revenus / Dépenses et la recherche (qui gardent les filtres avancés).
  - Les **pastilles** sous le panneau (« Du 01/11/2026 au 30/11/2026 », « Compte : … ») retirent chacune leur filtre d'un clic ;
    « Réinitialiser » les retire tous.
  - La ligne de **totaux** (« 8 transactions +2 500,00 € −1 168,19 € ») porte sur **toutes** les transactions trouvées, pas seulement
    celles affichées ; les montants ne comptent que les comptes en euros. Au-delà de 300 résultats, une note le signale.
  - Sans résultat : message « ne correspond à ces filtres » avec un lien pour réinitialiser.
  - Valeurs invalides dans l'adresse (`?min=abc`, `?from=31/12/2026`, minimum supérieur au maximum…) : erreur 422.
  - Le bouton d'export CSV reprend **tous** les filtres (période, montants, compte, catégorie, type, recherche).
- Créer, modifier, supprimer ; le formulaire propose uniquement les comptes et catégories de l'utilisateur ;
  montant négatif ou nul refusé.

## Annulation d'une suppression

- Supprimer une **transaction** (page de modification → « Supprimer ») : le message vert « La transaction a été supprimée. » affiche un bouton
  **« Annuler »** et reste 15 secondes (ou jusqu'à la croix). Le total « N transactions » de la liste baisse de 1.
- Cliquer sur « Annuler » : « 1 transaction restaurée. », le total remonte, la transaction est de retour **avec le même contenu** (libellé, montant,
  date, note, compte, catégorie, lien avec sa récurrence) ; seul son identifiant change.
- Même chose pour un **virement** (« 1 virement restauré. ») : les deux soldes retrouvent leurs valeurs.
- Le jeton d'annulation est **à usage unique** (recliquer ou rejouer le lien : « Le délai pour annuler cette suppression est dépassé. »), valable
  **dix minutes**, propre à votre session (un autre utilisateur ne peut pas l'utiliser) ; on garde les cinq dernières suppressions.
- Si le compte ou la catégorie de la ligne a été supprimé entre-temps, elle n'est pas restaurée et le message l'explique.
- Les autres messages de confirmation (enregistrement, modification…) n'ont pas de bouton « Annuler » et disparaissent après 6 secondes.

## Import CSV (`/import/transactions`)

- Accès : icône de téléversement en haut de la page Transactions, ou « Importer des transactions (CSV) » dans le profil.
  Fichier d'essai : `docs/exemple-import.csv` (6 lignes : 5 valides, dont un revenu, et 1 ligne à la date « bientôt », invalide).
- **Étape 1 – Fichier** : .csv ou .txt, 2 Mo et 2 000 lignes au plus. Refusés avec un message : fichier vide, seulement l'en-tête, une seule colonne,
  mauvaise extension (.exe…), fichier qui ressemble à du HTML, plus de 2 000 lignes. Séparateur (`;` `,` tabulation `|`) et encodage (UTF-8,
  Windows-1252) sont détectés ; les accents restent corrects.
- **Étape 2 – Colonnes et aperçu** : les colonnes sont devinées d'après leurs noms (Date, Libellé, Montant, Catégorie…). Choisir le compte de
  destination et la catégorie par défaut, puis « Voir l'aperçu » : **rien n'est enregistré** tant qu'on n'a pas cliqué sur « Importer ».
  - Compteurs : à importer / déjà présentes / invalides ; liste des lignes à corriger avec leur numéro de ligne dans le fichier ;
    catégories qui seront créées ; tableau des 30 premières lignes.
  - Montant négatif = dépense, positif = revenu ; avec une colonne « Type » (Revenu, Dépense, Crédit, Débit…), le montant est pris sans signe.
  - Une colonne « Compte » range chaque ligne dans le bon compte (nom reconnu sans tenir compte des majuscules) ; un nom inconnu, ou le compte
    d'un autre utilisateur, rend la ligne invalide.
  - Dates : `2026-11-20`, `20/11/2026`, `20-11-2026`, `20.11.2026`, avec ou sans heure. Montants : `1 234,56`, `1,234.56`, `1.234,56`, `-42,50`, `42,50 €`, `(42,50)`.
  - Les catégories qui n'existent pas sont créées (case décochable ; sinon la catégorie par défaut est utilisée). Une catégorie existante est reconnue
    sans tenir compte des majuscules.
- **Étape 3 – Import** : message « N transactions importées, … » sur la page Transactions. Tout ou rien : une erreur ne laisse rien d'enregistré.
- **Doublons** : réimporter le même fichier n'ajoute rien (« déjà présentes ») ; décocher « Ignorer les transactions déjà présentes » les ajoute quand même.
  Deux achats identiques le même jour dans un même fichier sont tous les deux importés.
- **Aller-retour** : exporter ses transactions en CSV puis les réimporter (dans un autre compte, par exemple) : les colonnes FINORA sont reconnues.
- Sécurité : un libellé comme `=1+1` ou contenant `<` `>` est conservé comme du texte et affiché échappé ; les données importées n'appartiennent
  qu'à l'utilisateur connecté.

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

## Alertes de budget

- Seuils : à partir de **80 %** du plafond (« à 85 % »), plafond **atteint** (100 % exactement), plafond **dépassé**. Seul le **mois en cours** compte.
- Jeu de test : « Loisirs dépassé » (180 € sur 150 €), « Restaurants atteint » (60 € sur 60 €), « Voyage à 94 % », « Shopping à 85 % » et
  « Alimentation » : **5 budgets à surveiller**. Les plus graves viennent d'abord (dépassé, atteint, puis proche du plafond) ; quatre lignes sont
  affichées, puis « … et 1 autre : voir les budgets ». Chaque ligne mène au budget.
- La bannière rouge (dépassé ou atteint) ou orange (seulement proche du plafond) s'affiche sur **l'accueil** et sur **Budgets** (mois en cours ;
  pas en changeant de mois). Une pastille rouge avec le nombre de budgets à surveiller est à côté de « Budgets » dans le menu et sur l'onglet
  mobile, **sur toutes les pages**.
- La croix ferme la bannière **et la mémorise** : elle reste fermée sur les autres pages et après rechargement. Elle **revient** si un budget
  change de niveau (par exemple en dépensant davantage pour dépasser un budget « à 85 % »). La pastille du menu reste affichée.
- Les budgets des autres mois et ceux des autres utilisateurs ne déclenchent jamais d'alerte ; rien n'est affiché sur les pages publiques.

## Budgets (`/budget`)

- Mois en cours : **Loisirs 180/150 (120 %, barre rouge)**, **Transport 82,50/200 (41 %)**, **Shopping 85/100 (85 %, orange)**,
  **Voyage ≈ 470/500 $ (≈ 94 %, orange, en dollars)**, Alimentation et Restaurants (pourcentage variable selon la date).
- Mois précédent : 3 budgets ; mois suivant : Alimentation à **0 %**.
- Créer un second budget pour la même catégorie et le même mois → refusé.

## Objectifs (`/goal`)

- 7 objectifs : Voiture **64 %**, Vacances **100 %** (atteint), Nouveau PC **0 %** (sans échéance), Voyage aux États-Unis **17 %** ($),
  « dépassé » **100 %** (250 € épargnés pour 100 € visés, barre plafonnée), « en retard » **40 %** (échéance passée),
  un nom très long qui ne doit pas déborder.
- **Versements** : au dos d'une carte (bouton de retournement) ou sur la page de l'objectif, « Verser » / « Verser ou retirer » ouvre un
  formulaire (Versement ou Retrait, montant, date, note). La voiture a un historique de 4 lignes (3 versements, 1 retrait) déjà compris
  dans ses 3 200 € : l'historique ne couvre pas tout le montant épargné.
  - Un versement augmente « Déjà épargné » et l'avancement ; un retrait les baisse. Un retrait supérieur à l'épargne est **refusé**.
  - Verser de quoi atteindre le montant visé affiche « Objectif … atteint, bravo ! » (une seule fois : pas de nouveau message si l'objectif
    était déjà atteint).
  - Supprimer une ligne de l'historique (corbeille) rétablit l'épargne ; elle ne descend jamais sous 0.
  - Supprimer l'objectif supprime son historique. Les objectifs d'un autre utilisateur sont inaccessibles (403).

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

- `/category` : 14 catégories (icône et couleur facultatives). Sur « Nouvelle catégorie » et « Modifier », la couleur se choisit dans 3 palettes (Vives, Douces, Profondes : 8 couleurs chacune) ou « Automatique » ; il n'y a plus de code hexadécimal à saisir. Une catégorie ancienne dont la couleur est hors palette (ex. Shopping, rose) affiche « Couleur actuelle », conservée tant qu'on n'en choisit pas une autre. Un nom déjà utilisé est refusé ; supprimer une catégorie
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
  paramètres) : il déploie 6 raccourcis, le « + » devient une croix et la page s'assombrit derrière ; Échap, un clic ailleurs ou un
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

## Sur téléphone et installation

- Avec `symfony serve -d --allow-all-ip --no-tls` et Docker démarré (`docker compose up -d`), ouvrir `http://<adresse-ip-du-pc>:8000` sur le
  téléphone (même Wi-Fi) : la connexion et toutes les pages fonctionnent. Si la connexion échoue alors que la page de connexion s'affiche,
  la base de données (Docker) n'est probablement pas démarrée.
- *Ajouter à l'écran d'accueil* : l'icône FINORA (le « F » bleu sur fond marine) et le nom « FINORA » apparaissent ; l'ouverture se fait
  en plein écran sur iPhone, dans le navigateur sur Android tant que le site n'est pas en HTTPS.
- Le menu d'appui long de l'icône (Android) propose « Nouvelle transaction », « Budgets » et « Objectifs » (une fois installée).
