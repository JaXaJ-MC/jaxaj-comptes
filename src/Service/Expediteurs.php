<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Adresses d'envoi des e-mails, une par association (table compte.expediteur). La clé est
 * aussi le nom du transport Symfony Mailer à utiliser.
 */
class Expediteurs
{
    public function __construct(private Connection $base) {}

    /** @return list<array{cle: string, association: ?string, adresse: string, nom: string, par_defaut: bool}> */
    public function liste(): array
    {
        return array_map(fn (array $e) => $e + ['par_defaut' => (bool) $e['par_defaut']],
            $this->base->fetchAllAssociative(
                'select cle, association_id::text as association, adresse::text as adresse, nom, par_defaut
                 from compte.expediteur order by par_defaut desc, nom'));
    }

    /** L'expéditeur demandé, ou celui par défaut. */
    public function trouver(?string $cle): array
    {
        foreach ($this->liste() as $e) {
            if ($cle ? $e['cle'] === $cle : $e['par_defaut']) {
                return $e;
            }
        }
        throw new \InvalidArgumentException($cle ? "Expéditeur inconnu : « $cle »." : 'Aucun expéditeur par défaut.');
    }
}
