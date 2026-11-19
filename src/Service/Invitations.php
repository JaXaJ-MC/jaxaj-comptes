<?php

namespace App\Service;

use App\Entity\Compte;
use App\Entity\Jeton;
use App\Repository\CompteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * « Transformer en compte » : crée le compte d'une personne de l'annuaire et lui envoie un
 * lien pour choisir son mot de passe. Gère aussi le mot de passe oublié.
 */
class Invitations
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompteRepository $comptes,
        private Jetons $jetons,
        private MailerInterface $mailer,
        private Expediteurs $expediteurs,
        private string $urlPublique,
    ) {}

    /**
     * @param ?string $expediteur clé de l'adresse d'envoi (mere, bearn…) ; celle par défaut si vide
     * @return array{0: Compte, 1: string} le compte et le lien d'activation
     */
    public function inviter(Uuid $personne, string $email, array $droits = [], ?string $expediteur = null): array
    {
        $expediteur = $this->expediteurs->trouver($expediteur)['cle'];
        if (!$this->comptes->personneExiste($personne)) {
            throw new \InvalidArgumentException('Personne inconnue dans l\'annuaire.');
        }
        $compte = $this->comptes->findOneBy(['personneId' => $personne]);
        if ($compte && $compte->estActive()) {
            throw new \LogicException('Cette personne a déjà un compte actif.');
        }
        $autre = $this->comptes->parEmail($email);
        if ($autre && (!$compte || $autre->getId() != $compte->getId())) {
            throw new \LogicException('Cette adresse e-mail est déjà utilisée par un autre compte.');
        }
        if (!$compte) {
            $compte = new Compte($personne, trim($email));
            $this->em->persist($compte);
        } else {
            $compte->setEmail(trim($email));
        }
        $compte->marquerInvite();
        $compte->setExpediteur($expediteur);
        $this->em->flush();
        $this->comptes->attribuer($compte, $droits);

        $lien = $this->url('/compte/activer/', $this->jetons->creer($compte, Jeton::INVITATION, '+7 days'));
        $this->envoyer($compte, 'Votre compte JaXaJ MC', 'email/invitation.html.twig', $lien);

        return [$compte, $lien];
    }

    /** Envoie un lien de réinitialisation si le compte existe ; ne dit jamais s'il existe. */
    public function motDePasseOublie(string $email): void
    {
        $compte = $this->comptes->parEmail($email);
        if (!$compte || !$compte->estActif()) {
            return;
        }
        $usage = $compte->estActive() ? Jeton::REINITIALISATION : Jeton::INVITATION;
        $chemin = $compte->estActive() ? '/compte/reinitialiser/' : '/compte/activer/';
        $lien = $this->url($chemin, $this->jetons->creer($compte, $usage, '+2 hours'));
        $this->envoyer($compte, 'Réinitialisation de votre mot de passe JaXaJ MC',
            'email/reinitialisation.html.twig', $lien);
    }

    private function url(string $chemin, string $jeton): string
    {
        return rtrim($this->urlPublique, '/').$chemin.$jeton;
    }

    /** Envoie depuis l'adresse qui a invité le compte (ou celle par défaut). */
    private function envoyer(Compte $compte, string $sujet, string $gabarit, string $lien): void
    {
        $expediteur = $this->expediteurs->trouver($compte->getExpediteur());
        $email = (new TemplatedEmail())
            ->from(new Address($expediteur['adresse'], $expediteur['nom']))
            ->to($compte->getEmail())
            ->subject($sujet)
            ->htmlTemplate($gabarit)
            ->context(['lien' => $lien, 'signature' => $expediteur['nom']]);
        $email->getHeaders()->addTextHeader('X-Transport', $expediteur['cle']);
        $this->mailer->send($email);
    }
}
