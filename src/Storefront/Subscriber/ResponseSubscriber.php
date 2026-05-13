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

        $injection = <<<HTML
<template id="dp-cfg-source">{$configuratorHtml}</template>
<script>
(function () {
    'use strict';
    function buyArea() {
        return document.querySelector('.product-detail-buy')
            || document.querySelector('.product-detail-content-main')
            || document.querySelector('.product-detail-main')
            || document.querySelector('.product-detail');
    }
    function alreadyInjected() { return !!document.querySelector('.dp-cfg, .dp-cfg-injected'); }
    function inject() {
        if (alreadyInjected()) return true;
        var src = document.getElementById('dp-cfg-source');
        if (!src) return false;
        var area = buyArea();
        if (!area) return false;
        var wrapper = document.createElement('div');
        wrapper.className = 'dp-cfg-injected';
        wrapper.innerHTML = src.innerHTML;
        area.insertBefore(wrapper, area.firstChild);
        if (window.Alpine && typeof window.Alpine.initTree === 'function') {
            try { window.Alpine.initTree(wrapper); } catch (e) {}
        }
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try { window.lucide.createIcons(); } catch (e) {}
        }
        return true;
    }
    function tryInjectWithRetries() {
        if (inject()) return;
        [50, 150, 500, 1500, 3000].forEach(function (d) { setTimeout(inject, d); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryInjectWithRetries);
    } else {
        tryInjectWithRetries();
    }
})();
</script>
HTML;

        $content = preg_replace(
            '/<\/body>/i',
            $injection . '</body>',
            $content,
            1
        );

        $response->setContent($content);

        unset($this->shouldInject[$requestId], $this->renderContext[$requestId]);
    }
}
