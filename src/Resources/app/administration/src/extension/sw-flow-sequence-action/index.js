import { ACTION, GROUP } from '../../constant/byjunoauth-plugin.constant';

const { Component } = Shopware;

Component.override('sw-flow-sequence-action', {
    computed: {
        // Not necessary if you use an existing group
        // Push the `groups` method in computed if you are defining a new group
        groups() {
            this.actionGroups.unshift(GROUP);

            return this.$super('groups');
        },

        modalName() {
            if (this.selectedAction === ACTION.BYJUNO_AUTH) {
                return 'byjuno-flow-plugin-modal';
            }
            return this.$super('modalName');
        },

    },

    methods: {
        getActionTitle(actionName) {
            if (actionName === ACTION.BYJUNO_AUTH) {
                return {
                    value: actionName,
                    icon: 'regular-file-text',
                    label: this.$tc('ByjunoPayment.byjunoAuthFlow'),
                    group: GROUP,
                }
            }

            return this.$super('getActionTitle', actionName);
        },
    },
});
