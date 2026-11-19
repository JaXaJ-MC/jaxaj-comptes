<?php

namespace App\Entity;

use App\Repository\CompteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compte de connexion d'une personne de l'annuaire, commun à toutes les applis du club.
 */
#[ORM\Entity(repositoryClass: CompteRepository::class)]
#[ORM\Table(name: 'compte', schema: 'compte')]
class Compte implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'mot_de_passe', nullable: true)]
    private ?string $motDePasse = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(name: 'invite_le', nullable: true)]
    private ?\DateTimeImmutable $inviteLe = null;

    #[ORM\Column(name: 'active_le', nullable: true)]
    private ?\DateTimeImmutable $activeLe = null;

    #[ORM\Column(name: 'derniere_connexion', nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    /** Clé de l'expéditeur (compte.expediteur) qui a envoyé l'invitation. */
    #[ORM\Column(nullable: true)]
    private ?string $expediteur = null;

    #[ORM\Column(name: 'cree_le')]
    private \DateTimeImmutable $creeLe;

    /** Droits en vigueur (« application:role[:perimetre] »), chargés par le dépôt. */
    private array $droits = [];

    public function __construct(
        #[ORM\Column(name: 'personne_id', type: 'uuid', unique: true)]
        private Uuid $personneId,
        #[ORM\Column(unique: true)]
        private string $email,
    ) {
        $this->id = Uuid::v7();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPersonneId(): Uuid { return $this->personneId; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function estActif(): bool { return $this->actif; }
    public function setActif(bool $actif): void { $this->actif = $actif; }
    public function estActive(): bool { return null !== $this->motDePasse; }
    public function getInviteLe(): ?\DateTimeImmutable { return $this->inviteLe; }
    public function getActiveLe(): ?\DateTimeImmutable { return $this->activeLe; }
    public function getDerniereConnexion(): ?\DateTimeImmutable { return $this->derniereConnexion; }

    public function marquerInvite(): void { $this->inviteLe = new \DateTimeImmutable(); }
    public function marquerConnexion(): void { $this->derniereConnexion = new \DateTimeImmutable(); }

    public function definirMotDePasse(string $empreinte): void
    {
        $this->motDePasse = $empreinte;
        $this->activeLe ??= new \DateTimeImmutable();
    }

    public function getExpediteur(): ?string { return $this->expediteur; }
    public function setExpediteur(?string $cle): void { $this->expediteur = $cle; }

    public function getDroits(): array { return $this->droits; }
    public function setDroits(array $droits): void { $this->droits = $droits; }

    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): ?string { return $this->motDePasse; }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if (in_array('comptes:admin', $this->droits, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function eraseCredentials(): void {}
}
