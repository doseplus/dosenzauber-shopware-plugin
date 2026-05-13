<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Administration\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin-API: Liste aktiver Warenkörbe mit Dosenzauber-Konfiguration.
 *
 * Liest direkt aus der cart-Tabelle (kein DAL-Entity da Cart eine Sonderform ist),
 * dekomprimiert den payload und filtert nach LineItems mit dpDosenzauberConfig.
 */
class CartOverviewController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route(
        path: '/api/_action/dp-cart-overview',
        name: 'api.action.dp-cart-overview',
        methods: ['GET'],
        defaults: ['_routeScope' => ['api']]
    )]
    public function list(Request $request): JsonResponse
    {
        try {
            $limit = min((int) $request->query->get('limit', 50), 200);

            // cart-Tabelle in Shopware 6.6 (manchmal mit Prefix)
            $rows = $this->connection->fetchAllAssociative(
                'SELECT token, name, payload, compressed, customer_id, sales_channel_id, created_at, updated_at, price
                 FROM cart
                 WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY updated_at DESC
                 LIMIT :limit',
                ['limit' => $limit],
                ['limit' => \PDO::PARAM_INT]
            );

            $carts = [];
            foreach ($rows as $row) {
                $cartInfo = $this->extractDosenzauberInfo($row);
                if ($cartInfo !== null) {
                    $carts[] = $cartInfo;
                }
            }

            return new JsonResponse([
                'total' => count($carts),
                'data'  => $carts,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dosenzauber Cart-Overview: Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function extractDosenzauberInfo(array $row): ?array
    {
        try {
            $payload = $row['payload'];
            // Shopware speichert payload je nach Konfig: serialized PHP, msgpack oder json.
            // compressed=1 → gzcompressed
            if ((int)($row['compressed'] ?? 0) === 1) {
                $payload = @gzuncompress($payload);
                if ($payload === false) return null;
            }

            // Versuch 1: php-serialize
            $cart = @unserialize($payload, ['allowed_classes' => true]);
            $lineItems = null;
            if (is_object($cart) && method_exists($cart, 'getLineItems')) {
                $lineItems = $cart->getLineItems();
            } elseif (is_array($cart) && isset($cart['lineItems'])) {
                $lineItems = $cart['lineItems'];
            }

            if (!$lineItems) {
                // Versuch 2: JSON
                $json = @json_decode($payload, true);
                if (is_array($json) && isset($json['lineItems'])) {
                    $lineItems = $json['lineItems'];
                }
            }

            if (!$lineItems) return null;

            $dpItems = [];
            foreach ($lineItems as $li) {
                $itemPayload = null;
                if (is_object($li) && method_exists($li, 'getPayload')) {
                    $itemPayload = $li->getPayload();
                    $label = method_exists($li, 'getLabel') ? $li->getLabel() : '';
                    $qty = method_exists($li, 'getQuantity') ? $li->getQuantity() : 0;
                } elseif (is_array($li)) {
                    $itemPayload = $li['payload'] ?? null;
                    $label = $li['label'] ?? '';
                    $qty = $li['quantity'] ?? 0;
                } else {
                    continue;
                }

                if (!is_array($itemPayload) || empty($itemPayload['dpDosenzauberConfig'])) {
                    continue;
                }

                $dpItems[] = [
                    'label'    => (string) $label,
                    'quantity' => (int) $qty,
                    'config'   => $itemPayload['dpDosenzauberConfig'],
                ];
            }

            if (empty($dpItems)) return null;

            // Convert binary IDs to hex
            $customerId = !empty($row['customer_id']) ? Uuid::fromBytesToHex($row['customer_id']) : null;
            $salesChannelId = !empty($row['sales_channel_id']) ? Uuid::fromBytesToHex($row['sales_channel_id']) : null;

            return [
                'token'           => $row['token'],
                'name'            => $row['name'],
                'customerId'      => $customerId,
                'salesChannelId'  => $salesChannelId,
                'createdAt'       => $row['created_at'],
                'updatedAt'       => $row['updated_at'],
                'totalPrice'      => $this->extractTotalPrice($row['price'] ?? null),
                'dpLineItems'     => $dpItems,
            ];
        } catch (\Throwable $e) {
            $this->logger->info('Dosenzauber Cart-Extract fail', ['token' => $row['token'] ?? '?', 'err' => $e->getMessage()]);
            return null;
        }
    }

    private function extractTotalPrice($priceData): ?float
    {
        if ($priceData === null) return null;
        // Shopware speichert price oft als serialized CartPrice
        $obj = @unserialize($priceData, ['allowed_classes' => true]);
        if (is_object($obj) && method_exists($obj, 'getTotalPrice')) {
            return (float) $obj->getTotalPrice();
        }
        if (is_array($obj) && isset($obj['totalPrice'])) {
            return (float) $obj['totalPrice'];
        }
        return null;
    }
}
