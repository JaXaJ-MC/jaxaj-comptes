<?php

namespace App\Security;

use App\Entity\Compte;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Refuse les comptes désactivés et ceux dont l'invitation n'a pas été acceptée. */
class VerificateurCompte implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Compte) {
            return;
        }
        if (!$user->estActif()) {
            throw new CustomUserMessageAccountStatusException('Compte désactivé.');
        }
        if (!$user->estActive()) {
            throw new CustomUserMessageAccountStatusException('Compte pas encore activé : utilisez le lien reçu par e-mail.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void {}
}
