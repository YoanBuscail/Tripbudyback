<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Annotation\Security;
use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserController extends AbstractController
{
    private $entityManager;
    private $validator;

    public function __construct(EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
    }

    /**
     * Read - Read
     * @Route("/users", name="app_user")
     */
    public function index(): JsonResponse
    {
        return new JsonResponse(['message' => 'Bienvenue'], 200);
    }

    /**
     * Get a specific user by ID
     * @Route("/api/users/{user_id}", name="get_user", methods={"GET"})
     */
    public function getUserById($user_id)
    {
        // mettre un try catch au cas ou le json n'est pas bon
        // Récupérer l'utilisateur à partir de la base de données en utilisant $user_id
        try {
            $user = $this->entityManager->getRepository(User::class)->find($user_id);
        } catch (EntityNotFoundException $e) {
            // L'utilisateur n'a pas été trouvé, renvoyer une réponse 404.
            return new JsonResponse(['message' => 'Utilisateur non trouvé'], 404);
        } catch (\Exception $e) {
            // Gérer des erreurs de base de données.
            return new JsonResponse(['message' => 'Une erreur s\'est produite lors de la récupération de l\'utilisateur.'], 500);
        }
        // Convertir l'objet utilisateur en tableau ou en JSON pour la réponse.
        $userData = [
            'id' => $user->getId(),
            'firstname' => $user->getFirstname(),
            'lastname' => $user->getLastname(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            ];

        return new JsonResponse($userData, 200);
    }

    /**
     * Get the authenticated user's profile
     * @Route("/api/profile", name="get_own_profile", methods={"GET"})
     * @Security("is_granted('ROLE_USER')")
     */
    public function getOwnProfile()
    {
        $user = $this->getUser();

        // Convertir l'objet utilisateur en données de profil
        $profileData = [
            'id' => $user->getId(),
            'firstname' => $user->getFirstname(),
            'lastname' => $user->getLastname(),
            'email' => $user->getEmail(),
            
        ];

        return new JsonResponse($profileData, 200);
    }

    /**
     * Update the authenticated user's profile
     * @Route("/api/profile", name="update_own_profile", methods={"PUT"})
     * @Security("is_granted('ROLE_USER')")
     */
    public function updateOwnProfile(Request $request, UserPasswordHasherInterface $passwordHasher, ValidatorInterface $validator)
    {
        $user = $this->getUser();

        // Récupérer les données JSON de la requête
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['message' => 'Les données JSON de la requête sont invalides'], 400);
        }

        if (isset($data['firstname'])) {
            $user->setFirstname($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $user->setLastname($data['lastname']);
        }
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }

        $errors = $validator->validate($user);

        if (count($errors) > 0) {
            return new JsonResponse(['message' => 'Validation error', 'errors' => $errors], 400);
        }

        // Persist
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Profile updated successfully'], 200);
    }

    /**
     * Delete the authenticated user's account
     * @Route("/api/profile", name="delete_own_account", methods={"DELETE"})
     * @Security("is_granted('ROLE_USER')")
     */
    public function deleteOwnAccount(Request $request, UserPasswordHasherInterface $passwordHasher)
    {
        $user = $this->getUser();

        // implémenter une méthode pour obtenir le mot de passe de l'utilisateur,
        // par exemple, $user->getPassword().
        $providedPassword = $request->request->get('password');

        if (!$passwordHasher->isPasswordValid($user, $providedPassword)) {
            return new JsonResponse(['message' => 'Mot de passe incorrect'], 400);
        }

        // If the password matches, delete the user's account
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Compte supprimé avec succès'], 200);
    }
    /**
     * Create a new user
     * @Route("/api/users", name="create_user", methods={"POST"})
     */
    public function createUser(Request $request, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        // Récupérer les données JSON de la requête
        $data = json_decode($request->getContent(), true);

        // Vérifier si des données ont été fournies
        if (!$data) {
            return new JsonResponse(['message' => 'Les données JSON de la requête sont invalides'], 400);
        }

        // Créer une nouvelle instance de l'entité User
        $user = new User();

        // Créer le formulaire en utilisant UserType
        $form = $this->createForm(UserType::class, $user);

        // Gérer la soumission du formulaire
        $form->handleRequest($request);

        // Remplir les attributs de l'utilisateur avec les données de la requête
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }
        if (isset($data['firstname'])) {
            $user->setFirstname($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $user->setLastname($data['lastname']);
        }
        if (isset($data['password'])) {
            // Gérer le hachage du mot de passe ici
            $hashedPassword = $passwordHasher->hashPassword($user, $data['password']);
            // reste plus qu'à setter le nouveau mot de passe 
            $user->setPassword($hashedPassword);
        }
        if (isset($data['roles'])) {
            // Gérer les rôles ici
            $user->setRoles($data['roles']);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Persister le nouvel utilisateur dans la base de données
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Retourner une réponse JSON avec l'utilisateur créé et un code de statut 201 (Created)
            $userData = [
                'id' => $user->getId(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ];

            return new JsonResponse($userData, 201);
        } else {
            // Si le formulaire n'est pas valide, retourner une réponse JSON avec les erreurs de validation
            $formErrors = $this->getFormErrors($form);
            return new JsonResponse(['message' => 'Erreurs de validation', 'errors' => $formErrors], 400);
        }
    }

    // Fonction pour récupérer les erreurs de validation du formulaire
    private function getFormErrors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        foreach ($form->all() as $childForm) {
            if ($childForm instanceof FormInterface) {
                $childErrors = $this->getFormErrors($childForm);
                if (!empty($childErrors)) {
                    $errors[$childForm->getName()] = $childErrors;
                }
            }
        }

        return $errors;
    }
    /**
     * Update User
     * @Route("/api/users/{user_id}", name="update_user", methods={"PUT"})
     */
    public function updateUser(Request $request, $user_id, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        // Récupérer l'utilisateur à partir de la base de données en utilisant $user_id.
        $user = $this->entityManager->getRepository(User::class)->find($user_id);

        if (!$user) {
            return new JsonResponse(['message' => 'Utilisateur non trouvé'], 404);
        }

        // Récupérer les données JSON de la requête
        $data = json_decode($request->getContent(), true);

        // Mettre à jour les attributs de l'utilisateur en fonction des données de la requête.
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }
        if (isset($data['firstname'])) {
            $user->setFirstname($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $user->setLastname($data['lastname']);
        }
        if (isset($data['password'])) {
            // Hacher le mot de passe
            $hashedPassword = $passwordHasher->hashPassword($user, $data['password']); 
            $user->setPassword($hashedPassword);
        }
        if (isset($data['roles'])) {
            // Gérer la mise à jour des rôles ici 
            $user->setRoles($data['roles']);
        }

        // Persister les changements dans la base de données.
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Utilisateur mis à jour avec succès']);
    }

    /**
     * Delete User
     * @Route("/api/users/{user_id}", name="delete_user", methods={"DELETE"})
     */
    public function deleteUser(Request $request, $user_id): JsonResponse
    {
        $user = $this->entityManager->getRepository(User::class)->find($user_id);

        if (!$user) {
            // L'utilisateur n'a pas été trouvé, renvoyez une réponse 404.
            return new JsonResponse(['message' => 'Utilisateur non trouvé'], 404);
        }
         // Vérifier si l'utilisateur actuellement authentifié a le droit de supprimer cet utilisateur.
         $currentUser = $this->getUser(); // l'utilisateur actuellement authentifié.

        if ($currentUser !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Vous n\'avez pas les autorisations nécessaires pour supprimer cet utilisateur.'], 403);
        }

        // Supprimer l'utilisateur de la base de données.
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Utilisateur supprimé avec succès']);
    }
}
