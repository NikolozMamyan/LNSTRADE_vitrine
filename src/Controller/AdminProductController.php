<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use App\Product\ProductManager;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produits', name: 'admin_product')]
final class AdminProductController extends AbstractController
{
    public function __construct(private readonly ProductRepository $products, private readonly ProductManager $manager)
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 120);

        return $this->render('admin/products/index.html.twig', [
            'listing' => $this->products->search($search, $request->query->getInt('page', 1)), 'search' => $search,
        ]);
    }

    #[Route('/nouveau', name: '_new', methods: ['GET', 'POST'])]
    #[Route('/{id}/modifier', name: '_edit', methods: ['GET', 'POST'], requirements: ['id' => '\\d+'])]
    public function edit(Request $request, ?int $id = null): Response
    {
        $product = null === $id ? new Product() : ($this->products->find($id) ?? throw $this->createNotFoundException('Produit introuvable.'));
        $this->manager->prepareTranslations($product);
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $pricing = [];
            foreach (['unitPriceExclTax', 'unitPriceInclTax', 'taxRate', 'priceBasis'] as $field) $pricing[$field] = (string) $form->get($field)->getData();
            $removed = [];
            foreach ($form->get('images') as $image) {
                if ($image->get('remove')->getData()) $removed[] = $image->getData();
            }
            try {
                $this->manager->save($product, $pricing, $form->get('newImages')->getData() ?? [], $form->get('newImageKind')->getData(), $removed);
                $this->addFlash('success', 'Le produit a été enregistré. La boutique utilise maintenant ces informations.');

                return $this->redirectToRoute('admin_product_edit', ['id' => $product->getId()]);
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/products/edit.html.twig', ['product' => $product, 'form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
