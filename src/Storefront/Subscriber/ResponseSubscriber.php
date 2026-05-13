<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Storefront\Subscriber;

use Doseplus\DosenzauberConfigurator\Service\ConfiguratorDataProvider;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment as TwigEnvironment;

/**
 * Robuste HTML-Injection per Response-Filter.
 *
 * Wird IMMER ausgeführt sobald irgendein Plugin im Theme-Chain Twig blocks
 * unseren base.html.twig-Override schluckt. Wir hängen einen <template>-Tag
 * + Loader-<script> direkt vor </body> in den finalen HTML-Response.
 *
 * Trigger: nur auf Produkt-Detailseiten (frontend.detail.page) und nur wenn
 * der Subscriber-Datenflag (dpDosenzauberActive) für das Produkt gesetzt ist.
 */
class ResponseSubscriber implements EventSubscriberInterface
{
    /** @var array<string,bool> Track per request whether configurator should be injected */
    private array $shouldInject = [];

    /** @var array<string,array> Track render context for injection */
    private array $renderContext = [];

    public function __construct(
        private readonly ConfiguratorDataProvider $dataProvider,
        private readonly SalesChannelRepository $productRepository,
        private readonly TwigEnvironment $twig,
        private readonly RequestStack $requestStack,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => ['onStorefrontRender', 1000],
            KernelEvents::RESPONSE       => ['onResponse', -100],
        ];
    }

    /**
     * Pre-render: prüft ob wir auf einer Produktseite mit Dosenzauber-Produkt sind
     * und merkt sich die nötigen Daten für die Response-Injection.
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');

        if ($routeName !== 'frontend.detail.page') {
            return;
        }

        $parameters = $event->getParameters();
        $page = $parameters['page'] ?? null;

        if (!is_object($page) || !method_exists($page, 'getProduct')) {
            return;
        }

        $product = $page->getProduct();
        if (!$product instanceof SalesChannelProductEntity) {
            return;
        }

        if (!$this->dataProvider->isDosenzauberProduct($product)) {
            return;
        }

        $data = $this->dataProvider->getDataForProduct($product);
        if ($data === null) {
            return;
        }

        $requestId = spl_object_id($request);
        $this->shouldInject[$requestId] = true;
        $this->renderContext[$requestId] = [
            'cfg'     => $data,
            'product' => $product,
        ];
    }

    /**
     * Response: rendert das Konfigurator-Template und injiziert es vor </body>.
     */
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = spl_object_id($request);
        if (!($this->shouldInject[$requestId] ?? false)) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if (!is_string($content) || stripos($content, '</body>') === false) {
            return;
        }

        // Doppel-Injection vermeiden
        if (str_contains($content, 'dp-cfg-source')) {
            return;
        }

        try {
            $ctx = $this->renderContext[$requestId];
            $configuratorHtml = $this->twig->render(
                '@DoseplusDosenzauberConfigurator/storefront/component/dosenzauber-configurator.html.twig',
                [
                    'cfg'     => $ctx['cfg'],
                    'product' => $ctx['product'],
                ]
            );
        } catch (\Throwable $e) {
            // Niemals die Response zerschießen wenn unser Template ein Fehler hat
            return;
        }

        // CDN-Dependencies vor </head>, Konfigurator-HTML direkt vor .product-detail-contact.
        // Keine Template-Tags, kein JS-Injection — Alpine bindet selbst on load.
        $headDeps = <<<HTML

<!-- Dosenzauber-Konfigurator: CDN-Dependencies -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js" defer></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
HTML;

        $bodyContent = '<div class="dp-cfg-injected" style="margin:0;padding:0">' . $configuratorHtml . '</div>';

        // Dependencies vor </head> einfügen
        if (stripos($content, '</head>') !== false) {
            $content = preg_replace('/<\/head>/i', $headDeps . "\n</head>", $content, 1);
        }

        // Injection direkt NACH der VPE-Row (innerhalb von .product-detail-buy):
        //   col-lg-5 Top-Row enthält dann: Art.-Nr. / Maße / VPE / Konfigurator
        //   col-lg-7 Top-Row enthält: Image + Thumbnails
        // Fallback: vor .product-detail-contact (bottom row), dann </body>.
        $injected = false;

        $vpePattern = '/(<div\s+class="[^"]*\bproduct-detail-additional-information\b[^"]*"[^>]*>\s*<div[^>]*>\s*<strong>VPE:<\/strong>\s*<\/div>\s*<div[^>]*>[\s\S]*?<\/div>\s*<\/div>)/i';
        if (preg_match($vpePattern, $content)) {
            $content = preg_replace($vpePattern, '$1' . "\n" . $bodyContent, $content, 1);
            $injected = true;
        }

        if (!$injected) {
            $contactPattern = '/<div\s+class="[^"]*\bproduct-detail-contact\b[^"]*"/i';
            if (preg_match($contactPattern, $content)) {
                $content = preg_replace($contactPattern, $bodyContent . "\n" . '$0', $content, 1);
                $injected = true;
            }
        }

        if (!$injected) {
            $content = preg_replace('/<\/body>/i', $bodyContent . "\n</body>", $content, 1);
        }

        $response->setContent($content);

        unset($this->shouldInject[$requestId], $this->renderContext[$requestId]);
    }
}
