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
<!-- Dosenzauber-Konfigurator: Dependencies + Template + Injection-Loader -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js" defer></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

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

    function alreadyInjected() {
        return !!document.querySelector('.dp-cfg-injected');
    }

    // <script>-Tags die via innerHTML kopiert wurden, EXECUTEN nicht.
    // Diese Funktion klont sie als neue Elemente, dann führt der Browser sie aus.
    function reExecuteScripts(root) {
        var scripts = root.querySelectorAll('script');
        scripts.forEach(function (oldScript) {
            var newScript = document.createElement('script');
            for (var i = 0; i < oldScript.attributes.length; i++) {
                var a = oldScript.attributes[i];
                newScript.setAttribute(a.name, a.value);
            }
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function performInject(area) {
        var src = document.getElementById('dp-cfg-source');
        if (!src) return false;

        var wrapper = document.createElement('div');
        wrapper.className = 'dp-cfg-injected';
        wrapper.innerHTML = src.innerHTML;
        area.insertBefore(wrapper, area.firstChild);

        // Inline-Scripts im Konfigurator-Template ausführen (window.dpDosenzauberCfg etc.)
        reExecuteScripts(wrapper);

        // Alpine.js anwenden — sobald geladen
        function applyAlpine() {
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                try { window.Alpine.initTree(wrapper); } catch (e) { console.error('[Dosenzauber] Alpine.initTree fail:', e); }
                return true;
            }
            return false;
        }

        if (!applyAlpine()) {
            // Alpine noch nicht geladen — auf 'alpine:init'-Event warten oder pollen
            document.addEventListener('alpine:initialized', function onInit() {
                document.removeEventListener('alpine:initialized', onInit);
                applyAlpine();
            });
            var attempts = 0;
            var poller = setInterval(function () {
                attempts++;
                if (applyAlpine() || attempts > 50) clearInterval(poller);
            }, 100);
        }

        // Lucide-Icons rendern
        function applyLucide() {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                try { window.lucide.createIcons(); } catch (e) {}
                return true;
            }
            return false;
        }
        if (!applyLucide()) {
            var lucideAttempts = 0;
            var lucidePoller = setInterval(function () {
                lucideAttempts++;
                if (applyLucide() || lucideAttempts > 50) clearInterval(lucidePoller);
            }, 100);
        }

        return true;
    }

    function tryInjectWithRetries() {
        if (alreadyInjected()) return;
        var area = buyArea();
        if (area) { performInject(area); return; }
        // Wenn buyArea noch nicht im DOM: wiederholt versuchen
        [50, 150, 500, 1500, 3000].forEach(function (delay) {
            setTimeout(function () {
                if (alreadyInjected()) return;
                var a = buyArea();
                if (a) performInject(a);
            }, delay);
        });
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
