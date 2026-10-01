<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogManager;
use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\Catalog;
use App\Entity\CatalogPage;
use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class AdminCatalogControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $uploadDirectory;
    /** @var list<int> */
    private array $pageIds;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->entityManager->getConnection()->getParams()['driver']);
        $metadata = array_map($this->entityManager->getClassMetadata(...), [Catalog::class, CatalogPage::class, AdminUser::class, AdminSession::class]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $catalog = (new Catalog())->setEnabled(true);
        $user = new AdminUser('catalog-test@example.com');
        $user->setPassword('unused-test-password');
        $this->entityManager->persist($catalog);
        $this->entityManager->persist($user);
        $pages = [];
        for ($position = 1; $position <= 3; ++$position) {
            $page = (new CatalogPage($catalog, $position))->setTitle('Page '.$position)->setImagePath('images/logo-ultrapop-red.png');
            $this->entityManager->persist($page);
            $pages[] = $page;
        }
        $this->entityManager->flush();
        $this->pageIds = array_map(static fn (CatalogPage $page): int => $page->getId(), $pages);
        $this->uploadDirectory = sys_get_temp_dir().'/lns-catalog-test-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDirectory);
        $container->set(CatalogManager::class, new CatalogManager(
            $this->entityManager,
            $container->get(CatalogRepository::class),
            $container->get(CatalogPageRepository::class),
            $this->uploadDirectory,
        ));
        $cookie = $container->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
        $this->client->request('GET', 'https://localhost/admin/catalogue');
        self::assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        foreach ([$this->uploadDirectory.'/uploads/catalogue', $this->uploadDirectory.'/uploads', $this->uploadDirectory] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory.'/*') as $file) {
                if (is_file($file)) unlink($file);
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function testAjaxMoveAndDeleteReturnTheSavedOrder(): void
    {
        $id = $this->pageIds[1];
        $token = $this->client->getCrawler()->filter('#page-'.$id.' form[data-catalog-operation="move"] input')->first()->attr('value');
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id.'/deplacer/up', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$id, $this->pageIds[0], $this->pageIds[2]], $result['order']);
        self::assertSame([], $result['pages']);

        $this->client->request('GET', 'https://localhost/admin/catalogue');
        $token = $this->client->getCrawler()->filter('#page-'.$id.' form[data-catalog-operation="delete"] input')->attr('value');
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id.'/supprimer', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$this->pageIds[0], $this->pageIds[2]], $result['order']);
        self::assertNull($this->entityManager->find(CatalogPage::class, $id));
    }

    public function testAjaxUpdateReturnsOnlyTheUpdatedCardAndRejectsInvalidLinks(): void
    {
        $id = $this->pageIds[0];
        $token = $this->client->getCrawler()->filter('#page-'.$id.' form[data-catalog-operation="update"] input[name="_token"]')->attr('value');
        $parameters = ['_token' => $token, 'title' => 'Couverture', 'enabled' => '1', 'linkUrl' => '/fr/'];
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id, $parameters);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $result['pages']);
        self::assertSame($id, $result['pages'][0]['id']);
        self::assertStringContainsString('value="Couverture"', $result['pages'][0]['html']);
        self::assertSame($this->pageIds, $result['order']);

        $parameters['linkUrl'] = 'javascript:alert(1)';
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id, $parameters);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse(json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['success']);
        $this->entityManager->clear();
        self::assertSame('/fr/', $this->entityManager->find(CatalogPage::class, $id)->getLinkUrl());
    }

    public function testAjaxUploadReturnsNewCardsAndStoresTheOptimizedImage(): void
    {
        $token = $this->client->getCrawler()->filter('form[data-catalog-operation="images"] input[name="_token"]')->attr('value');
        $path = $this->uploadDirectory.'/cover.png';
        $image = imagecreatetruecolor(12, 18);
        imagepng($image, $path);
        imagedestroy($image);
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/ajouter-images', ['_token' => $token], [
            'images' => [new UploadedFile($path, 'cover.png', 'image/png', test: true)],
        ]);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(4, $result['order']);
        self::assertCount(1, $result['pages']);
        $page = $this->entityManager->find(CatalogPage::class, $result['pages'][0]['id']);
        self::assertFileExists($this->uploadDirectory.$page->getImagePath());
        self::assertStringContainsString($page->getImagePath(), $result['pages'][0]['html']);
    }

    public function testMutationsKeepCsrfAndAuthenticationChecks(): void
    {
        $id = $this->pageIds[0];
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id.'/supprimer', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entityManager->find(CatalogPage::class, $id));
        $this->client->getCookieJar()->clear();
        $this->client->xmlHttpRequest('POST', 'https://localhost/admin/catalogue/page/'.$id.'/supprimer', ['_token' => 'invalid']);
        self::assertResponseRedirects('/admin/login');
        self::assertNotNull($this->entityManager->find(CatalogPage::class, $id));
    }

    public function testRegularFormsStillRedirectAfterSaving(): void
    {
        $token = $this->client->getCrawler()->filter('form[data-catalog-operation="settings"] input[name="_token"]')->attr('value');
        $this->client->request('POST', 'https://localhost/admin/catalogue/parametres', ['_token' => $token, 'title' => 'Catalogue renommé', 'enabled' => '1']);
        self::assertResponseRedirects('/admin/catalogue');
        self::assertSame('Catalogue renommé', static::getContainer()->get(CatalogRepository::class)->findCurrent()->getTitle());
    }
}
