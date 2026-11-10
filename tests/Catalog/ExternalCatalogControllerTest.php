<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogManager;
use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\Catalog;
use App\Entity\CatalogPage;
use App\Entity\CatalogPageElement;
use App\Entity\CatalogShare;
use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use App\Repository\CatalogShareRepository;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class ExternalCatalogControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CatalogManager $manager;
    private Catalog $primary;
    private Catalog $external;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->em->getConnection()->getParams()['driver']);
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [Catalog::class, CatalogPage::class, CatalogPageElement::class, CatalogShare::class, AdminUser::class, AdminSession::class]));
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->external = (new Catalog())->setExternal(true)->setTitle('Catalogue France')->setLocale('fr')->setEnabled(true);
        $this->primary = (new Catalog())->setTitle('Catalogue principal')->setEnabled(true);
        $user = new AdminUser('external-catalog-test@example.com');
        $user->setPassword('unused-test-password');
        foreach ([$this->external, $this->primary, $user] as $entity) $this->em->persist($entity);
        foreach ([$this->external, $this->primary] as $catalog) {
            $this->em->persist((new CatalogPage($catalog, 1))->setTitle('Couverture')->setImagePath('images/logo-ultrapop-red.webp'));
        }
        $this->em->flush();
        $this->directory = sys_get_temp_dir().'/lns-external-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->manager = new CatalogManager($this->em, $container->get(CatalogRepository::class), $container->get(CatalogPageRepository::class), $this->directory);
        $container->set(CatalogManager::class, $this->manager);
        $cookie = $container->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/uploads/catalogue', $this->directory.'/uploads', $this->directory] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory.'/*') as $file) if (is_file($file)) unlink($file);
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function testLibraryCreatesIndependentCataloguesWithoutChangingThePublicCatalogue(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/catalogues-externes');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-library-row]'));
        self::assertStringNotContainsString('Catalogue principal', $crawler->filter('.admin-library-table')->text());
        self::assertCount(0, $crawler->filter('[name="locale"]'));
        $this->client->submit($crawler->selectButton('Créer et ajouter mes documents')->form(['title' => 'Export collection']));
        self::assertResponseRedirects();
        $catalog = self::getContainer()->get(CatalogRepository::class)->findOneBy(['title' => 'Export collection']);
        self::assertTrue($catalog->isExternal());
        self::assertTrue($catalog->isEnabled());
        self::assertSame($this->primary->getId(), self::getContainer()->get(CatalogRepository::class)->findCurrent()->getId());
        $this->client->request('GET', '/catalogue');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Catalogue principal');
    }

    public function testPdfImportAndPageEditingUseTheExistingEngineInTheCorrectCatalogue(): void
    {
        $crawler = $this->editor();
        copy(dirname(__DIR__).'/Fixtures/catalog-2-pages.pdf', $this->directory.'/document.pdf');
        $token = $crawler->filter('form[data-catalog-operation="pdf"] input[name="_token"]')->attr('value');
        $this->client->xmlHttpRequest('POST', $this->base().'/importer-pdf', ['_token' => $token, 'pdfTitle' => 'Document FR'], ['pdf' => new UploadedFile($this->directory.'/document.pdf', 'document.pdf', 'application/pdf', test: true)]);
        self::assertResponseIsSuccessful();
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $result['order']);
        self::assertCount(2, $result['pages']);
        self::assertStringContainsString($this->base().'/page/', $result['pages'][0]['html']);
        $pages = self::getContainer()->get(CatalogPageRepository::class)->findForCatalog($this->external);
        self::assertSame(1, $pages[1]->getPdfPage());
        self::assertSame(2, $pages[2]->getPdfPage());
        self::assertSame($pages[1]->getPdfPath(), $pages[2]->getPdfPath());
        self::assertFileExists($this->directory.$pages[1]->getPdfPath());
        self::assertCount(1, self::getContainer()->get(CatalogPageRepository::class)->findForCatalog($this->primary));

        $crawler = $this->editor();
        $id = $pages[2]->getId();
        $form = $crawler->filter('#page-'.$id.' form[data-catalog-operation="update"]')->form(['title' => 'Page personnalisée', 'linkUrl' => '/fr/', 'enabled' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->base().'#page-'.$id);
        $crawler = $this->editor();
        $token = $crawler->filter('#page-'.$id.' form[data-catalog-operation="move"] input[name="_token"]')->first()->attr('value');
        $this->client->xmlHttpRequest('POST', $this->base().'/page/'.$id.'/deplacer/up', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame([$pages[0]->getId(), $id, $pages[1]->getId()], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['order']);
    }

    public function testSharingFormCreatesAnAnonymousLocalizedUnindexedReader(): void
    {
        $this->external->setLocale('de');
        $this->em->flush();
        $crawler = $this->editor();
        $this->client->submit($crawler->filter('.admin-external-share-create')->form(['label' => 'Client Berlin']));
        self::assertResponseRedirects($this->base().'#partage');
        $share = self::getContainer()->get(CatalogShareRepository::class)->findOneBy(['label' => 'Client Berlin']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $share->getToken());
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/catalogue/partage/'.$share->getToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex,nofollow"]');
        self::assertCount(0, $crawler->filter('link[rel="canonical"], .catalog-preview-banner'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testRevokingOneLinkPreservesOtherLinksAndUnpublishingPausesAll(): void
    {
        $first = $this->manager->createShare($this->external, 'Client A');
        $second = $this->manager->createShare($this->external, 'Client B');
        self::assertNotSame($first->getToken(), $second->getToken());
        $crawler = $this->editor();
        $this->client->submit($crawler->filter('form[action$="/liens/'.$first->getId().'/desactiver"]')->form());
        self::assertResponseRedirects($this->base().'#partage');
        $this->client->request('GET', '/catalogue/partage/'.$first->getToken());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/catalogue/partage/'.$second->getToken());
        self::assertResponseIsSuccessful();
        $form = $this->editor()->filter('form[data-catalog-operation="settings"]')->form();
        $form['enabled']->untick();
        $this->client->submit($form);
        $this->client->request('GET', '/catalogue/partage/'.$second->getToken());
        self::assertResponseStatusCodeSame(404);
        $form = $this->editor()->filter('form[data-catalog-operation="settings"]')->form();
        $form['enabled']->tick();
        $this->client->submit($form);
        $this->client->request('GET', '/catalogue/partage/'.$second->getToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/catalogue/partage/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignPagesAndLinksCannotBeMutatedThroughAnotherCatalogue(): void
    {
        $foreign = $this->manager->createExternal('Other catalogue');
        $foreign->setEnabled(true);
        $foreignPage = (new CatalogPage($foreign, 1))->setTitle('Other page')->setImagePath('images/logo-ultrapop-red.webp');
        $this->em->persist($foreignPage);
        $this->em->flush();
        $share = $this->manager->createShare($foreign, 'Other link');
        foreach (['', '/deplacer/up', '/supprimer'] as $suffix) {
            $this->client->request('POST', 'https://localhost'.$this->base().'/page/'.$foreignPage->getId().$suffix, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', $this->base().'/liens/'.$share->getId().'/desactiver', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/catalogues-externes/'.$this->primary->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/catalogues-externes/'.$this->primary->getId().'/supprimer', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($share->isRevoked());
    }

    public function testDeletionRemovesItsPagesAndSharingLinks(): void
    {
        $share = $this->manager->createShare($this->external, 'Link to remove');
        $crawler = $this->client->request('GET', 'https://localhost/admin/catalogues-externes');
        $this->client->submit($crawler->filter('form[action="'.$this->base().'/supprimer"]')->form());
        self::assertResponseRedirects('/admin/catalogues-externes');
        $this->em->flush();
        self::assertNull($this->em->getRepository(Catalog::class)->find($this->external->getId()));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_page WHERE catalog_id = ?', [$this->external->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_share WHERE catalog_id = ?', [$this->external->getId()]));
        $this->client->request('GET', '/catalogue/partage/'.$share->getToken());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/catalogue');
        self::assertResponseIsSuccessful();
    }

    public function testInvalidInputAndSharingEmptyCatalogueAreRejected(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/catalogues-externes');
        $token = $crawler->filter('form[action$="/nouveau"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/catalogues-externes/nouveau', ['_token' => $token, 'title' => '   ']);
        self::assertResponseRedirects('/admin/catalogues-externes');
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog'));
        $empty = $this->manager->createExternal('Empty draft');
        $crawler = $this->client->request('GET', '/admin/catalogues-externes/'.$empty->getId());
        $this->client->submit($crawler->filter('.admin-external-share-create')->form());
        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_share'));
        $crawler = $this->editor();
        file_put_contents($this->directory.'/invalid.pdf', 'not a PDF');
        $token = $crawler->filter('form[data-catalog-operation="pdf"] input[name="_token"]')->attr('value');
        $this->client->xmlHttpRequest('POST', $this->base().'/importer-pdf', ['_token' => $token, 'pdfPageCount' => '2'], ['pdf' => new UploadedFile($this->directory.'/invalid.pdf', 'invalid.pdf', 'application/pdf', test: true)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testManagementRemainsProtectedByAuthenticationAndCsrf(): void
    {
        foreach (['/admin/catalogues-externes/nouveau', $this->base().'/liens', $this->base().'/supprimer'] as $path) {
            $this->client->request('POST', 'https://localhost'.$path, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/catalogues-externes');
        self::assertResponseRedirects('/admin/login');
        $this->client->request('POST', $this->base().'/liens', ['_token' => 'invalid']);
        self::assertResponseRedirects('/admin/login');
    }

    private function base(): string { return '/admin/catalogues-externes/'.$this->external->getId(); }
    public function testLibraryToggleRenameAndShareModalEndpoints(): void
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/catalogues-externes');
        $shareButton = $crawler->filter('[data-action="catalog-shares#share"]')->first();
        $this->client->xmlHttpRequest('POST', $shareButton->attr('data-url'), ['_token' => $shareButton->attr('data-token')]);
        self::assertResponseIsSuccessful();
        $url = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['url'];
        self::assertStringContainsString('/catalogue/partage/', $url);
        $this->client->xmlHttpRequest('POST', $shareButton->attr('data-url'), ['_token' => $shareButton->attr('data-token')]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_share'));
        $toggle = $crawler->filter('[data-action="change->catalog-shares#toggle"]')->first();
        $this->client->xmlHttpRequest('POST', $toggle->attr('data-url'), ['_token' => $toggle->attr('data-token'), 'enabled' => '0']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $rename = $crawler->filter('[data-action="catalog-shares#rename"]')->first();
        $this->client->request('POST', 'https://localhost'.$rename->attr('data-url'), ['_token' => $rename->attr('data-token'), 'title' => 'Nouveau nom']);
        self::assertResponseRedirects('/admin/catalogues-externes');
        $this->em->clear();
        self::assertSame('Nouveau nom', $this->em->find(Catalog::class, $this->external->getId())->getTitle());
    }

    public function testInteractiveEditorPersistsLinksAndVideosAndRejectsInvalidElements(): void
    {
        $share = $this->manager->createShare($this->external, 'Interactive reader');
        $crawler = $this->client->request('GET', 'https://localhost'.$this->base().'/personnaliser');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-controller="catalog-editor"]')->attr('data-catalog-editor-token-value');
        $page = self::getContainer()->get(CatalogPageRepository::class)->findForCatalog($this->external)[0];
        $url = $this->base().'/page/'.$page->getId().'/elements';
        $link = ['kind' => 'link', 'url' => 'https://example.com/products', 'label' => 'Notre gamme', 'x' => 10, 'y' => 20, 'width' => 30, 'height' => 10];
        $video = ['kind' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'label' => 'Présentation', 'x' => 10, 'y' => 40, 'width' => 50, 'height' => 30];
        $this->client->xmlHttpRequest('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['elements' => [$link, $video]]));
        self::assertResponseIsSuccessful();
        $elements = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['elements'];
        self::assertCount(2, $elements);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $elements[1]['url']);
        $this->client->request('GET', '/catalogue/partage/'.$share->getToken());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.catalog-interactive-link[href="https://example.com/products"]');
        self::assertSelectorExists('.catalog-interactive-video[data-controller="catalog-video"][data-catalog-video-url-value="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"]');
        self::assertSelectorNotExists('.catalog-video-dialog');
        foreach ([['url' => 'javascript:alert(1)'], ['x' => 95], ['kind' => 'audio'], ['kind' => 'video', 'url' => 'https://evil.example/embed'], ['kind' => 'video', 'url' => 'http://example.com/video.mp4']] as $invalid) {
            $this->client->xmlHttpRequest('POST', 'https://localhost'.$url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['elements' => [array_replace($link, $invalid)]]));
            self::assertResponseStatusCodeSame(422);
            self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_page_element'));
        }
        $this->client->xmlHttpRequest('POST', 'https://localhost'.$url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid'], json_encode(['elements' => []]));
        self::assertResponseStatusCodeSame(403);
        $this->client->xmlHttpRequest('POST', 'https://localhost'.$url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['elements' => [$link]]));
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_page_element'));
    }

    private function editor(): \Symfony\Component\DomCrawler\Crawler { return $this->client->request('GET', 'https://localhost'.$this->base()); }
}
