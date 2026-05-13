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
    },
});
