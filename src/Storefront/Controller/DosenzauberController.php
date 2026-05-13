<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Storefront\Controller;

use Doseplus\DosenzauberConfigurator\Service\ConfiguratorDataProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Storefront-Endpoint: Dosenzauber-Konfiguration in den Warenkorb legen.
 *
 * Erwartet JSON-Payload aus dem Alpine.js-Konfigurator, validiert gegen die
 * Whitelist via ConfiguratorDataProvider und legt einen LineItem an, der die
 * komplette Konfiguration im payload-Feld trägt (Lasergravur, Befüllung,
 * Verpackung, Karte, Rabattcode).
 */
class DosenzauberController extends StorefrontController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly SalesChannelRepository $productRepository,
        private readonly ConfiguratorDataProvider $dataProvider,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route(
        path: '/dosenzauber/add-to-cart',
        name: 'frontend.dosenzauber.add-to-cart',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']]
    )]
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodePayload($request);
        if ($data === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }

        $productNumber = trim((string)($data['productNumber'] ?? ''));
        $quantity      = max(1, (int)($data['quantity'] ?? 0));

        if ($productNumber === '') {
            return new JsonResponse(['ok' => false, 'error' => 'productNumber missing'], 400);
        }

        $product = $this->loadProductByNumber($productNumber, $context);
        if ($product === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Produkt nicht gefunden'], 404);
        }

        if (!$this->dataProvider->isDosenzauberProduct($product)) {
            return new JsonResponse(['ok' => false, 'error' => 'Produkt ist kein Dosenzauber-Produkt'], 400);
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $lineItem = $this->lineItemFactory->create([
            'type'        => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => $product->getId(),
            'quantity'    => $quantity,
            'payload'     => [
                'dpDosenzauberConfig' => $this->sanitizeConfig($data),
            ],
        ], $context);

        $lineItem->setRemovable(true);
        $lineItem->setStackable(false);

        $cart = $this->cartService->add($cart, $lineItem, $context);

        // Promotion-Code an Shopware durchreichen (Weg A: native Promotion-Engine)
        $promoCode = trim((string)($data['promo']['code'] ?? ''));
        $promoApplied = false;
        $promoError = null;
        if ($promoCode !== '') {
            try {
                $promotionItem = (new PromotionItemBuilder())->buildPlaceholderItem($promoCode);
                $cart = $this->cartService->add($cart, $promotionItem, $context);

                // Prüfen ob Shopware den Code akzeptiert hat (gültige Promotion = LineItem bleibt drin, ungültige = wird automatisch entfernt + Error im Cart)
                $promoApplied = $cart->getLineItems()->filter(
                    fn(LineItem $li) => $li->getType() === 'promotion' && $li->getReferencedId() === $promoCode
                )->count() > 0;

                if (!$promoApplied) {
                    $promoError = 'Rabattcode ungültig oder Bedingungen nicht erfüllt';
                }
            } catch (\Throwable $e) {
                $this->logger->info('Dosenzauber: Promo-Code konnte nicht angewendet werden', [
                    'code'  => $promoCode,
                    'error' => $e->getMessage(),
                ]);
                $promoError = 'Rabattcode konnte nicht angewendet werden';
            }
        }

        return new JsonResponse([
            'ok'           => true,
            'redirectUrl'  => $this->generateUrl('frontend.checkout.cart.page'),
            'cartToken'    => $context->getToken(),
            'itemCount'    => $cart->getLineItems()->count(),
            'lineItemId'   => $lineItem->getId(),
            'promoApplied' => $promoApplied,
            'promoError'   => $promoError,
        ]);
    }

    /**
     * Logo-Upload: speichert die hochgeladene Datei und verknüpft sie via Payload-Update
     * mit dem zuvor angelegten LineItem. Akzeptiert PNG/JPG/SVG/PDF bis 5 MB.
     */
    #[Route(
        path: '/dosenzauber/upload-logo',
        name: 'frontend.dosenzauber.upload-logo',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']]
    )]
    public function uploadLogo(Request $request, SalesChannelContext $context): JsonResponse
    {
        $lineItemId = (string) $request->request->get('lineItemId', '');
        $file = $request->files->get('logo');

        if ($lineItemId === '' || $file === null) {
            return new JsonResponse(['ok' => false, 'error' => 'lineItemId oder logo fehlt'], 400);
        }

        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $allowed = ['image/png', 'image/jpeg', 'image/svg+xml', 'application/pdf'];

        if (!in_array($mime, $allowed, true)) {
            return new JsonResponse(['ok' => false, 'error' => 'Dateityp nicht erlaubt: ' . $mime], 415);
        }
        if ($size > 5 * 1024 * 1024) {
            return new JsonResponse(['ok' => false, 'error' => 'Datei zu groß (max. 5 MB)'], 413);
        }

        // Cart suchen + LineItem finden
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $lineItem = $cart->getLineItems()->get($lineItemId);
        if ($lineItem === null) {
            return new JsonResponse(['ok' => false, 'error' => 'LineItem nicht gefunden'], 404);
        }

        // Speicherort: <shopware-root>/public/media/dosenzauber-logos/<token>-<filename>
        $projectDir = (string) $this->container->getParameter('kernel.project_dir');
        $publicDir = $projectDir . '/public/media/dosenzauber-logos';
        if (!is_dir($publicDir) && !@mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
            return new JsonResponse(['ok' => false, 'error' => 'Upload-Verzeichnis nicht beschreibbar'], 500);
        }

        $safeOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file->getClientOriginalName());
        $token = bin2hex(random_bytes(8));
        $filename = $token . '-' . $safeOriginal;
        $file->move($publicDir, $filename);

        $relativeUrl = '/media/dosenzauber-logos/' . $filename;

        // Payload erweitern
        $payload = $lineItem->getPayload();
        $cfg = is_array($payload['dpDosenzauberConfig'] ?? null) ? $payload['dpDosenzauberConfig'] : [];
        $cfg['laser'] = is_array($cfg['laser'] ?? null) ? $cfg['laser'] : [];
        $cfg['laser']['logoUrl']      = $relativeUrl;
        $cfg['laser']['logoFileName'] = $safeOriginal;
        $cfg['laser']['logoMime']     = $mime;
        $payload['dpDosenzauberConfig'] = $cfg;
        $lineItem->setPayload($payload);

        $this->cartService->recalculate($cart, $context);

        return new JsonResponse([
            'ok'      => true,
            'logoUrl' => $relativeUrl,
        ]);
    }

    private function decodePayload(Request $request): ?array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) $request->getContent();
            if ($raw === '') return null;
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        // Fallback: Form-Post (z.B. via FormData)
        return $request->request->all() ?: null;
    }

    private function loadProductByNumber(string $number, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        $criteria = (new Criteria())->addFilter(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('productNumber', $number)
        );
        $criteria->setLimit(1);

        /** @var SalesChannelProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();
        return $product;
    }

    /**
     * Whitelist + Cast: nur das speichern was wir auch wirklich kennen.
     * Verhindert, dass Frontend beliebige Felder ins payload-Feld smuggeln kann.
     */
    private function sanitizeConfig(array $data): array
    {
        $opt = $data['options'] ?? [];
        $laser = $data['laser'] ?? [];
        $fuell = $data['fuellung'] ?? [];
        $promo = $data['promo'] ?? null;

        return [
            'productNumber' => (string)($data['productNumber'] ?? ''),
            'quantity'      => (int)($data['quantity'] ?? 0),
            'stueckpreis'   => (float)($data['stueckpreis'] ?? 0),
            'einmalkosten'  => (float)($data['einmalkosten'] ?? 0),
            'gesamt'        => (float)($data['gesamt'] ?? 0),
            'staffel'       => (string)($data['staffel'] ?? ''),
            'options' => [
                'laser'      => (bool)($opt['laser'] ?? false),
                'fuellung'   => (bool)($opt['fuellung'] ?? false),
                'verpackung' => (bool)($opt['verpackung'] ?? false),
            ],
            'laser' => [
                'personalisierung' => (bool)($laser['personalisierung'] ?? false),
                'logoFileName'     => isset($laser['logoFileName']) ? (string)$laser['logoFileName'] : null,
            ],
            'fuellung' => [
                'riegelProDose' => (int)($fuell['riegelProDose'] ?? 0),
                'karteVariant'  => (string)($fuell['karteVariant'] ?? 'standard'),
                'karteText'     => isset($fuell['karteText']) && $fuell['karteText'] !== null ? (string)$fuell['karteText'] : null,
            ],
            'verpackung' => (string)($data['verpackung'] ?? 'plano'),
            'promo' => $promo !== null && is_array($promo) ? [
                'code'   => (string)($promo['code']   ?? ''),
                'rabatt' => (float) ($promo['rabatt'] ?? 0),
                'label'  => (string)($promo['label']  ?? ''),
            ] : null,
        ];
    }
}
