<?php

namespace App\Command;

use App\Service\Invitations;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Crée un compte depuis la ligne de commande, notamment le premier administrateur :
 *   bin/console compte:inviter moi@example.org --nom=Michel --prenom=Yann --droit=comptes:admin --droit=membres:admin
 * L'option --expediteur choisit l'adresse d'envoi (mere, bearn, euskadi, productions).
 */
#[AsCommand(name: 'compte:inviter', description: 'Transforme une personne en compte et envoie le lien d\'activation')]
class InviterCommand extends Command
{
    public function __construct(private Invitations $invitations, private Connection $base)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addOption('personne', null, InputOption::VALUE_REQUIRED, 'UUID de la personne dans l\'annuaire')
            ->addOption('nom', null, InputOption::VALUE_REQUIRED, 'Crée la personne si elle n\'existe pas')
            ->addOption('prenom', null, InputOption::VALUE_REQUIRED)
            ->addOption('droit', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'application:role[:perimetre]', [])
            ->addOption('expediteur', null, InputOption::VALUE_REQUIRED, 'Clé de l\'adresse d\'envoi (table compte.expediteur) ; par défaut celle marquée par défaut');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $personne = $input->getOption('personne');
        if (!$personne) {
            if (!$input->getOption('nom')) {
                $output->writeln('<error>Indiquer --personne=<uuid> ou --nom (et --prenom) pour la créer.</error>');

                return Command::INVALID;
            }
            $personne = $this->base->fetchOne('select annuaire.creer_personne(:nom, :prenom)',
                ['nom' => $input->getOption('nom'), 'prenom' => $input->getOption('prenom')]);
            $output->writeln("Personne créée dans l'annuaire : $personne");
        }
        [$compte, $lien] = $this->invitations->inviter(Uuid::fromString($personne),
            $input->getArgument('email'), $input->getOption('droit'), $input->getOption('expediteur'));
        $output->writeln(sprintf('Compte %s créé pour %s, droits : %s, invitation envoyée par : %s.', $compte->getId(),
            $compte->getEmail(), implode(', ', $compte->getDroits()) ?: 'aucun', $compte->getExpediteur()));
        $output->writeln("Lien d'activation (aussi envoyé par e-mail, valable 7 jours) :");
        $output->writeln($lien);

        return Command::SUCCESS;
    }
}
