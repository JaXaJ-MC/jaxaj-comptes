<?php

namespace App\EventListener;

use App\Entity\Compte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
class DerniereConnexion
{
    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $compte = $event->getUser();
        if ($compte instanceof Compte && 'connexion' === $event->getFirewallName()) {
            $compte->marquerConnexion();
            $this->em->flush();
        }
    }
}
