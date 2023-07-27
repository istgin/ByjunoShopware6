import template from './byjuno-flow-plugin-modal.html.twig';
const { Component } = Shopware;

Component.register('byjuno-flow-plugin-modal', {
    template,

    props: {
        sequence: {
            type: Object,
            required: true,
        },
    },
    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
        },

        onClose() {
            this.$emit('modal-close');
        },

        onAddAction() {
            const sequence = {
                ...this.sequence,
                config: {
                    ...this.config
                },
            };

            this.$emit('process-finish', sequence);
        },
    },
});
