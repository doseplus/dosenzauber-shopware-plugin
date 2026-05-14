<?php declare(strict_types=1);

namespace Doseplus\DosenzauberConfigurator\Storefront\Subscriber;

use Doseplus\DosenzauberConfigurator\Service\ConfiguratorDataProvider;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ConfiguratorDataProvider $dataProvider) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    /**
     * Kundengruppen-IDs die den Konfigurator sehen dürfen.
     * Gleiche Logik wie für Staffelpreise — nur eingeloggte B2B-Kunden.
     * Steffen Anger 2026-05-13: "wir müssen das nur für später berücksichtigen,
     * dass wir die entsprechenden Codes dann aktivieren, dass nur nach anmelden
     * der Konfigurator auch sichtbar ist."
     */
    private const ALLOWED_GROUPS = [
        '01952490f8c17aa79a89e05f74345d88', // Premium PSI
        '01960fc76ceb70459a6c85aeeb5f0326', // Shopkunden
        '01960fc76d1c7105a5eebd44f0ba491f', // Goldstatus
        '01960fc76d2272f685a85f806b3e5464', // Premium Wiederverkäufer (15%)
        '0198efd7a5cd739ab9426d2769b1427c', // Premium Händler (10%)
    ];

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();

        if (!$this->dataProvider->isDosenzauberProduct($product)) {
            $event->getPage()->assign(['dpDosenzauberActive' => false]);
            return;
        }

        // Login-Gate: nur eingeloggte Kunden in einer erlaubten B2B-Gruppe
        // sehen den Konfigurator. Sonst gleiche Behandlung wie bei Preisen
        // (B2B-Plugin blendet sie ebenfalls aus).
        $customer = $event->getSalesChannelContext()->getCustomer();
        if ($customer === null || !in_array($customer->getGroupId(), self::ALLOWED_GROUPS, true)) {
            $event->getPage()->assign(['dpDosenzauberActive' => false]);
            return;
        }

        $data = $this->dataProvider->getDataForProduct($product);

        $event->getPage()->assign([
            'dpDosenzauberActive' => $data !== null,
            'dpDosenzauberData'   => $data,
        ]);
    }
}
