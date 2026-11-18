# Comptes JaXaJ MC

Brique d'authentification commune à toutes les applications du club. Un compte est toujours celui d'une personne de l'annuaire (`jaxaj-membres`). L'appli délivre des jetons signés (JWT) que les autres applications vérifient avec la clé publique, sans rappeler l'appli comptes.

Symfony 7.4 LTS, PHP 8.4, PostgreSQL (schéma `compte`, à côté du schéma `annuaire`).

## Parcours

1. « Transformer en compte » : un administrateur crée le compte d'une personne de l'annuaire (`POST /api/comptes`) ou, pour le premier, en ligne de commande. Un e-mail part avec un lien valable 7 jours.
2. La personne choisit son mot de passe sur `/compte/activer/<lien>` (12 caractères au moins). Le lien ne sert qu'une fois.
3. Elle se connecte (`POST /api/connexion`) et reçoit un JWT valable 15 minutes et un jeton de renouvellement valable 30 jours, à usage unique.
4. Mot de passe oublié : `/compte/mot-de-passe-oublie` envoie un lien valable deux heures, sans jamais révéler si l'adresse a un compte.

## API

| Méthode | Chemin | Accès | Rôle |
|---|---|---|---|
| POST | `/api/connexion` | public | `{"email", "mot_de_passe"}` → `{"token", "refresh_token"}` ; 5 essais par quart d'heure |
| POST | `/api/connexion/renouveler` | public | `{"refresh_token"}` → nouveau couple de jetons |
| GET | `/api/moi` | connecté | le compte et ses droits |
| GET | `/api/comptes` | `comptes:admin` | tous les comptes |
| POST | `/api/comptes` | `comptes:admin` | `{"personne": uuid, "email", "droits": [...]}` : crée le compte et envoie l'invitation |

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
# schémas : jaxaj-membres/sql/01_schema.sql et 02_reference.sql, puis sql/01_schema.sql
php bin/console lexik:jwt:generate-keypair   # clés dans config/jwt, jamais versionnées
```

Variables dans `.env.local` (jamais versionné) : `DATABASE_URL`, `JWT_PASSPHRASE`, `MAILER_DSN`, `MAILER_EXPEDITEUR`, `URL_PUBLIQUE`.

Premier administrateur :

```sh
php bin/console compte:inviter moi@example.org --nom=Nom --prenom=Prénom --droit=comptes:admin --droit=membres:admin
```

La commande affiche aussi le lien d'activation, utile tant que l'envoi d'e-mails n'est pas configuré.

### Envoi des e-mails par un compte Gmail

`MAILER_DSN=gmail+smtp://adresse%40gmail.com:MOTDEPASSEAPPLICATION@default`, avec un mot de passe d'application Google (compte Google avec la validation en deux étapes, puis « Mots de passe des applications »). `MAILER_EXPEDITEUR` doit être la même adresse Gmail.

## Tests

```sh
PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres bin/preparer-tests   # clés et base jaxaj_test
php bin/phpunit
```

Chaque test est annulé à la fin : la base de test reste vide.
