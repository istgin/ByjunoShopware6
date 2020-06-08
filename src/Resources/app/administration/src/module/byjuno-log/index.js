import './page/byjuno-log-list';
import './page/byjuno-log-detail';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('byjuno-log', {
    type: 'plugin',
    name: 'Bundle',
    title: 'byjuno-log.general.mainMenuItemGeneral',
    description: 'sw-property.general.descriptionTextModule',
    color: '#ff3d58',
    icon: 'default-shopping-paper-bag-product',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        list: {
            component: 'byjuno-log-list',
            path: 'list'
        },
        detail: {
            component: 'byjuno-log-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'byjuno.log.list'
            }
        }
    },

    navigation: [{
        label: 'byjuno-log.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'byjuno.log.list',
        icon: 'default-shopping-paper-bag-product',
        position: 100
    }]
});
