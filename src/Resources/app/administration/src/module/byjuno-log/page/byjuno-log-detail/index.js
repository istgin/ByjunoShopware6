import template from './byjuno-log-detail.html.twig';

const { Component, Mixin } = Shopware;

Component.register('byjuno-log-detail', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    data() {
        return {
            bundle: null,
            isLoading: false,
            processSuccess: false,
            repository: null
        };
    },

    computed: {
        options() {
            return [
                { value: 'absolute', name: this.$t('byjuno-log.detail.absoluteText') },
                { value: 'percentage', name: this.$t('byjuno-log.detail.percentageText') }
            ];
        }
    },

    created() {
        this.repository = this.repositoryFactory.create('byjuno_log_entity');
        this.getBundle();
    },

    methods: {
        getBundle() {
            this.repository
                .get(this.$route.params.id, Shopware.Context.api)
                .then((entity) => {
                    this.bundle = entity;
                });
        },

        onClickSave() {
            this.isLoading = true;

            this.repository
                .save(this.bundle, Shopware.Context.api)
                .then(() => {
                    this.getBundle();
                    this.isLoading = false;
                    this.processSuccess = true;
                }).catch((exception) => {
                    this.isLoading = false;
                    this.createNotificationError({
                        title: this.$t('byjuno-log.detail.errorTitle'),
                        message: exception
                    });
                });
        },

        saveFinish() {
            this.processSuccess = false;
        }
    }
});
