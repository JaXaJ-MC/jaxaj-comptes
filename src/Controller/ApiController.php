<?php

namespace App\Controller;

use App\Entity\Compte;
use App\Repository\CompteRepository;
use App\Service\Expediteurs;
use App\Service\Invitations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(private CompteRepository $comptes, private Invitations $invitations) {}

    /** Le compte connecté. */
    #[Route('/moi', methods: ['GET'])]
    public function moi(#[CurrentUser] Compte $compte): JsonResponse
    {
        return $this->json($this->decrire($compte));
    }

    /**
     * Liste des comptes (administration). ?personne=<uuid> : le compte d'une personne de
     * l'annuaire, s'il existe (liste vide sinon) ; sert à afficher « transformer en compte ».
     */
    #[Route('/comptes', methods: ['GET'])]
    public function liste(Request $requete): JsonResponse
    {
        $personne = $requete->query->get('personne');
        if (null !== $personne && !Uuid::isValid($personne)) {
            return $this->json(['erreur' => 'personne : UUID attendu.'], 400);
        }
        $critere = $personne ? ['personneId' => Uuid::fromString($personne)] : [];
        $comptes = array_map(fn (Compte $c) => $this->decrire($this->comptes->chargerDroits($c)),
            $this->comptes->findBy($critere, ['email' => 'ASC']));

        return $this->json($comptes);
    }

    /** Adresses d'envoi possibles, pour le choix lors de la création d'un compte. */
    #[Route('/expediteurs', methods: ['GET'])]
    public function expediteurs(Expediteurs $expediteurs): JsonResponse
    {
        return $this->json($expediteurs->liste());
    }

    /**
     * « Transformer en compte » : { "personne": uuid, "email": …, "droits": ["membres:admin"],
     * "expediteur": "bearn" }. Crée le compte et envoie l'e-mail pour choisir le mot de passe,
     * depuis l'adresse de l'association choisie (celle par défaut si absente).
     */
    #[Route('/comptes', methods: ['POST'])]
    public function inviter(Request $requete): JsonResponse
    {
        $donnees = json_decode($requete->getContent(), true) ?: [];
        $personne = $donnees['personne'] ?? '';
        $email = trim($donnees['email'] ?? '');
        $droits = $donnees['droits'] ?? [];
        $expediteur = $donnees['expediteur'] ?? null;
        if (!Uuid::isValid($personne) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_array($droits)) {
            return $this->json(['erreur' => 'Attendu : personne (UUID), email, droits (liste).'], 400);
        }
        foreach ($droits as $droit) {
            if (!is_string($droit) || !preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*(:[0-9a-f-]{36})?$/', $droit)) {
                return $this->json(['erreur' => "Droit invalide : « $droit » (application:role[:perimetre])."], 400);
            }
        }
        try {
            [$compte] = $this->invitations->inviter(Uuid::fromString($personne), $email, $droits, $expediteur);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erreur' => $e->getMessage()], str_starts_with($e->getMessage(), 'Expéditeur') ? 400 : 404);
        } catch (\LogicException $e) {
            return $this->json(['erreur' => $e->getMessage()], 409);
        }

        return $this->json($this->decrire($compte), 201);
    }

    /**
     * Modifier un compte (administration) : { "droits": [...] } remplace les droits en vigueur,
     * { "actif": false } désactive le compte (plus de connexion), true le réactive ;
     * { "personne": uuid } le rattache à une autre fiche (fusion de doublons), qui ne doit pas en avoir.
     */
    #[Route('/comptes/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function modifier(string $id, Request $requete, \Doctrine\ORM\EntityManagerInterface $em): JsonResponse
    {
        $compte = $this->comptes->find(Uuid::fromString($id));
        if (!$compte) {
            return $this->json(['erreur' => 'Compte inconnu.'], 404);
        }
        $donnees = json_decode($requete->getContent(), true) ?: [];
        if (array_key_exists('droits', $donnees)) {
            $droits = $donnees['droits'];
            if (!is_array($droits)) {
                return $this->json(['erreur' => 'droits : liste attendue.'], 400);
            }
            foreach ($droits as $droit) {
                if (!is_string($droit) || !preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*(:[0-9a-f-]{36})?$/', $droit)) {
                    return $this->json(['erreur' => "Droit invalide : « $droit » (application:role[:perimetre])."], 400);
                }
            }
            $this->comptes->definirDroits($compte, array_values(array_unique($droits)));
        }
        if (array_key_exists('personne', $donnees)) {
            $personne = $donnees['personne'];
            if (!is_string($personne) || !Uuid::isValid($personne) || !$this->comptes->personneExiste(Uuid::fromString($personne))) {
                return $this->json(['erreur' => 'personne : fiche inconnue.'], 404);
            }
            $autre = $this->comptes->findOneBy(['personneId' => Uuid::fromString($personne)]);
            if ($autre && $autre !== $compte) {
                return $this->json(['erreur' => 'Cette fiche a déjà un compte de connexion.'], 409);
            }
            $compte->setPersonneId(Uuid::fromString($personne));
        }
        if (array_key_exists('actif', $donnees)) {
            $compte->setActif((bool) $donnees['actif']);
        }
        $em->flush();

        return $this->json($this->decrire($this->comptes->chargerDroits($compte)));
    }

    private function decrire(Compte $compte): array
    {
        return [
            'id' => (string) $compte->getId(),
            'personne' => (string) $compte->getPersonneId(),
            'email' => $compte->getEmail(),
            'actif' => $compte->estActif(),
            'active' => $compte->estActive(),
            'invite_le' => $compte->getInviteLe()?->format(DATE_ATOM),
            'derniere_connexion' => $compte->getDerniereConnexion()?->format(DATE_ATOM),
            'expediteur' => $compte->getExpediteur(),
            'droits' => $compte->getDroits(),
        ];
    }
}
