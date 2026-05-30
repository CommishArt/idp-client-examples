<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class OnboardingController extends AbstractController
{
    #[Route('/onboarding', name: 'app_onboarding')]
    #[IsGranted('ROLE_USER')]
    public function onboarding(#[CurrentUser] User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($user->isOnboarded()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $user->setOnboarded(true);
            $em->flush();
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('onboarding.html.twig', ['user' => $user]);
    }
}
