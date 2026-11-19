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

    /** Liste des comptes (administration). */
    #[Route('/comptes', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $comptes = array_map(fn (Compte $c) => $this->decrire($this->comptes->chargerDroits($c)),
            $this->comptes->findBy([], ['email' => 'ASC']));

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
