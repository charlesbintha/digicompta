# Digi-Compta

Première version de développement d’un SaaS de facturation pour les petites entreprises du Sénégal. Laravel 13, React 19, Vite 8 et PostgreSQL. Interface française, devise XOF (montants entiers), fuseau Africa/Dakar.

## Lancer en local

Prérequis : PHP 8.3+ avec PDO PostgreSQL, Composer, Node compatible Vite 8 et PostgreSQL. Les versions installées et utilisées ici sont PHP 8.5, Node 25 et PostgreSQL 14.

```sh
cd backend
composer install
npm ci
cp .env.example .env
php artisan key:generate
# Renseigner les accès PostgreSQL dans .env avant les migrations.
php artisan migrate
# Optionnel, uniquement APP_ENV=local : données fictives.
php artisan db:seed --class=DemoSeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Ouvrir http://127.0.0.1:8000. Pour recompiler automatiquement l’interface, lancer `npm run dev` dans un second terminal.

Dans l’environnement de travail actuel, `.env` est déjà configuré et la base est initialisée sur le port 55432. Le cluster local dédié est situé dans `../.local/postgres`. Pour le redémarrer si nécessaire, depuis la racine du projet :

```sh
pg_ctl -D "$PWD/.local/postgres" -l "$PWD/.local/postgres.log" -o '-h 127.0.0.1 -p 55432 -k /private/tmp' start
```

Compte fictif local : `demo@digicompta.test` / `Demo-Senegal-2026!`. Le seeder refuse les environnements autres que local/testing et ne remplace pas un compte existant. L’inscription crée un espace vide indépendant.

## Fonctionnalités implémentées

- Inscription d’une entreprise, connexion/déconnexion et sessions protégées par CSRF ; limitation de fréquence des connexions et inscriptions.
- Accès aux données filtré systématiquement par la société de la session et l’appartenance de l’utilisateur. L’identifiant d’entreprise envoyé dans un formulaire n’est jamais une source d’autorisation.
- Création, modification et archivage de clients, fournisseurs, produits et services ; recherche côté interface. Les fiches archivées sont exclues des nouvelles opérations.
- Création et modification des factures en brouillon avec plusieurs lignes, taxe saisie explicitement, calculs entiers en XOF et arrondi de la taxe au franc le plus proche. Une facture en validation ou émise est verrouillée contre les modifications.
- Circuits de validation par entreprise : 1 à 5 étapes ordonnées par rôle, seuil inclusif sur le montant TTC, interdiction optionnelle de l’auto-validation et validateurs distincts. Sous le seuil, une validation standard reste requise. Le circuit et ses règles sont figés lors de la soumission ; les changements de configuration ne réécrivent pas les demandes en attente. Numérotation sous verrou transactionnel uniquement après la dernière validation.
- Rejet motivé, correction du brouillon et nouvelle soumission avec un nouveau cycle ; historique des décisions conservé. Un numéro de cycle et d’étape protège les décisions contre les doubles clics et requêtes périmées. Les instantanés vendeur/client sont actualisés lors de la modification du brouillon puis conservés.
- Règlements manuels partiels ou complets, limite au solde restant, clé d’idempotence et verrou transactionnel. Aucun paiement bancaire/mobile n’est déclenché.
- Vue détaillée et impression / enregistrement PDF via le navigateur.
- Enregistrement de dépenses déjà payées, validation/rejet avant comptabilisation, tableau de bord incluant les avoirs et les règlements fournisseurs.
- Quatre rôles prédéfinis conservés et rôles personnalisables par entreprise : permissions granulaires de consultation, écriture et administration. La réponse métier du serveur exclut les modules non autorisés, et leur navigation est masquée. Les dépendances entre permissions sont validées côté serveur et cochées automatiquement dans l’interface.
- Attribution et modification de rôle, désactivation/réactivation des collaborateurs. Les requêtes suivantes d’une session existante tiennent compte des nouveaux droits. Le propriétaire et son accès sont protégés ; personne ne peut déléguer ou modifier des droits supérieurs aux siens. Les rôles prédéfinis restent immuables, mais peuvent être copiés.
- Ajout de collaborateurs avec mot de passe initial saisi par le propriétaire. Aucun email d’invitation n’est envoyé.
- Paramètres entreprise : nom, adresse, NINEA et RCCM. Journal métier consultable des 50 dernières actions.
- Super administration : entreprises, offres/prix/quotas/modules, promotions, demandes d’abonnement, confirmation manuelle des paiements, tickets/SLA et journal global.

Le compte démo n’a pas accès à la super administration. Pour désigner explicitement un compte de plateforme existant :

```sh
php artisan digi:grant-admin adresse@example.com
```

## Vérifications

```sh
php artisan test --compact
npm run build
vendor/bin/pint --format agent
```

Les tests métier vérifient les inscriptions, l’isolation entre entreprises, les rôles personnalisés, les droits de lecture, les dépendances de permissions, les protections contre l’escalade, les désactivations, les circuits par seuil, l’ordre des validations, les approbateurs distincts, l’interdiction d’auto-validation, la conservation des circuits en attente, les rejets/corrections et décisions périmées, les montants/arrondis, les paiements excessifs et répétés, les suspensions et l’audit. Ils utilisent SQLite en mémoire par défaut. Pour PostgreSQL, créer une base **réservée aux tests**, puis :

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_USERNAME=digi DB_DATABASE=digi_compta_test php artisan test --compact tests/Feature
```

`RefreshDatabase` remet les tables de la base de test à zéro. Ne jamais viser une base contenant des données à conserver.

## Configurer les accès et validations

Dans **Équipe & accès → Rôles & permissions**, créer un rôle ou copier un modèle, puis l’attribuer à un collaborateur. Les rôles standards conservent leurs droits de lecture initiaux ; utiliser un rôle personnalisé pour limiter les modules visibles. Le rôle propriétaire ne se transfère pas depuis cette interface. Un responsable ne peut pas modifier son propre rôle personnalisé ni son propre accès.

Dans **Validations**, activer le circuit, définir le seuil TTC en FCFA et ordonner les rôles attendus. Chaque rôle d’étape doit disposer de `invoices.approve`. Le propriétaire ne contourne pas une étape destinée à un autre rôle. La soumission vérifie qu’il existe assez de validateurs actifs ; une désactivation ou réaffectation ultérieure peut nécessiter de réattribuer le rôle attendu pour débloquer une facture. Retirer le droit de validation d’un rôle utilisé dans un circuit actif ou une facture en attente est refusé.

Le circuit est désactivé par défaut pour préserver le comportement initial. La migration conserve les factures existantes en attente sur une étape standard. L’historique détaillé démarre avec cette version et ne reconstitue pas les décisions anciennes.

API : `GET /api/v1/access`, `POST /api/v1/roles`, `PUT /api/v1/roles/{id}`, `PUT /api/v1/members/{id}`, `PUT /api/v1/invoice-workflow`, `PUT /api/v1/invoices/{id}`. Les décisions `approve` et `reject` sur `/invoices/{id}/transition` exigent les valeurs `round` et `step` renvoyées par la facture ; un rejet exige aussi `comment`. Seules les données validées de la facture sont modifiables ; son créateur, sa société et son historique restent inchangés.

## Modules commerciaux et comptables

- **Devis** : lignes et taxe explicite, acceptation/refus, validité et conversion idempotente en facture brouillon, impression/PDF. La décision client est enregistrée par un collaborateur ; ce n’est pas une signature électronique.
- **Achats fournisseurs** : référence unique par fournisseur, saisie HT/taxe, validation, paiements partiels ou complets et historique. La saisie des achats et celle des dépenses payées sont distinctes : ne pas enregistrer deux fois la même charge.
- **Avoirs et remboursements** : correction partielle ou totale, y compris après encaissement, prorata HT/taxe en entiers et écritures inverses des ventes. Le formulaire d’avoir autorise les montants encaissés (`include_paid=true`) ; le crédit client disponible apparaît dans Remboursements. Enregistrer le paiement effectué avec une référence et une clé d’idempotence : montant plafonné au crédit, date postérieure aux avoirs/encaissements, débit client et crédit banque/caisse. Aucun virement réel n’est initié.
- **Stocks** : les lignes de facture liées à un produit du catalogue déstockent à la dernière validation, dans la même transaction que la facture et la comptabilité. Les lignes libres et services ne modifient pas le stock. Des lignes répétées du même produit sont agrégées ; un stock insuffisant annule toute la validation sans consommer de numéro. Les achats peuvent réceptionner un produit et une quantité à leur validation. Les retours physiques sont enregistrés depuis Retours produits, plafonnés à la quantité vendue non retournée, sans double saisie. L’avoir financier et le retour physique sont des opérations distinctes. Les ajustements manuels restent disponibles.
- **Brouillons** : archivage et restauration depuis Brouillons & archives. Les factures actives masquent les archives, la soumission et la modification d’un brouillon archivé sont interdites. Les archives ne bloquent pas une clôture ; après restauration, une opération reste soumise aux dates comptables autorisées.
- **Comptabilité** : plan de comptes, correspondances initiales, écritures équilibrées, journaux, balance, grand livre filtrable par période/compte, export CSV protégé contre les formules, extourne des écritures manuelles et clôture irréversible. Les correspondances sont figées après la première écriture. Les dates clôturées sont bloquées à la saisie et à la comptabilisation.
- Les factures, achats, règlements, avoirs et dépenses validés alimentent les journaux dans la même transaction. **Comptabiliser l’historique** reprend les factures/règlements/dépenses antérieurs à cette migration sans doublons. La clôture effectue cette reprise et refuse les opérations en attente dans la période.

Les comptes proposés constituent un socle paramétrable inspiré du [guide d’application SYSCOHADA](https://www.ohada.com/uploads/actualite/3504/Guide-d-application-du-SYSCOHADA.pdf). La qualification des charges, taxes et comptes doit être validée pour chaque entreprise. Cette application ne produit pas encore les états fiscaux réglementaires et ne revendique aucune certification comptable.

## Rapprochement bancaire et états de gestion

**Banque & rapprochements** permet d’importer un relevé pour un compte bancaire de classe 52. Renseigner la référence, la période et les soldes initial/final, puis coller les mouvements sous la forme `AAAA-MM-JJ;libellé;montant signé`, sans en-tête, une ligne par mouvement (maximum 2 000). Les montants sont entiers en XOF, positifs pour les entrées et négatifs pour les sorties. Le solde initial plus les mouvements doit égaler le solde final. Les périodes actives ne peuvent pas se chevaucher pour un même compte.

Associer chaque mouvement à une ligne comptable de même compte, période et montant signé. Une écriture ne peut être utilisée qu’une fois. La finalisation exige que toutes les lignes et écritures de la période soient rapprochées et que les soldes initial/final concordent. Les associations deviennent alors définitives et toute nouvelle écriture sur ce compte à une date antérieure ou égale à la fin du relevé est refusée. Les corrections se comptabilisent à une date ultérieure. Un relevé **en cours** erroné peut être annulé : il reste dans l’historique, ses associations sont libérées et un remplacement peut être importé avec une nouvelle référence. Le rapprochement est strict, un mouvement pour une ligne comptable ; les regroupements bancaires doivent être ventilés avant import.

**États de gestion** présente, pour la période choisie, les produits des comptes de classe 7, les charges de classe 6, leur différence, et les soldes de chaque classe. Les comptes de classe 8 sont affichés séparément. La balance de la période est exportable en CSV. Ces états ne remplacent pas les formulaires fiscaux réglementaires.

Les permissions `banking.read`, `banking.write`, `refunds.read` et `refunds.write` sont ajoutées au catalogue et attribuées au propriétaire/comptable. Elles sont configurables dans les rôles personnalisés. Les remboursements dépendent du module commerce ; les rapprochements du module comptabilité. Les décaissements du tableau de bord incluent les remboursements clients. Une période d’accès expirée est signalée dans l’interface, les écritures métier restent bloquées côté serveur.

## Abonnements et support

Les trois offres initiales sont masquées et sans prix commercial imposé. Le super administrateur configure puis publie les offres. Chaque offre définit des modules, un quota de membres actifs, un quota de factures créées par mois civil et des objectifs SLA. Le support et le renouvellement restent accessibles indépendamment des modules. Un essai ou abonnement expiré interdit les écritures métier ; une suspension bloque aussi la lecture métier tout en permettant le support et l’abonnement.

Les demandes de souscription figent le prix et la réduction. Un code promotionnel ne peut être utilisé qu’une fois par entreprise ; une demande en attente réserve l’utilisation et son annulation la libère. Pourcentage ou réduction XOF, période de validité, plafond d’utilisations et restriction d’offre sont contrôlés sous verrou. La confirmation par le super administrateur exige une référence du règlement vérifié et active **30 jours**, ou prolonge une offre identique encore active. Wave Checkout est intégré pour les abonnements : lancement du paiement par le client, confirmation HMAC du webhook, ou vérification depuis l’interface, puis activation unique de 30 jours. Chaque renouvellement demande une validation du client ; aucun prélèvement récurrent automatique. Les encaissements des factures clients restent manuels.

### Configuration Wave (en attente des identifiants)

Le connecteur reste désactivé par défaut. Utiliser le compte **Wave Business de la plateforme**, avec accès à Checkout, et renseigner côté serveur :

- `APP_URL=https://votre-domaine` : adresse publique HTTPS de l’application.
- `WAVE_API_KEY` : clé Checkout, jamais exposée au navigateur.
- `WAVE_SIGNING_SECRET` : secret de signature des requêtes si cette option a été activée sur la clé Wave.
- `WAVE_WEBHOOK_SECRET` : secret de signature HMAC du webhook (distinct de la clé API).
- `WAVE_ENABLED=true` : seulement après configuration et vérification du compte.

Dans le portail Wave Business, enregistrer `https://votre-domaine/api/v1/webhooks/wave`, choisir la signature HMAC et l’événement `checkout.session.completed`. La route est publique avec vérification de signature et d’horodatage ; seule cette route est exemptée de la protection CSRF. Après mise à jour de la configuration, reconstruire le cache Laravel si utilisé. Documentation officielle : https://docs.wave.com/checkout et https://docs.wave.com/webhook.

Les montants et réductions proviennent exclusivement de la commande serveur. La confirmation vérifie la référence, la session, la devise et le montant ; un retour navigateur ne valide jamais un paiement. Un abonnement suspendu reste suspendu après paiement. Les doublons ne prolongent pas plusieurs fois l’abonnement. Aucun email, push ou notification interne n’est émis par ce parcours.

Après une réponse réseau incertaine, la référence de tentative reste enregistrée et la reprise recherche la session existante chez Wave, sans créer de nouveau paiement. Si Wave ne retrouve aucune session, le support doit rapprocher la tentative avec Wave avant intervention ; aucune nouvelle session automatique n’est créée sur cette commande. Une session confirmée expirée peut être annulée par le super administrateur puis remplacée par une nouvelle demande. Une demande Wave en cours bloque la confirmation et l’annulation manuelles. Les demandes à zéro FCFA restent validées manuellement.

Les tests utilisent exclusivement des réponses Wave simulées ; un essai de paiement réel contrôlé reste nécessaire après configuration du compte et du domaine HTTPS.

Les tickets regroupent bugs, questions et facturation, avec conversation, affectation et suivi de statut. Les délais sont figés à l’ouverture selon l’offre, en heures continues : priorité haute = moitié du délai, critique = quart. La première réponse du support et la résolution sont horodatées. Le contrôle SLA produit une alerte par ticket en retard ; une réponse à un ticket résolu le rouvre. Les listes support et plateforme affichent les 200 éléments les plus récents.

## SMTP, emails et notifications push

**Mis en attente à la demande du propriétaire du projet (24 octobre 2026).** Aucun worker d’envoi ni canal externe n’a été activé pendant cette livraison. Les instructions suivantes décrivent la configuration future.

Dans **Emails**, renseigner le serveur SMTP public de l’entreprise : STARTTLS sur 587 ou TLS direct sur 465, identifiant, mot de passe, adresse et nom d’expéditeur. Le secret est chiffré avec `APP_KEY`, absent des réponses API et jamais affiché. Sauvegarder cette clé avec les sauvegardes ; sa perte rend les secrets indéchiffrables. Le serveur est résolu et sa connexion épinglée à une IPv4 publique ; les certificats TLS sont vérifiés. Les SMTP internes, IPv6 seuls et OAuth ne sont pas pris en charge par cet écran.

L’envoi d’une facture utilise son adresse client figée et son contenu textuel, depuis le SMTP de l’entreprise. Le PDF reste imprimable dans le navigateur ; aucune pièce jointe PDF n’est produite par la file d’envoi. Le bouton de test envoie seulement à l’utilisateur connecté. L’historique distingue file/envoi/acceptation SMTP/échec ; la relance d’un échec est explicite car une rupture réseau peut laisser la livraison incertaine. La révocation du collaborateur ou du module bloque les emails encore en attente.

Démarrer les processus de fond dans des terminaux séparés, ou sous un gestionnaire de processus en production :

```sh
php artisan queue:work --sleep=3 --tries=1 --timeout=60
php artisan schedule:work
```

En production, le scheduler peut être exécuté chaque minute via `php artisan schedule:run`. Les contrôles SLA sont planifiés toutes les cinq minutes. Le worker utilise la file Laravel configurée par `QUEUE_CONNECTION=database` ; sans worker, les messages restent en attente.

Les notifications internes concernent les validations, les abonnements et le support. Le push est optionnel, nécessite HTTPS (localhost accepté par les navigateurs), un navigateur compatible et l’accord de l’utilisateur. Configurer les clés sans afficher le secret :

```sh
php artisan digi:vapid-init --subject=mailto:admin@votre-domaine.sn
```

Cette commande refuse d’écraser une clé existante. Redémarrer les workers après configuration. Le centre **Notifications** permet d’activer/désactiver le push ; les adresses techniques d’abonnement sont chiffrées. Les notifications système contiennent un avis générique, sans données financières. Les fournisseurs autorisés sont FCM, Mozilla, Apple et Windows Push. Aucun SMTP réel ni consentement push n’est configuré par défaut.

## Vérifications et périmètre de livraison

La suite comprend les tests d’isolation, droits, circuits, calculs monétaires et les scénarios des nouveaux modules : équilibre comptable, reprise idempotente, clôture, extourne, devis, règlements fournisseurs, avoirs, stock, expiration, quotas, promotions, SLA, chiffrement SMTP/push et erreurs de livraison sans secret. Les tests de transport n’envoient aucun email réel. Les nouveaux tests couvrent le déstockage transactionnel, les réceptions/retours, les remboursements après avoir, les archives, l’import/association/annulation/finalisation des relevés, les protections contre les écritures rétroactives et les accès entre entreprises. Les essais navigateur utilisent uniquement l’entreprise fictive.

Il s’agit d’une version locale fonctionnelle des modules, pas d’un déploiement commercial. Restent hors de cette livraison : encaissement automatique des abonnements (prestataire et accès non fournis), états fiscaux officiels, invitations/récupération de compte par email et permissions limitées à ses propres données ou à un établissement. Les validations multiétapes paramétrables concernent les factures ; les achats/dépenses utilisent une approbation dédiée par permission.

Avant exploitation : validation comptable/fiscale sénégalaise, infrastructure HTTPS et cookies sécurisés, `APP_DEBUG=false`, sauvegarde/restauration PostgreSQL et `APP_KEY`, supervision des workers et du scheduler, pagination/agrégats serveur (les listes métier chargent encore les données de l’entreprise), durcissement de l’administration et tests de charge. Le serveur PHP local reste lié à 127.0.0.1.

Références techniques : [authentification Laravel](https://laravel.com/framework/docs/13.x/authentication), [emails Laravel](https://laravel.com/framework/docs/mail), [notifications Laravel](https://laravel.com/framework/docs/13.x/notifications), [Web Push PHP](https://github.com/web-push-libs/web-push-php), [Vite](https://vite.dev/guide/build).
