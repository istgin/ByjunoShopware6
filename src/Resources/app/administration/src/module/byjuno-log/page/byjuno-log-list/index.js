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
                label: 'Request Id',
                routerLink: 'byjuno.log.detail',
                allowResize: true,
                primary: true
            }, {
                property: 'request_type',
                dataIndex: 'request_type',
                label: 'Request Type',
                allowResize: true,
            }, {
                property: 'firstname',
                dataIndex: 'firstname',
                label: 'First Name',
                allowResize: true
            }, {
                property: 'lastname',
                dataIndex: 'last name',
                label: 'Last Name',
                allowResize: true
            }, {
                property: 'ip',
                dataIndex: 'ip',
                label: 'IP',
                allowResize: true
            }, {
                property: 'byjuno_status',
                dataIndex: 'byjuno_status',
                label: 'Status',
                allowResize: true
            }, {
                property: 'createdAt',
                dataIndex: 'createdAt',
                label: 'Date',
                allowResize: true
            }];
        }
    },
    created() {
        this.repository = this.repositoryFactory.create('byjuno_log_entity');

        const criteria = new Criteria();
        criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
        this.repository
            .search(criteria, Shopware.Context.api)
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
