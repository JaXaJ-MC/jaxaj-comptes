<?php

namespace App\Controller;

use App\Entity\Jeton;
use App\Service\Invitations;
use App\Service\Jetons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Pages ouvertes depuis les liens reçus par e-mail. */
#[Route('/compte')]
class MotDePasseController extends AbstractController
{
    private const LONGUEUR_MIN = 12;

    public function __construct(
        private Jetons $jetons,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hacheur,
    ) {}

    #[Route('/activer/{lien}', methods: ['GET', 'POST'])]
    public function activer(string $lien, Request $requete): Response
    {
        return $this->choisir($lien, Jeton::INVITATION, $requete, 'Choisissez votre mot de passe');
    }

    #[Route('/reinitialiser/{lien}', methods: ['GET', 'POST'])]
    public function reinitialiser(string $lien, Request $requete): Response
    {
        return $this->choisir($lien, Jeton::REINITIALISATION, $requete, 'Nouveau mot de passe');
    }

    #[Route('/mot-de-passe-oublie', methods: ['GET', 'POST'])]
    public function oublie(Request $requete, Invitations $invitations): Response
    {
        if ($requete->isMethod('POST')) {
            $invitations->motDePasseOublie((string) $requete->request->get('email'));

            return $this->render('compte/message.html.twig', ['titre' => 'E-mail envoyé',
                'message' => 'Si un compte existe pour cette adresse, un lien vient de lui être envoyé. Il est valable deux heures.']);
        }

        return $this->render('compte/oublie.html.twig');
    }

    private function choisir(string $lien, string $usage, Request $requete, string $titre): Response
    {
        $jeton = $this->jetons->verifier($lien, $usage);
        if (!$jeton) {
            return $this->render('compte/message.html.twig', ['titre' => 'Lien expiré',
                'message' => 'Ce lien n\'est plus valable. Demandez-en un nouveau.', 'oublie' => true], new Response('', 410));
        }
        $erreur = null;
        if ($requete->isMethod('POST')) {
            $mdp = (string) $requete->request->get('mot_de_passe');
            if (mb_strlen($mdp) < self::LONGUEUR_MIN) {
                $erreur = sprintf('Au moins %d caractères.', self::LONGUEUR_MIN);
            } elseif ($mdp !== $requete->request->get('confirmation')) {
                $erreur = 'Les deux saisies ne correspondent pas.';
            } else {
                $compte = $jeton->getCompte();
                $compte->definirMotDePasse($this->hacheur->hashPassword($compte, $mdp));
                $jeton->consommer();
                $this->em->flush();

                return $this->render('compte/message.html.twig', ['titre' => 'Mot de passe enregistré',
                    'message' => 'Vous pouvez maintenant vous connecter aux applications du club avec '.$compte->getEmail().'.']);
            }
        }

        return $this->render('compte/mot_de_passe.html.twig', ['titre' => $titre, 'erreur' => $erreur,
            'email' => $jeton->getCompte()->getEmail(), 'longueur' => self::LONGUEUR_MIN],
            new Response('', $erreur ? 422 : 200));
    }
}
