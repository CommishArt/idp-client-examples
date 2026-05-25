<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MainController extends AbstractController
{
    public function __construct(
        private readonly string $idpBaseUrl,
    ) {}

    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->redirectToRoute('commish_idp_login');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(#[CurrentUser] User $user): Response
    {
        return $this->render('dashboard.html.twig', [
            'user'        => $user,
            'idp_base_url' => $this->idpBaseUrl,
        ]);
    }

}
