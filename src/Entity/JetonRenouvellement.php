<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;

#[ORM\Entity]
#[ORM\Table(name: 'jeton_renouvellement', schema: 'compte')]
class JetonRenouvellement extends RefreshToken
{
}
