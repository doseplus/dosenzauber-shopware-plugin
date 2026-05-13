import './page/dp-cart-list';

const { Module } = Shopware;

Module.register('dp-cart-overview', {
    type: 'plugin',
    name: 'Dosenzauber Carts',
    title: 'dp-cart-overview.module.title',
    description: 'dp-cart-overview.module.description',
    color: '#e53009',
    icon: 'regular-shopping-basket',

    snippets: {
        'de-DE': {
            'dp-cart-overview': {
                module: {
                    title: 'Dosenzauber Carts',
                    description: 'Aktive Warenkörbe mit Konfigurator-Daten',
                },
                list: {
                    title: 'Aktive Dosenzauber Warenkörbe',
                    empty: 'Keine aktiven Warenkörbe mit Dosenzauber-Konfiguration gefunden.',
                    refresh: 'Neu laden',
                    columnToken: 'Cart-Token',
                    columnCustomer: 'Kunde',
                    columnUpdated: 'Geändert',
                    columnPositions: 'Konfigurationen',
                    columnTotal: 'Gesamt',
                },
            },
        },
        'en-GB': {
            'dp-cart-overview': {
                module: {
                    title: 'Dosenzauber Carts',
                    description: 'Active carts with configurator data',
                },
                list: {
                    title: 'Active Dosenzauber Carts',
                    empty: 'No active carts with Dosenzauber configuration found.',
                    refresh: 'Refresh',
                    columnToken: 'Cart token',
                    columnCustomer: 'Customer',
                    columnUpdated: 'Updated',
                    columnPositions: 'Configurations',
                    columnTotal: 'Total',
                },
            },
        },
    },

    routes: {
        list: {
            component: 'dp-cart-list',
            path: 'list',
        },
    },

    navigation: [{
        id: 'dp-cart-overview',
        label: 'dp-cart-overview.module.title',
        color: '#e53009',
        path: 'dp.cart.overview.list',
        icon: 'regular-shopping-basket',
        parent: 'sw-order',
        position: 100,
    }],
});
