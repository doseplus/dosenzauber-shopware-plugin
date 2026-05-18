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
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
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

            // Theilich 2026-05-18 Bug 1: Stock-Defense im Backend — falls Frontend-Check
            // umgangen wird, schicken wir hier einen klaren 409-Fehler statt eine
            // Konfiguration ohne Dose im Cart anzulegen.
            $availableStock = (int) ($product->getAvailableStock() ?? 0);
            if ($availableStock <= 0) {
                return new JsonResponse([
                    'ok'    => false,
                    'error' => 'Diese Dose ist aktuell ausverkauft.',
                ], 409);
            }

            $cart = $this->cartService->getCart($context->getToken(), $context);

            // Konfigurations-Gruppen-ID, damit zusammengehörige LineItems später
            // identifizierbar sind (z.B. wenn der User die Hauptposition löscht,
            // sollen auch alle Options-Positionen gelöscht werden).
            $configGroupId = Uuid::randomHex();
            $sanitized = $this->sanitizeConfig($data);
            $sanitized['dpGroupId'] = $configGroupId;

            // Produkt-Cover-URL im Payload mitsenden, damit das Admin-Order-Detail
            // den Konfigurations-Block mit dem richtigen Produkt-Bild rendern kann
            // auch wenn die Line-Item-Cover-Association nicht standardmäßig geladen ist.
            $coverUrl = $product->getCover()?->getMedia()?->getUrl();
            if ($coverUrl) {
                $sanitized['productCoverUrl'] = $coverUrl;
            }

            // Haupt-LineItem: das WD-Produkt selbst (Shopware-Staffel greift).
            // Eindeutige ID im Factory-Aufruf, sonst nutzt ProductLineItemFactory einen
            // deterministischen Hash → mehrfache Konfigurationen würden kollidieren.
            $lineItem = $this->lineItemFactory->create([
                'id'           => Uuid::randomHex(),
                'type'         => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $product->getId(),
                'quantity'     => $quantity,
            ], $context);
            $lineItem->setPayloadValue('dpDosenzauberConfig', $sanitized);
            $lineItem->setPayloadValue('dpGroupId', $configGroupId);
            // DZ-Hauptprodukt bleibt removable=TRUE, damit Shopware das Remove-Form rendert.
            // Der × wird im Twig-Override umgeleitet auf /dosenzauber/remove-config
            // welcher dann DZ + alle NK_*-Optionen gemeinsam entfernt.
            $lineItem->setRemovable(true);
            $lineItem->setStackable(true);

            // Beschreibendes Label für Admin-Sichtbarkeit
            $lineItem->setLabel($this->buildConfigLabel($product->getProductNumber(), $sanitized));

            // Performance: Alle Optionen-LineItems vorbereiten und in EINEM cartService->add()
            // mit Array hinzufügen. Shopware recalculiert dann nur EINMAL statt 6× — spart 1-3s.
            $allItems = [$lineItem];
            $optionItems = $this->buildOptionLineItems($sanitized, $product, $quantity, $configGroupId, $context);
            foreach ($optionItems as $oi) {
                $allItems[] = $oi;
            }

            $cart = $this->cartService->add($cart, $allItems, $context);

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

                    // Theilich 2026-05-18 Bug 5: Doppelter Rabatt verhindern.
                    // Wenn jetzt MEHRERE aktive Promotion-LineItems im Cart sind (z.B. weil
                    // schon eine automatische Promotion lief), behalten wir nur den größten
                    // Rabatt — alle anderen Promotions werden entfernt.
                    $promoLineItems = $cart->getLineItems()->filter(fn(LineItem $li) => $li->getType() === 'promotion');
                    if ($promoLineItems->count() > 1) {
                        // Größter Discount = niedrigste (negativste) totalPrice
                        $bestPromoId = null;
                        $bestDiscount = 0.0;
                        foreach ($promoLineItems as $li) {
                            $price = $li->getPrice()?->getTotalPrice() ?? 0.0;
                            if ($price < $bestDiscount) {
                                $bestDiscount = $price;
                                $bestPromoId = $li->getId();
                            }
                        }
                        $removedCodes = [];
                        foreach ($promoLineItems as $li) {
                            if ($li->getId() !== $bestPromoId) {
                                $code = $li->getReferencedId() ?: ($li->getPayload()['code'] ?? '');
                                $removedCodes[] = (string) $code;
                                $cart = $this->cartService->remove($cart, $li->getId(), $context);
                            }
                        }
                        if (!empty($removedCodes)) {
                            $this->logger->info('Dosenzauber Promo-Filter: nur größten Rabatt behalten', [
                                'kept'    => $bestPromoId,
                                'removed' => $removedCodes,
                            ]);
                        }
                    }

                    // Shopware schreibt bei ungültigem Code einen Error in den Cart,
                    // aber bei erfolgreichem Code ein Notice ("Discount X has been added") —
                    // beide müssen unterschieden werden über getLevel() bzw. den Key.
                    $cartErrors = $cart->getErrors()->getElements();
                    $foundPromoError = false;
                    foreach ($cartErrors as $err) {
                        /** @var Error $err */
                        $key = method_exists($err, 'getMessageKey') ? $err->getMessageKey() : '';
                        $params = method_exists($err, 'getParameters') ? $err->getParameters() : [];
                        $level = method_exists($err, 'getLevel') ? $err->getLevel() : 0;

                        // Level 0=NOTICE/INFO, 10=WARNING, 20=ERROR. Nur ERROR ist ein Problem.
                        if ($level < 20) {
                            continue;
                        }

                        // Echte Fehler-Keys: "promotion-not-found", "promotion-not-eligible",
                        // "promotion-already-placed-in-cart", etc. — alle ENDEN nicht mit "added".
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
     * Entfernt eine komplette Dosenzauber-Konfiguration aus dem Cart:
     * das Haupt-WD-Produkt + alle NK_*-Optionen mit derselben dpGroupId.
     */
    #[Route(
        path: '/dosenzauber/remove-config/{lineItemId}',
        name: 'frontend.dosenzauber.remove-config',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']]
    )]
    public function removeConfig(string $lineItemId, Request $request, SalesChannelContext $context): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $mainItem = $cart->getLineItems()->get($lineItemId);

            if ($mainItem !== null) {
                $payload = $mainItem->getPayload();
                $groupId = $payload['dpGroupId'] ?? null;

                // Alle LineItem-IDs der Gruppe sammeln (Haupt + Optionen)
                $idsToRemove = [$lineItemId];
                if ($groupId) {
                    foreach ($cart->getLineItems() as $li) {
                        if ($li->getId() === $lineItemId) continue;
                        $liPayload = $li->getPayload();
                        if (($liPayload['dpGroupId'] ?? null) === $groupId) {
                            $idsToRemove[] = $li->getId();
                        }
                    }
                }

                // Removable temporär zurücksetzen, damit cart->remove() funktioniert
                foreach ($idsToRemove as $id) {
                    $li = $cart->getLineItems()->get($id);
                    if ($li) {
                        $li->setRemovable(true);
                        $cart->remove($id);
                    }
                }

                $this->cartService->recalculate($cart, $context);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Dosenzauber removeConfig fail', ['err' => $e->getMessage()]);
        }

        // Redirect zurück zur Cart-Seite (oder Referer)
        $referer = $request->headers->get('referer') ?? '/checkout/cart';
        return new \Symfony\Component\HttpFoundation\RedirectResponse($referer);
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
     * Baut ein beschreibendes Label aus der Konfiguration. Wird sichtbar im Admin
     * unter Bestellungen → LineItem-Übersicht UND im Cart selbst. Damit Steffen die
     * Konfiguration auf einen Blick sieht, ohne in Payload-JSON wühlen zu müssen.
     */
    private function buildConfigLabel(string $productNumber, array $cfg): string
    {
        $parts = [];
        $opt   = $cfg['options']  ?? [];
        $laser = $cfg['laser']    ?? [];
        $fuell = $cfg['fuellung'] ?? [];

        // Header: WD-Nummer + DZ-Nummer falls anders
        $dzNum = (string)($cfg['productNumber'] ?? '');
        $parts[] = $productNumber . ($dzNum && $dzNum !== $productNumber ? " ($dzNum)" : '');

        // Lasergravur
        if (!empty($opt['laser'])) {
            $laserBits = ['Lasergravur'];
            if (!empty($laser['personalisierung'])) $laserBits[] = 'pers.';
            if (!empty($laser['logoFileName']))     $laserBits[] = 'Logo:' . $laser['logoFileName'];
            $parts[] = implode(' ', $laserBits);
        }

        // Befüllung
        if (!empty($opt['fuellung'])) {
            $riegel = (int)($fuell['riegelProDose'] ?? 0);
            $fuellBits = ['Befüllung ' . $riegel . '× RSW'];
            $karteVar = (string)($fuell['karteVariant'] ?? 'standard');
            if ($karteVar === 'persoenlich') {
                $text = (string)($fuell['karteText'] ?? '');
                $fuellBits[] = 'Karte mit pers. Text';
                if ($text !== '') {
                    $short = mb_substr($text, 0, 50);
                    $fuellBits[] = '"' . $short . (mb_strlen($text) > 50 ? '…' : '') . '"';
                }
            } else {
                $fuellBits[] = 'Karte: Dosenmotiv';
            }
            $parts[] = implode(' ', $fuellBits);
        }

        // Verpackung
        if (!empty($opt['verpackung'])) {
            $verp = (string)($cfg['verpackung'] ?? 'plano');
            $parts[] = 'Verp. ' . $verp;
        }

        // Promo
        if (!empty($cfg['promo']['code'])) {
            $parts[] = 'Promo:' . $cfg['promo']['code'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Fügt die Konfigurations-Optionen als reale Shopware-Produkte zum Cart hinzu.
     *
     * Optionen mit Mengen-Faktor (× DZ-Quantity):
     *   - NK_LG (Lasergravur)                            wenn options.laser
     *   - NK_RSW (Ritter Sport)        × riegelProDose   wenn options.fuellung
     *   - NK_UV_PLANO / NK_UV_KONF                       wenn options.verpackung
     *
     * Einmalkosten (qty = 1):
     *   - NK_LG_MASCHINE                                 wenn options.laser
     *   - NK_PERS                                        wenn options.laser + laser.personalisierung
     *   - NK_KARTE_TEXT                                  wenn options.fuellung + fuellung.karteVariant=persoenlich
     */
    /**
     * Baut alle Option-LineItems (NK LG / Maschine / PERS / RSW / NK Karte / UV plano|konfektioniert).
     * Lädt alle benötigten Helper-Produkte in EINEM DB-Query (vs. 5-6 separate Queries).
     * Gibt Array von LineItems zurück, das dann gesammelt in cartService->add() übergeben wird.
     *
     * @return LineItem[]
     */
    private function buildOptionLineItems(
        array $cfg,
        SalesChannelProductEntity $baseProduct,
        int $quantity,
        string $configGroupId,
        SalesChannelContext $context
    ): array {
        $options = $cfg['options'] ?? [];
        $laser   = $cfg['laser'] ?? [];
        $fuell   = $cfg['fuellung'] ?? [];

        $additions = [];

        if (!empty($options['laser'])) {
            $additions[] = ['number' => 'NK LG', 'qty' => $quantity, 'label' => 'Lasergravur'];
            $additions[] = ['number' => 'NK LG Maschinenrüstung', 'qty' => 1, 'label' => 'Maschinenrüstung Laser'];
            if (!empty($laser['personalisierung'])) {
                $additions[] = ['number' => 'NK PERS', 'qty' => 1, 'label' => 'Personalisierung'];
            }
        }

        if (!empty($options['fuellung'])) {
            $riegel = max(0, (int)($fuell['riegelProDose'] ?? 0));
            if ($riegel > 0) {
                $additions[] = ['number' => 'RSW', 'qty' => $quantity * $riegel, 'label' => 'Ritter Sport Mini'];
            }
            if (($fuell['karteVariant'] ?? '') === 'persoenlich') {
                $additions[] = ['number' => 'NK Karte', 'qty' => 1, 'label' => 'Karte mit pers. Text'];
            }
        }

        if (!empty($options['verpackung'])) {
            $verp = (string)($cfg['verpackung'] ?? 'plano');
            // uvNumber bereits aus cfg['uvNumber'] verfügbar — kein zweiter dataProvider-Aufruf nötig
            $uvNumber = (string)($cfg['uvNumber'] ?? '');
            if ($uvNumber === '') {
                $uvNumber = $this->dataProvider->getDataForProduct($baseProduct, $context)['uvNumber'] ?? '';
            }
            if ($uvNumber !== '') {
                $variant = $verp === 'konfektioniert' ? 'konfektioniert' : 'plano';
                $additions[] = [
                    'number' => $uvNumber . ' ' . $variant,
                    'qty'    => $quantity,
                    'label'  => 'Versandverpackung ' . ucfirst($verp) . ' (' . $uvNumber . ')',
                ];
            }
        }

        if (empty($additions)) return [];

        // Performance: alle Helfer in EINEM Query laden statt 5-6 einzeln
        $numbers = array_unique(array_column($additions, 'number'));
        $criteria = (new Criteria())->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter('productNumber', $numbers));
        $products = $this->productRepository->search($criteria, $context)->getEntities();
        $productByNumber = [];
        foreach ($products as $p) {
            /** @var SalesChannelProductEntity $p */
            $productByNumber[$p->getProductNumber()] = $p;
        }

        $items = [];
        foreach ($additions as $add) {
            $optProduct = $productByNumber[$add['number']] ?? null;
            if ($optProduct === null) {
                $this->logger->warning('Dosenzauber: Option-Produkt nicht gefunden', ['number' => $add['number']]);
                continue;
            }
            $optItem = $this->lineItemFactory->create([
                'id'           => Uuid::randomHex(),
                'type'         => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $optProduct->getId(),
                'quantity'     => $add['qty'],
            ], $context);
            $optItem->setPayloadValue('dpGroupId', $configGroupId);
            $optItem->setPayloadValue('dpDosenzauberOption', [
                'baseProductNumber' => $baseProduct->getProductNumber(),
                'baseLabel'         => $add['label'],
                'productNumber'     => $add['number'],
            ]);
            $optItem->setRemovable(true);
            $optItem->setStackable(true);
            $items[] = $optItem;
        }

        return $items;
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
