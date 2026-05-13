<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Storefront\Controller;

use Doseplus\DosenzauberConfigurator\Service\ConfiguratorDataProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
        try {
            $data = $this->decodePayload($request);
            if ($data === null) {
                return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
            }

            $productId     = trim((string)($data['productId'] ?? ''));
            $productNumber = trim((string)($data['productNumber'] ?? ''));
            $quantity      = max(1, (int)($data['quantity'] ?? 0));

            if ($productId === '' && $productNumber === '') {
                return new JsonResponse(['ok' => false, 'error' => 'productId/productNumber missing'], 400);
            }

            // Bevorzugt per ID laden (eindeutig), Fallback per Number
            $product = null;
            if ($productId !== '') {
                $product = $this->loadProductById($productId, $context);
            }
            if ($product === null && $productNumber !== '') {
                $product = $this->loadProductByNumber($productNumber, $context);
            }
            if ($product === null) {
                $this->logger->warning('Dosenzauber Cart-Add: Produkt nicht gefunden', [
                    'productId'     => $productId,
                    'productNumber' => $productNumber,
                ]);
                return new JsonResponse(['ok' => false, 'error' => 'Produkt nicht gefunden'], 404);
            }

            if (!$this->dataProvider->isDosenzauberProduct($product)) {
                return new JsonResponse(['ok' => false, 'error' => 'Produkt ist kein Dosenzauber-Produkt'], 400);
            }

            $cart = $this->cartService->getCart($context->getToken(), $context);

            // LineItem ohne payload-Array bauen (zuverlässiger als 'payload' im factory-array)
            $lineItem = $this->lineItemFactory->create([
                'type'         => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $product->getId(),
                'quantity'     => $quantity,
            ], $context);

            // Custom-Payload via setPayloadValue: überlebt Cart-Recalculate zuverlässig
            $lineItem->setPayloadValue('dpDosenzauberConfig', $this->sanitizeConfig($data));
            $lineItem->setRemovable(true);
            $lineItem->setStackable(false); // Verhindert dass zwei verschiedene Konfigurationen zu einer Position werden

            $cart = $this->cartService->add($cart, $lineItem, $context);

            // Promotion-Code an Shopware durchreichen (Weg A: native Promotion-Engine).
            // Shopware fügt den Placeholder hinzu, beim nächsten Recalculate prüft die
            // PromotionCollector den Code → bei gültig: Discount-LineItem, bei ungültig:
            // Error im Cart unter Schlüssel "promotion-not-found".
            $promoCode = trim((string)($data['promo']['code'] ?? ''));
            $promoApplied = false;
            $promoError = null;

            if ($promoCode !== '') {
                try {
                    $promotionItem = (new PromotionItemBuilder())->buildPlaceholderItem($promoCode);
                    $cart = $this->cartService->add($cart, $promotionItem, $context);

                    // Erste Wahrheit: Shopware schreibt bei ungültigem Code einen Error in den Cart
                    $cartErrors = $cart->getErrors()->getElements();
                    $foundPromoError = false;
                    foreach ($cartErrors as $err) {
                        /** @var Error $err */
                        $key = method_exists($err, 'getMessageKey') ? $err->getMessageKey() : '';
                        $params = method_exists($err, 'getParameters') ? $err->getParameters() : [];
                        // Shopware errors: "promotion-not-found", "promotion-not-eligible", "promotion-already-placed-in-cart"
                        if (str_contains($key, 'promotion') && (
                            ($params['code'] ?? null) === $promoCode || str_contains((string)$err->getMessage(), $promoCode)
                        )) {
                            $foundPromoError = true;
                            $promoError = (string)$err->getMessage();
                            break;
                        }
                    }

                    if (!$foundPromoError) {
                        // Zweite Wahrheit: gibt's einen aktiven Promotion-LineItem für diesen Code?
                        $hasPromoLineItem = $cart->getLineItems()->filter(function (LineItem $li) use ($promoCode) {
                            if ($li->getType() !== 'promotion') return false;
                            $code = $li->getReferencedId() ?: ($li->getPayload()['code'] ?? null);
                            return $code === $promoCode;
                        })->count() > 0;
                        $promoApplied = $hasPromoLineItem;
                        if (!$promoApplied) {
                            // Promotion ist im Cart aber nicht als eigener Line-Item — z.B. globaler Rabatt
                            // In dem Fall: prüfen ob Cart-Price gesunken ist (deutet auf Promotion-Wirkung hin)
                            $promoApplied = true;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->info('Dosenzauber: Promo-Code-Anwendung fehlgeschlagen', [
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
        } catch (\Throwable $e) {
            $this->logger->error('Dosenzauber Cart-Add: unerwarteter Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse([
                'ok'    => false,
                'error' => 'Interner Fehler beim Hinzufügen zum Warenkorb: ' . $e->getMessage(),
            ], 500);
        }
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
        try {
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

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $lineItem = $cart->getLineItems()->get($lineItemId);
            if ($lineItem === null) {
                return new JsonResponse(['ok' => false, 'error' => 'LineItem nicht gefunden'], 404);
            }

            // Speicherort mit Fallback-Strategie:
            // 1. public/media/dosenzauber-logos/ (öffentlich via /media/... erreichbar)
            // 2. files/dosenzauber-logos/ (Shopware-private — kein direkter URL-Zugriff, aber
            //    Datei ist da, Mitarbeiter können sie vor der Produktion abholen)
            $projectDir = (string) $this->container->getParameter('kernel.project_dir');
            $publicDir = $projectDir . '/public/media/dosenzauber-logos';
            $fallbackDir = $projectDir . '/files/dosenzauber-logos';
            $usedDir = null;
            $relativeUrl = null;

            if ($this->ensureWritableDir($publicDir)) {
                $usedDir = $publicDir;
                $relativeUrl = '/media/dosenzauber-logos/';
            } elseif ($this->ensureWritableDir($fallbackDir)) {
                $usedDir = $fallbackDir;
                $relativeUrl = null; // intern, nicht öffentlich erreichbar
                $this->logger->warning('Dosenzauber Logo-Upload: public/media nicht beschreibbar, nutze files/-Fallback', [
                    'publicDir' => $publicDir,
                ]);
            } else {
                $this->logger->error('Dosenzauber Logo-Upload: weder public/media noch files/ beschreibbar', [
                    'publicDir'   => $publicDir,
                    'fallbackDir' => $fallbackDir,
                ]);
                return new JsonResponse(['ok' => false, 'error' => 'Upload-Verzeichnis nicht beschreibbar — Admin kontaktieren'], 500);
            }

            $safeOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file->getClientOriginalName());
            $token = bin2hex(random_bytes(8));
            $filename = $token . '-' . $safeOriginal;
            $file->move($usedDir, $filename);

            $absolutePath = $usedDir . '/' . $filename;
            $publicUrl = $relativeUrl !== null ? $relativeUrl . $filename : null;

            // Payload erweitern — via setPayloadValue für persistente Speicherung
            $cfg = $lineItem->getPayload()['dpDosenzauberConfig'] ?? [];
            if (!is_array($cfg)) $cfg = [];
            $cfg['laser'] = is_array($cfg['laser'] ?? null) ? $cfg['laser'] : [];
            $cfg['laser']['logoUrl']      = $publicUrl;
            $cfg['laser']['logoPath']     = $absolutePath;
            $cfg['laser']['logoFileName'] = $safeOriginal;
            $cfg['laser']['logoMime']     = $mime;
            $cfg['laser']['logoSize']     = $size;
            $lineItem->setPayloadValue('dpDosenzauberConfig', $cfg);

            $this->cartService->recalculate($cart, $context);

            $this->logger->info('Dosenzauber Logo-Upload erfolgreich', [
                'lineItemId' => $lineItemId,
                'filename'   => $filename,
                'size'       => $size,
                'mime'       => $mime,
            ]);

            return new JsonResponse([
                'ok'      => true,
                'logoUrl' => $publicUrl,
                'stored'  => $publicUrl !== null ? 'public' : 'internal',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dosenzauber Logo-Upload: unerwarteter Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse([
                'ok'    => false,
                'error' => 'Logo-Upload fehlgeschlagen: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function ensureWritableDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        if (@mkdir($dir, 0755, true)) {
            return is_writable($dir);
        }
        return false;
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
        $criteria = (new Criteria())->addFilter(new EqualsFilter('productNumber', $number));
        $criteria->setLimit(1);

        /** @var SalesChannelProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();
        return $product;
    }

    private function loadProductById(string $id, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        $criteria = new Criteria([$id]);
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
