<?php

declare(strict_types=1);

namespace App\Visitor;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(priority: -64)]
final readonly class VisitorResponseSubscriber
{
    public function __construct(
        private VisitorRecorder $recorder,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:VISITOR_TRACKING_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $route = preg_replace('/\.(en|fr)$/', '', (string) $request->attributes->get('_route'));
        if (!$this->enabled || !$event->isMainRequest() || !$request->isMethod('GET') || 200 !== $response->getStatusCode()
            || !str_contains($response->headers->get('Content-Type', ''), 'text/html')
            || !in_array($route, ['app_home', 'app_wholesale', 'app_trading', 'app_ultrapop', 'app_blog', 'app_article_wholesaler', 'app_article_merchandising', 'app_article_trends', 'app_terms', 'app_privacy', 'app_catalog'], true)
            || $request->headers->has('Turbo-Frame') || $request->headers->has('X-LNS-Prefetch')
            || str_contains($request->headers->get('Sec-Purpose', '').$request->headers->get('X-Sec-Purpose', '').$request->headers->get('Purpose', ''), 'prefetch')
            || preg_match('/bot|spider|crawler|slurp|headless|lighthouse|curl|wget/i', $request->headers->get('User-Agent', ''))
        ) {
            return;
        }

        try {
            $this->recorder->record($request);
        } catch (\Throwable $exception) {
            $this->logger->warning('Visitor tracking failed.', ['exception' => $exception]);
        }
    }
}
