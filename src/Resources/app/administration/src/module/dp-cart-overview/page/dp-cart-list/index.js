import template from './dp-cart-list.html.twig';
import './dp-cart-list.scss';

const { Component } = Shopware;

Component.register('dp-cart-list', {
    template,

    inject: ['loginService'],

    data() {
        return {
            carts: [],
            loading: false,
            error: null,
        };
    },

    created() {
        this.loadCarts();
    },

    methods: {
        async loadCarts() {
            this.loading = true;
            this.error = null;
            try {
                const token = this.loginService.getToken();
                const res = await fetch(`${Shopware.Context.api.apiPath}/_action/dp-cart-overview`, {
                    headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const json = await res.json();
                this.carts = json.data || [];
            } catch (e) {
                this.error = e.message;
                this.carts = [];
            } finally {
                this.loading = false;
            }
        },

        formatDate(dt) {
            if (!dt) return '';
            try {
                return new Date(dt).toLocaleString('de-DE');
            } catch (e) {
                return dt;
            }
        },

        formatPrice(p) {
            if (p === null || p === undefined) return '—';
            try {
                return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(p);
            } catch (e) {
                return p + ' €';
            }
        },

        configSummary(item) {
            const cfg = item.config || {};
            const opt = cfg.options || {};
            const parts = [];
            if (opt.laser)      parts.push('Laser');
            if (opt.fuellung)   parts.push((cfg.fuellung?.riegelProDose || 0) + '×RSW');
            if (opt.verpackung) parts.push(cfg.verpackung === 'konfektioniert' ? 'Konf.' : 'Plano');
            if (cfg.promo?.code) parts.push(cfg.promo.code);
            return parts.join(' · ');
        },
    },
});
