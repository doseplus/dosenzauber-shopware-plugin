<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\QuantityInformation;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Auto-Scale: Wenn der Kunde die Menge der WD-Hauptdose im Cart ändert,
 * skalieren alle gebundenen NK_*-Options-LineItems automatisch mit.
 *
 * Skalierungs-Regeln:
 *   - NK LG (Lasergravur)     × qty_WD
 *   - NK LG Maschinenrüstung  × 1 (Einmalkosten, scaliert NICHT)
 *   - NK PERS                  × 1 (Einmalkosten)
 *   - RSW (Ritter Sport)       × qty_WD × riegelProDose
 *   - NK Karte                 × 1 (Einmalkosten)
 *   - UV * (Verpackung)        × qty_WD
 *
 * Steffen Anger 2026-05-13: "wenn ich produkte hoch mache bleiben die sub
 * kategorien gleich" — sollen sich proportional ändern.
 */
class AutoScaleProcessor implements CartProcessorInterface
{
    // ProductNummern die EINMALIG sind (qty bleibt 1, egal wie viele WD)
    private const ONETIME_PRODUCTS = [
        'NK LG Maschinenrüstung',
        'NK PERS',
        'NK Karte',
    ];

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $lineItems = $toCalculate->getLineItems();

        // 1. WD-Hauptprodukte mit ihrer Konfig sammeln (group_id → qty + cfg)
        $groups = [];
        foreach ($lineItems as $li) {
            $payload = $li->getPayload();
            $cfg = $payload['dpDosenzauberConfig'] ?? null;
            $groupId = $payload['dpGroupId'] ?? null;
            if (!is_array($cfg) || !$groupId) continue;

            $groups[$groupId] = [
                'qty'    => $li->getQuantity(),
                'riegel' => (int)($cfg['fuellung']['riegelProDose'] ?? 0),
            ];
        }

        // 2. NK-Options-LineItems anpassen
        foreach ($lineItems as $li) {
            $payload = $li->getPayload();
            if (!isset($payload['dpDosenzauberOption'])) continue;

            $groupId = $payload['dpGroupId'] ?? null;
            if (!$groupId || !isset($groups[$groupId])) continue;

            $group = $groups[$groupId];
            $productNumber = $this->getProductNumber($li);

            // Einmalkosten skalieren nicht
            if (in_array($productNumber, self::ONETIME_PRODUCTS, true)) {
                continue;
            }

            // Soll-Menge berechnen
            $desiredQty = $group['qty'];
            // RSW = qty_WD × Riegel pro Dose
            if ($productNumber === 'RSW' && $group['riegel'] > 0) {
                $desiredQty = $group['qty'] * $group['riegel'];
            }

            // Falls Soll ≠ Ist → Menge updaten (KEINE QuantityInformation,
            // sonst Cart-Update-Errors). UI-Lock erfolgt via Twig-Override.
            if ($li->getQuantity() !== $desiredQty && $desiredQty > 0) {
                $li->setQuantity($desiredQty);
            }
        }
    }

    /**
     * Liest die productNumber aus dem LineItem (entweder direkt, aus Payload oder Label).
     */
    private function getProductNumber(LineItem $li): string
    {
        $payload = $li->getPayload();
        $opt = $payload['dpDosenzauberOption'] ?? null;
        if (is_array($opt) && isset($opt['productNumber'])) {
            return (string) $opt['productNumber'];
        }
        return (string)($payload['productNumber'] ?? '');
    }
}
