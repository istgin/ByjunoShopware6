import template from './byjuno-log-list.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('byjuno-log-list', {
    template,

    inject: [
        'repositoryFactory'
    ],

    data() {
        return {
            repository: null,
            logs: null,
            editable: false,
            settings: false,
            showSettings: false
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        columns() {
            return [{
                property: 'request_id',
                dataIndex: 'request_id',
                label: this.$t('byjuno-log.list.columnName'),
                routerLink: 'byjuno.log.detail',
                allowResize: true,
                primary: true
            }, {
                property: 'request_type',
                dataIndex: 'request_type',
                label: this.$t('byjuno-log.list.columnDiscount'),
                allowResize: true,
            }, {
                property: 'firstname',
                dataIndex: 'firstname',
                label: this.$t('byjuno-log.list.columnDiscountType'),
                allowResize: true
            }, {
                property: 'lastname',
                dataIndex: 'lastname',
                label: this.$t('byjuno-log.list.columnDiscountType'),
                allowResize: true
            }, {
                property: 'ip',
                dataIndex: 'ip',
                label: this.$t('byjuno-log.list.columnDiscountType'),
                allowResize: true
            }, {
                property: 'byjuno_status',
                dataIndex: 'byjuno_status',
                label: this.$t('byjuno-log.list.columnDiscountType'),
                allowResize: true
            }, {
                property: 'createdAt',
                dataIndex: 'createdAt',
                label: this.$t('byjuno-log.list.columnDiscountType'),
                allowResize: true
            }];
        }
    },

    created() {
        this.repository = this.repositoryFactory.create('byjuno_log_entity');

        this.repository
            .search(new Criteria(), Shopware.Context.api)
            .then((result) => {
                this.logs = result;
            });
    },
    methods: {
        openSettings() {
            this.showSettings = false;
        },
    }
});
