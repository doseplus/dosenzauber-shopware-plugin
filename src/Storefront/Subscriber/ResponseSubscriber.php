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
     * ODER auf einer Cart-Seite mit DZ-Items — und merkt sich die nötigen Daten für
     * die Response-Injection.
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');

        // Cart-Routes: Header mit Lieferzeit-Hinweis + Clear-Button injizieren.
        // Theilich 2026-05-19: Twig-Override für page_checkout_cart_header greift
        // wegen Theme-Inheritance nicht — Subscriber-Inject ist robuster.
        if (in_array($routeName, ['frontend.checkout.cart.page', 'frontend.cart.offcanvas'], true)) {
            $parameters = $event->getParameters();
            $cart = $parameters['cart'] ?? ($parameters['page']->getCart() ?? null);
            if (is_object($cart) && method_exists($cart, 'getLineItems')) {
                $hasDpGravur = false;
                $hasAnyItem = false;
                foreach ($cart->getLineItems() as $li) {
                    $hasAnyItem = true;
                    $payload = $li->getPayload();
                    if (isset($payload['dpDosenzauberConfig']['options']['laser']) && $payload['dpDosenzauberConfig']['options']['laser']) {
                        $hasDpGravur = true;
                    }
                    if (isset($payload['dpVorabmuster'])) {
                        $hasDpGravur = true;
                    }
                }
                if ($hasAnyItem) {
                    $requestId = spl_object_id($request);
                    $this->shouldInject[$requestId] = true;
                    $this->renderContext[$requestId] = [
                        'type' => 'cart',
                        'hasDpGravur' => $hasDpGravur,
                    ];
                }
            }
            return;
        }

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

        // Login-Gate entfernt: auf Live gibt es kein B2B-Plugin, Konfigurator für alle sichtbar.

        $data = $this->dataProvider->getDataForProduct($product, $event->getSalesChannelContext());
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

        $ctx = $this->renderContext[$requestId];

        // === Cart-Page-Inject (Theilich 2026-05-19): Lieferzeit-Hinweis + Cart-Clear-Button ===
        if (($ctx['type'] ?? null) === 'cart') {
            if (str_contains($content, 'dp-cart-header-injected')) {
                return;
            }
            $hasDpGravur = $ctx['hasDpGravur'] ?? false;
            $deliveryBox = '';
            if ($hasDpGravur) {
                $deliveryBox = '<div class="dp-cart-delivery-hint" style="background:#FEF8E8;border:1px solid #C9A45C;border-radius:6px;padding:14px 18px;margin:0 0 18px 0;display:flex;align-items:center;gap:12px;font-family:Inter,system-ui,sans-serif;">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;color:#C9A45C;flex-shrink:0;"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>'
                    . '<div><strong style="display:block;font-size:14px;color:#1d201f;margin-bottom:2px;">Lieferzeit voraussichtlich 2–3 Wochen ab Freigabe</strong>'
                    . '<span style="font-size:12px;color:#6B6B6B;">Ihre Konfiguration enthält eine Veredelung. Nach Ihrer Freigabe der Referenz starten wir die Produktion.</span></div></div>';
            }
            $clearButton = '<div class="dp-cart-clear" style="text-align:right;margin:0 0 12px 0;">'
                . '<form method="get" action="' . htmlspecialchars($request->getBasePath() . '/dosenzauber/clear-cart', ENT_QUOTES, 'UTF-8') . '" onsubmit="return confirm(\'Wirklich den gesamten Warenkorb leeren? Alle Positionen werden entfernt.\');" style="display:inline;">'
                . '<button type="submit" style="background:none;border:1px solid #d4d4d4;color:#6B6B6B;font-size:11px;padding:6px 14px;border-radius:4px;cursor:pointer;font-family:Inter,system-ui,sans-serif;" onmouseover="this.style.borderColor=\'#c1272d\';this.style.color=\'#c1272d\';" onmouseout="this.style.borderColor=\'#d4d4d4\';this.style.color=\'#6B6B6B\';">'
                . 'Warenkorb leeren</button></form></div>';
            $headerHtml = '<div class="dp-cart-header-injected">' . $deliveryBox . $clearButton . '</div>';

            // Prepend vor dem ersten Cart-LineItem-Container
            // Versuche verschiedene Anker (verschiedene Themes/Versionen)
            $anchors = [
                '/<div\s+class="[^"]*\bcart-table-wrapper\b[^"]*"/i',
                '/<div\s+class="[^"]*\bcheckout-aside-cart\b[^"]*"/i',
                '/<div\s+class="[^"]*\bcart-product\b[^"]*"/i',
                '/<form\s+[^>]*name="cart"/i',
            ];
            $injected = false;
            foreach ($anchors as $pattern) {
                if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    $offset = $m[0][1];
                    $content = substr($content, 0, $offset) . $headerHtml . substr($content, $offset);
                    $injected = true;
                    break;
                }
            }
            if (!$injected) {
                // Fallback: vor </main>
                $content = preg_replace('/<\/main>/i', $headerHtml . '</main>', $content, 1);
            }

            $response->setContent($content);
            unset($this->shouldInject[$requestId], $this->renderContext[$requestId]);
            return;
        }

        // === Produktdetailseite: Konfigurator-Inject (bestehender Pfad) ===
        // Doppel-Injection vermeiden
        if (str_contains($content, 'dp-cfg-source')) {
            return;
        }

        try {
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
        // Vorgebaute Tailwind-Utilities + scoped Preflight (~12 KB statt 3 MB CDN).
        // Alpine + Lucide bleiben deferred via CDN.
        $headDeps = <<<HTML

<!-- Dosenzauber-Konfigurator: Assets (Tailwind statisch + Alpine; Lucide entfernt — alle Icons inline-SVG) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/bundles/doseplusdosenzauberconfigurator/configurator.css">
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js" defer></script>
HTML;

        // Configurator-HTML + Inline-Script:
        //   1. verschiebt Konfigurator ans Ende von .product-detail-buy
        //   2. verschiebt "Sie haben Fragen"-Box (.product-detail-contact) direkt darunter
        // Beide Moves laufen IM DOMContentLoaded-Event weil das Script vor der Contact-Box
        // im HTML platziert ist und beim sync-Parse die Box noch nicht existiert.
        $bodyContent = '<div class="dp-cfg-injected" style="margin:24px 0 8px 0;padding:0">' . $configuratorHtml . '</div>'
            . '<script>(function(){'
            . 'var doMove=function(){'
            . 'var cfg=document.querySelector(".dp-cfg-injected");'
            . 'var b=document.querySelector(".product-detail-buy");'
            . 'if(!b||!cfg)return;'
            . 'if(cfg.parentNode!==b){b.appendChild(cfg);}'
            . 'var contact=document.querySelector(".product-detail-contact");'
            . 'if(contact&&!b.contains(contact)){'
            . 'var contactWrap=contact.closest(".col-lg-5")||contact.parentNode;'
            . 'b.appendChild(contact);'
            . 'if(contactWrap&&contactWrap.parentNode&&contactWrap!==contact&&contactWrap.children.length===0){contactWrap.parentNode.removeChild(contactWrap);}'
            . '}'
            . '};'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",doMove);}else{doMove();}'
            . '})();</script>';

        // Dependencies vor </head> einfügen
        if (stripos($content, '</head>') !== false) {
            $content = preg_replace('/<\/head>/i', $headDeps . "\n</head>", $content, 1);
        }

        // Injection: vor .product-detail-contact. Das eingebettete Inline-Script
        //   im $bodyContent verschiebt den Konfigurator beim Page-Load ans Ende
        //   von .product-detail-buy (direkt unter VPE, über "Sie haben Fragen").
        //   Falls JS deaktiviert ist: bleibt an der Original-Position.
        $contactPattern = '/<div\s+class="[^"]*\bproduct-detail-contact\b[^"]*"/i';
        if (preg_match($contactPattern, $content)) {
            $content = preg_replace($contactPattern, $bodyContent . "\n" . '$0', $content, 1);
        } else {
            $content = preg_replace('/<\/body>/i', $bodyContent . "\n</body>", $content, 1);
        }

        $response->setContent($content);

        unset($this->shouldInject[$requestId], $this->renderContext[$requestId]);
    }
}
