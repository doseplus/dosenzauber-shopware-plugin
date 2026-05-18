import template from './sw-order-line-items-grid.html.twig';
import './sw-order-line-items-grid.scss';

const { Component } = Shopware;

Component.override('sw-order-line-items-grid', {
    template,

    methods: {
        hasDosenzauberConfig(lineItem) {
            return !!(lineItem
                && lineItem.payload
                && lineItem.payload.dpDosenzauberConfig);
        },

        dpConfig(lineItem) {
            return lineItem?.payload?.dpDosenzauberConfig || null;
        },

        dpLogoUrl(lineItem) {
            const cfg = this.dpConfig(lineItem);
            return cfg?.laser?.logoUrl || null;
        },

        dpLogoFileName(lineItem) {
            const cfg = this.dpConfig(lineItem);
            return cfg?.laser?.logoFileName || null;
        },

        dpProductCover(lineItem) {
            // 1. eigenes Cover am LineItem (geladen via Order-Detail-Association)
            if (lineItem?.cover?.url) return lineItem.cover.url;
            if (lineItem?.product?.cover?.url) return lineItem.product.cover.url;
            // 2. Fallback: Cover-URL aus Payload (falls beim Cart-Add mit gespeichert)
            const cfg = this.dpConfig(lineItem);
            if (cfg?.productCoverUrl) return cfg.productCoverUrl;
            return null;
        },
    },
});
