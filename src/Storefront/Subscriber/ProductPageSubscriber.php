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

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();

        if (!$this->dataProvider->isDosenzauberProduct($product)) {
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
