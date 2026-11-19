# Comptes JaXaJ MC

Brique d'authentification commune à toutes les applications du club. Un compte est toujours celui d'une personne de l'annuaire (`jaxaj-membres`). L'appli délivre des jetons signés (JWT) que les autres applications vérifient avec la clé publique, sans rappeler l'appli comptes.

Symfony 7.4 LTS, PHP 8.4, PostgreSQL (schéma `compte`, à côté du schéma `annuaire`).

## Parcours

1. « Transformer en compte » : un administrateur crée le compte d'une personne de l'annuaire (`POST /api/comptes`) ou, pour le premier, en ligne de commande. Un e-mail part avec un lien valable 7 jours.
2. La personne choisit son mot de passe sur `/compte/activer/<lien>` (12 caractères au moins). Le lien ne sert qu'une fois.
3. Elle se connecte (`POST /api/connexion`) et reçoit un JWT valable 15 minutes. Le jeton de renouvellement (30 jours, à usage unique) arrive dans un cookie `HttpOnly`, `SameSite=Strict`, limité au chemin de connexion : le JavaScript n'y a pas accès.
4. Mot de passe oublié : `/compte/mot-de-passe-oublie` envoie un lien valable deux heures, sans jamais révéler si l'adresse a un compte.

## API

| Méthode | Chemin | Accès | Rôle |
|---|---|---|---|
| POST | `/api/connexion` | public | `{"email", "mot_de_passe"}` → `{"token"}` et cookie de renouvellement ; 5 essais par quart d'heure |
| POST | `/api/connexion/renouveler` | public | avec le cookie → nouveau JWT et nouveau cookie |
| POST | `/api/connexion/deconnexion` | public | invalide le jeton de renouvellement et efface le cookie |
| GET | `/api/moi` | connecté | le compte et ses droits |
| GET | `/api/comptes` | `comptes:admin` | tous les comptes ; `?personne=<uuid>` : le compte d'une personne de l'annuaire, s'il existe |
| GET | `/api/expediteurs` | `comptes:admin` | les adresses d'envoi possibles |
| POST | `/api/comptes` | `comptes:admin` | `{"personne": uuid, "email", "droits": [...], "expediteur": "bearn"}` : crée le compte et envoie l'invitation |

## Ce que contient le JWT (contrat avec les autres applications)

```json
{
  "username": "jean@example.org",
  "compte": "uuid du compte",
  "personne": "uuid de la personne dans l'annuaire",
  "droits": ["membres:admin", "membres:accueil:<uuid de l'association>"],
  "roles": ["ROLE_USER"],
  "iat": 1795000000,
  "exp": 1795000900
}
```

Un droit s'écrit `application:role` ou `application:role:perimetre`, le périmètre étant l'UUID d'une association de l'annuaire. Chaque application décide de ce que signifient ses rôles. Les droits sont datés en base (`compte.attribution`) : un droit expiré disparaît du jeton suivant.

Une application vérifie le jeton avec la clé publique `config/jwt/public.pem` (RS256), sans appel réseau.

## Installation

```sh
composer install
# schémas : jaxaj-membres/sql/01_schema.sql et 02_reference.sql, puis sql/01_schema.sql et sql/02_reference.sql
php bin/console lexik:jwt:generate-keypair   # clés dans config/jwt, jamais versionnées
```

Variables dans `.env.local` (jamais versionné) : `DATABASE_URL`, `JWT_PASSPHRASE`, `URL_PUBLIQUE`, `COOKIE_CHEMIN` (chemin public de la connexion, `/comptes/api/connexion` derrière le préfixe `/comptes`), `COOKIE_SECURISE` (1 en HTTPS), et un `MAILER_DSN_<CLE>` par expéditeur.

Le cookie impose que l'interface et l'appli comptes soient servies sous la même origine (par exemple `https://jaxaj.maxo2.eu/` et `https://jaxaj.maxo2.eu/comptes/`).

Premier administrateur :

```sh
php bin/console compte:inviter moi@example.org --nom=Nom --prenom=Prénom --droit=comptes:admin --droit=membres:admin
```

La commande affiche aussi le lien d'activation, utile tant que l'envoi d'e-mails n'est pas configuré.

### Adresses d'envoi : une par association

Les e-mails partent de l'adresse Gmail de l'association choisie lors de la création du compte : la mère, un chapitre ou JaXaJ Productions. Le compte garde cette adresse, et les e-mails suivants (mot de passe oublié) repartent de la même.

- `compte.expediteur` (rempli par `sql/02_reference.sql`) : pour chaque association, une clé (`mere`, `bearn`, `euskadi`, `productions`), l'adresse et le nom affiché. L'un d'eux est l'expéditeur par défaut (la mère).

  | Clé | Adresse |
  |---|---|
  | `mere` (par défaut) | mc.jaxaj@gmail.com |
  | `bearn` | bearn.jaxaj@gmail.com |
  | `euskadi` | euskadi.jaxaj@gmail.com |
  | `productions` | prod.jaxaj@gmail.com |
- Pour chaque clé, un transport dans `config/packages/mailer.yaml`, dont le DSN est dans `.env.local` : `MAILER_DSN_BEARN=gmail+smtp://adresse%40gmail.com:MOTDEPASSEAPPLICATION@default`. Le mot de passe d'application Google ne va jamais en base ni dans git. Sans DSN, le transport n'envoie rien.
- Choix de l'expéditeur : champ `expediteur` de `POST /api/comptes` (la liste est donnée par `GET /api/expediteurs`), ou option `--expediteur=bearn` de la commande.

Ajouter un expéditeur : une ligne dans `compte.expediteur`, un transport du même nom dans `mailer.yaml` et sa variable `MAILER_DSN_<CLE>`. Une clé sans transport fait échouer l'envoi plutôt que de partir d'une autre adresse.

Mot de passe d'application Google : sur le compte Gmail, activer la validation en deux étapes, puis créer un mot de passe sur https://myaccount.google.com/apppasswords.

## Tests

```sh
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres bin/preparer-tests   # clés et base jaxaj_test
php bin/phpunit
```

Chaque test est annulé à la fin : la base de test reste vide.
