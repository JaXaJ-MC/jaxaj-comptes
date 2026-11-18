<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Lien à usage unique envoyé par e-mail. Seule l'empreinte du vérificateur est stockée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jeton', schema: 'compte')]
class Jeton
{
    public const INVITATION = 'invitation';
    public const REINITIALISATION = 'reinitialisation';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'utilise_le', nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    #[ORM\Column(name: 'cree_le')]
    private \DateTimeImmutable $creeLe;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'compte_id', nullable: false, onDelete: 'CASCADE')]
        private Compte $compte,
        #[ORM\Column]
        private string $usage,
        #[ORM\Column(unique: true)]
        private string $selecteur,
        #[ORM\Column(name: 'verificateur_hash')]
        private string $verificateurHash,
        #[ORM\Column(name: 'expire_le')]
        private \DateTimeImmutable $expireLe,
    ) {
        $this->id = Uuid::v7();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getCompte(): Compte { return $this->compte; }
    public function getUsage(): string { return $this->usage; }
    public function getVerificateurHash(): string { return $this->verificateurHash; }

    public function estValide(): bool
    {
        return null === $this->utiliseLe && $this->expireLe > new \DateTimeImmutable();
    }

    public function consommer(): void { $this->utiliseLe = new \DateTimeImmutable(); }
}
