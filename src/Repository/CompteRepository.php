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

    /**
     * Les droits en vigueur deviennent exactement ceux donnés : les autres prennent fin hier (ou
     * disparaissent s'ils dataient d'aujourd'hui), les nouveaux commencent aujourd'hui.
     */
    public function definirDroits(Compte $compte, array $droits): void
    {
        $connexion = $this->getEntityManager()->getConnection();
        $retires = array_diff($this->chargerDroits($compte)->getDroits(), $droits);
        foreach ($retires as $droit) {
            $condition = "compte_id = :compte and periode @> current_date
                and application || ':' || role || coalesce(':' || perimetre::text, '') = :droit";
            $parametres = ['compte' => (string) $compte->getId(), 'droit' => $droit];
            $connexion->executeStatement("delete from compte.attribution where $condition and lower(periode) >= current_date", $parametres);
            $connexion->executeStatement("update compte.attribution set periode = daterange(lower(periode), current_date) where $condition", $parametres);
        }
        $this->attribuer($compte, $droits);
    }

    public function personneExiste(Uuid $personne): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'select 1 from annuaire.personne where id = :id', ['id' => (string) $personne]);
    }
}
