<?php

namespace App\Repository;

use App\Entity\Compte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Compte>
 */
class CompteRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Compte::class);
    }

    /** Chargement par le pare-feu : le compte arrive avec ses droits, donc ses rôles. */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->parEmail($identifier);
    }

    public function parEmail(string $email): ?Compte
    {
        $compte = $this->createQueryBuilder('c')
            ->where('LOWER(c.email) = LOWER(:email)')
            ->setParameter('email', trim($email))
            ->getQuery()->getOneOrNullResult();

        return $compte ? $this->chargerDroits($compte) : null;
    }

    public function chargerDroits(Compte $compte): Compte
    {
        $droits = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'select droit from compte.droit_en_vigueur where compte_id = :id order by droit',
            ['id' => (string) $compte->getId()],
        );
        $compte->setDroits($droits);

        return $compte;
    }

    /** Ajoute des droits « application:role[:perimetre] » à partir d'aujourd'hui. */
    public function attribuer(Compte $compte, array $droits): void
    {
        $connexion = $this->getEntityManager()->getConnection();
        foreach ($droits as $droit) {
            [$application, $role, $perimetre] = array_pad(explode(':', $droit, 3), 3, null);
            $connexion->executeStatement(
                'insert into compte.attribution (compte_id, application, role, perimetre)
                 select :compte, :application, :role, cast(:perimetre as uuid)
                 where not exists (
                   select 1 from compte.droit_en_vigueur where compte_id = :compte and droit = :droit)',
                ['compte' => (string) $compte->getId(), 'application' => $application, 'role' => $role,
                 'perimetre' => $perimetre, 'droit' => $droit],
            );
        }
        $this->chargerDroits($compte);
    }

    public function personneExiste(Uuid $personne): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'select 1 from annuaire.personne where id = :id', ['id' => (string) $personne]);
    }
}
