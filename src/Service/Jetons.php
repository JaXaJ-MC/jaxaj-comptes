<?php

namespace App\Service;

use App\Entity\Compte;
use App\Entity\Jeton;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liens à usage unique « selecteur.verificateur » : le sélecteur retrouve le jeton, le
 * vérificateur (dont seule l'empreinte est stockée) prouve qu'on détient le lien.
 */
class Jetons
{
    public function __construct(private EntityManagerInterface $em) {}

    public function creer(Compte $compte, string $usage, string $duree): string
    {
        // Un nouveau lien invalide les précédents du même usage.
        $this->em->createQuery('UPDATE App\Entity\Jeton j SET j.utiliseLe = CURRENT_TIMESTAMP()
                                WHERE j.compte = :c AND j.usage = :u AND j.utiliseLe IS NULL')
            ->execute(['c' => $compte, 'u' => $usage]);

        $selecteur = bin2hex(random_bytes(12));
        $verificateur = bin2hex(random_bytes(24));
        $this->em->persist(new Jeton($compte, $usage, $selecteur, hash('sha256', $verificateur),
            new \DateTimeImmutable($duree)));
        $this->em->flush();

        return $selecteur.'.'.$verificateur;
    }

    /** Le jeton correspondant au lien, s'il est valide pour cet usage. */
    public function verifier(string $lien, string $usage): ?Jeton
    {
        [$selecteur, $verificateur] = array_pad(explode('.', $lien, 2), 2, '');
        $jeton = $this->em->getRepository(Jeton::class)->findOneBy(['selecteur' => $selecteur]);
        if (!$jeton || $jeton->getUsage() !== $usage || !$jeton->estValide()
            || !hash_equals($jeton->getVerificateurHash(), hash('sha256', $verificateur))) {
            return null;
        }

        return $jeton;
    }
}
