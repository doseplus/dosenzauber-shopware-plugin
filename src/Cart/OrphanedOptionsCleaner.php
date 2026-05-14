<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Räumt verwaiste Konfigurator-Options-LineItems auf:
 * Wenn das Hauptprodukt (WD-Dose mit dpDosenzauberConfig) gelöscht wurde,
 * werden alle NK_*-Items mit derselben dpGroupId automatisch entfernt.
 *
 * Steffen Anger 2026-05-13: "wenn ich das main produkt lösche bleiben die
 * anderen, mach dass die dann auch weg gehen".
 */
class OrphanedOptionsCleaner implements CartProcessorInterface
{
    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $lineItems = $toCalculate->getLineItems();

        // 1. Alle aktiven Gruppen-IDs sammeln (von WD-Hauptprodukten = haben dpDosenzauberConfig)
        $activeGroupIds = [];
        foreach ($lineItems as $li) {
            $payload = $li->getPayload();
            if (isset($payload['dpDosenzauberConfig']) && is_array($payload['dpDosenzauberConfig'])) {
                $groupId = $payload['dpGroupId'] ?? null;
                if ($groupId) {
                    $activeGroupIds[$groupId] = true;
                }
            }
        }

        // 2. Verwaiste Option-LineItems suchen (dpGroupId gesetzt, aber Hauptprodukt fehlt)
        $orphanedIds = [];
        foreach ($lineItems as $li) {
            $payload = $li->getPayload();
            $groupId = $payload['dpGroupId'] ?? null;
            $isOption = isset($payload['dpDosenzauberOption']);

            if ($isOption && $groupId !== null && !isset($activeGroupIds[$groupId])) {
                $orphanedIds[] = $li->getId();
            }
        }

        // 3. Verwaiste entfernen
        foreach ($orphanedIds as $id) {
            $toCalculate->remove($id);
        }
    }
}
