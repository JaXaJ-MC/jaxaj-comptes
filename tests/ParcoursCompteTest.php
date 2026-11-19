<?php

namespace App\Tests;

use App\Service\Invitations;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours complet : invitation, choix du mot de passe, connexion, contenu du JWT,
 * renouvellement, droits d'administration, mot de passe oublié.
 * Chaque test est annulé à la fin (DAMA) : la base de test reste vide.
 */
class ParcoursCompteTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function personne(string $nom): Uuid
    {
        $id = static::getContainer()->get(Connection::class)
            ->fetchOne('select annuaire.creer_personne(:nom, :prenom)', ['nom' => $nom, 'prenom' => 'Test']);

        return Uuid::fromString($id);
    }

    /** Crée un compte par le service et renvoie le lien d'activation. */
    private function inviter(string $email, array $droits = [], ?string $expediteur = null): string
    {
        [, $lien] = static::getContainer()->get(Invitations::class)
            ->inviter($this->personne('P'.random_int(1, 1_000_000)), $email, $droits, $expediteur);

        return parse_url($lien, PHP_URL_PATH);
    }

    private function activer(string $chemin, string $mdp): void
    {
        $this->client->request('POST', $chemin, ['mot_de_passe' => $mdp, 'confirmation' => $mdp]);
        self::assertResponseIsSuccessful();
    }

    private function connexion(string $email, string $mdp): array
    {
        $this->client->jsonRequest('POST', '/api/connexion', ['email' => $email, 'mot_de_passe' => $mdp]);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function contenuJwt(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    public function testInvitationPuisConnexion(): void
    {
        $chemin = $this->inviter('jean@example.org', ['membres:admin']);
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), $chemin);

        // Avant activation : connexion refusée.
        $this->connexion('jean@example.org', 'peu importe');
        self::assertResponseStatusCodeSame(401);

        // Page d'activation, erreurs de saisie, puis mot de passe accepté.
        $this->client->request('GET', $chemin);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p.doux', 'jean@example.org');
        $this->client->request('POST', $chemin, ['mot_de_passe' => 'court', 'confirmation' => 'court']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', $chemin, ['mot_de_passe' => 'une phrase assez longue', 'confirmation' => 'autre chose encore']);
        self::assertResponseStatusCodeSame(422);
        $this->activer($chemin, 'une phrase assez longue');

        // Le lien ne sert qu'une fois.
        $this->client->request('GET', $chemin);
        self::assertResponseStatusCodeSame(410);

        // Connexion : JWT avec la personne et les droits, et jeton de renouvellement.
        $this->connexion('jean@example.org', 'mauvais mot de passe');
        self::assertResponseStatusCodeSame(401);
        $reponse = $this->connexion('JEAN@example.org', 'une phrase assez longue');
        self::assertResponseIsSuccessful();
        $jwt = $this->contenuJwt($reponse['token']);
        self::assertSame(['membres:admin'], $jwt['droits']);
        self::assertTrue(Uuid::isValid($jwt['personne']));
        self::assertLessThanOrEqual(900, $jwt['exp'] - $jwt['iat']);

        $this->client->request('GET', '/api/moi', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$reponse['token']]);
        self::assertResponseIsSuccessful();
        $moi = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('jean@example.org', $moi['email']);
        self::assertNotNull($moi['derniere_connexion']);

        // Renouvellement : le jeton de renouvellement ne sert qu'une fois.
        $this->client->jsonRequest('POST', '/api/connexion/renouveler', ['refresh_token' => $reponse['refresh_token']]);
        self::assertResponseIsSuccessful();
        $nouveau = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotSame($reponse['refresh_token'], $nouveau['refresh_token']);
        $this->client->jsonRequest('POST', '/api/connexion/renouveler', ['refresh_token' => $reponse['refresh_token']]);
        self::assertResponseStatusCodeSame(401);

        // Sans jeton : refusé.
        $this->client->request('GET', '/api/moi');
        self::assertResponseStatusCodeSame(401);
    }

    public function testTransformerEnCompteReserveAuxAdministrateurs(): void
    {
        $this->activer($this->inviter('admin@example.org', ['comptes:admin']), 'mot de passe administrateur');
        $this->activer($this->inviter('simple@example.org', ['membres:accueil']), 'mot de passe utilisateur');
        $admin = $this->connexion('admin@example.org', 'mot de passe administrateur')['token'];
        $simple = $this->connexion('simple@example.org', 'mot de passe utilisateur')['token'];
        $personne = (string) $this->personne('Nouvelle');
        $demande = ['personne' => $personne, 'email' => 'nouvelle@example.org', 'droits' => ['membres:accueil']];

        $this->client->jsonRequest('POST', '/api/comptes', $demande, ['HTTP_AUTHORIZATION' => 'Bearer '.$simple]);
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/comptes', $demande, ['HTTP_AUTHORIZATION' => 'Bearer '.$admin]);
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);
        self::assertSame(['membres:accueil'], json_decode($this->client->getResponse()->getContent(), true)['droits']);

        // Même adresse pour une autre personne : conflit. Personne inconnue : 404. Droit mal formé : 400.
        $autre = ['personne' => (string) $this->personne('Autre'), 'email' => 'NOUVELLE@example.org'];
        $this->client->jsonRequest('POST', '/api/comptes', $autre, ['HTTP_AUTHORIZATION' => 'Bearer '.$admin]);
        self::assertResponseStatusCodeSame(409);
        $this->client->jsonRequest('POST', '/api/comptes', ['personne' => (string) Uuid::v7(), 'email' => 'x@example.org'],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$admin]);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/comptes', ['personne' => $personne, 'email' => 'y@example.org', 'droits' => ['tout']],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$admin]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/comptes', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$admin]);
        self::assertResponseIsSuccessful();
        self::assertCount(3, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testMotDePasseOublie(): void
    {
        $this->activer($this->inviter('oubli@example.org'), 'ancien mot de passe');

        // Adresse inconnue : même réponse, aucun e-mail.
        $this->client->request('POST', '/compte/mot-de-passe-oublie', ['email' => 'inconnu@example.org']);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);

        $this->client->request('POST', '/compte/mot-de-passe-oublie', ['email' => 'oubli@example.org']);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        preg_match('#http://localhost(/compte/reinitialiser/[0-9a-f.]+)#', self::getMailerMessage()->getHtmlBody(), $m);
        $this->activer($m[1], 'nouveau mot de passe');

        $this->connexion('oubli@example.org', 'ancien mot de passe');
        self::assertResponseStatusCodeSame(401);
        $this->connexion('oubli@example.org', 'nouveau mot de passe');
        self::assertResponseIsSuccessful();
    }

    public function testChoixDeLExpediteur(): void
    {
        // L'expéditeur se lit dans l'adresse et le nom affichés, propres à chaque association.
        // (Le transport est choisi par l'en-tête X-Transport, que Symfony retire avant l'envoi.)
        $de = fn (int $i = 0) => self::getMailerMessage($i)->getFrom()[0];

        // Par défaut : l'association mère.
        $this->inviter('defaut@example.org');
        self::assertSame('JaXaJ MC', $de()->getName());
        self::assertSame('a-completer-mere@gmail.com', $de()->getAddress());

        // Invitation envoyée par le Béarn, et le mot de passe oublié repart de la même adresse.
        $this->activer($this->inviter('bearnais@example.org', [], 'bearn'), 'mot de passe béarnais');
        self::assertSame('a-completer-bearn@gmail.com', $de(1)->getAddress());
        self::assertEmailHtmlBodyContains(self::getMailerMessage(1), 'JaXaJ MC Béarn');
        $this->client->request('POST', '/compte/mot-de-passe-oublie', ['email' => 'bearnais@example.org']);
        self::assertSame('a-completer-bearn@gmail.com', $de()->getAddress());

        // Par l'API : liste des expéditeurs, choix de Productions, expéditeur inconnu refusé.
        $this->activer($this->inviter('chef@example.org', ['comptes:admin']), 'mot de passe du chef');
        $admin = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->connexion('chef@example.org', 'mot de passe du chef')['token']];
        $this->client->request('GET', '/api/expediteurs', server: $admin);
        self::assertSame(['mere', 'bearn', 'euskadi', 'productions'],
            array_column(json_decode($this->client->getResponse()->getContent(), true), 'cle'));
        $this->client->jsonRequest('POST', '/api/comptes', ['personne' => (string) $this->personne('Prod'),
            'email' => 'prod@example.org', 'expediteur' => 'productions'], $admin);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('productions', json_decode($this->client->getResponse()->getContent(), true)['expediteur']);
        self::assertSame('a-completer-productions@gmail.com', $de()->getAddress());
        $this->client->jsonRequest('POST', '/api/comptes', ['personne' => (string) $this->personne('X'),
            'email' => 'x@example.org', 'expediteur' => 'paris'], $admin);
        self::assertResponseStatusCodeSame(400);
    }
}
