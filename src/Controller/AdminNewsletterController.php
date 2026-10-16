<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NewsletterSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminNewsletterController extends AbstractController
{
    #[Route('/admin/newsletter', name: 'app_admin_newsletter', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $repository = $entityManager->getRepository(NewsletterSubscriber::class);
        $count = $repository->count([]);
        $pageCount = max(1, (int) ceil($count / 25));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $recent = $entityManager->createQueryBuilder()->select('COUNT(subscriber.id)')->from(NewsletterSubscriber::class, 'subscriber')
            ->where('subscriber.subscribedAt >= :since');

        return $this->render('admin/newsletter.html.twig', [
            'subscribers' => $repository->findBy([], ['subscribedAt' => 'DESC', 'id' => 'DESC'], 25, ($page - 1) * 25),
            'subscriberCount' => $count,
            'todayCount' => (int) (clone $recent)->setParameter('since', $today->setTimezone(new \DateTimeZone('UTC')))->getQuery()->getSingleScalarResult(),
            'weekCount' => (int) $recent->setParameter('since', $today->modify('-6 days')->setTimezone(new \DateTimeZone('UTC')))->getQuery()->getSingleScalarResult(),
            'page' => $page, 'pageCount' => $pageCount,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
