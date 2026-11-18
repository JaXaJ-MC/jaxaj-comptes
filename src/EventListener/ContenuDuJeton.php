<?php

namespace App\EventListener;

use App\Entity\Compte;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ce que contient le JWT, lu par toutes les applis : le compte, la personne de l'annuaire et
 * les droits en vigueur (« application:role[:perimetre] »).
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
class ContenuDuJeton
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $compte = $event->getUser();
        if (!$compte instanceof Compte) {
            return;
        }
        $donnees = $event->getData();
        $donnees['compte'] = (string) $compte->getId();
        $donnees['personne'] = (string) $compte->getPersonneId();
        $donnees['droits'] = $compte->getDroits();
        $event->setData($donnees);
    }
}
