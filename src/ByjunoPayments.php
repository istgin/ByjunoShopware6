<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments;

use Byjuno\ByjunoPayments\Service\ByjunoCorePayment;
use Byjuno\ByjunoPayments\Service\ByjunoInstallmentPayment;
use Byjuno\ByjunoPayments\Service\ByjunoInvoicePayment;
use mysql_xdevapi\Exception;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class ByjunoPayments extends Plugin
{
    public const BYJUNO_INVOICE             = '1f865e2aa66e41fa88f81decfa1ebb65';
    public const BYJUNO_INSTALLMENT         = '043946a0f1234380a7979f9fd12ff69f';

    public const BYJUNO_RETRY = 'byjuno_doc_retry';
    public const BYJUNO_SENT = 'byjuno_doc_sent';
    public const BYJUNO_TIME = 'byjuno_time';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');
    }

    public const CUSTOM_FIELDS = [
    [
            'id'     => '043946a045664646464649f9fd2ff69f',
            'name'   => 'custom_byjuno',
            'config' => [
                'label' => [
                    'en-GB' => 'Byjuno',
                    'de-DE' => 'Byjuno',
                ],
            ],
            'customFields' => [
                [
                    'name'   => self::BYJUNO_RETRY,
                    'type'   => CustomFieldTypes::INT,
                    'id'     => '6bb387512a5c0cb5fd654780a1e8998d',
                    'config' => [
                        'label' => [
                            'en-GB' => 'Byjuno retry count',
                            'de-DE' => 'Byjuno retry count',
                        ],
                    ],
                ],
                [
                    'name'   => self::BYJUNO_SENT,
                    'type'   => CustomFieldTypes::INT,
                    'id'     => '494624b325aaf606184c15cb6217dc34',
                    'config' => [
                        'label' => [
                            'en-GB' => 'Byjuno sent',
                            'de-DE' => 'Byjuno sent',
                        ],
                    ],
                ],
                [
                    'name'   => self::BYJUNO_TIME,
                    'type'   => CustomFieldTypes::INT,
                    'id'     => '494624b325aaf606184c22666217dc34',
                    'config' => [
                        'label' => [
                            'en-GB' => 'Byjuno time',
                            'de-DE' => 'Byjuno time',
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function installCustom(InstallContext $context): void
    {
        $customFieldRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository->upsert(self::CUSTOM_FIELDS, $context->getContext());
    }

    public function uninstallCustom(UninstallContext $context): void
    {
        $customFieldRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository->delete(self::CUSTOM_FIELDS, $context->getContext());
    }

    public function install(InstallContext $context): void
    {
        $this->installCustom($context);
        $this->addPaymentMethod($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        $this->uninstallCustom($context);
        $this->setPaymentMethodIsActive(false, $context->getContext());
    }

    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodIds();

        // Payment method exists already, no need to continue here
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $paymentRepository = $this->container->get('payment_method.repository');
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);
        $optionsInvoice = [
            'handlerIdentifier' => ByjunoCorePayment::class,
            'id' => self::BYJUNO_INVOICE,
            'position' => 0,
            'active' => false,
            'pluginId' => $pluginId,
            'translations' => [
                'de-DE' => [
                    'name' => 'Byjuno invoice (DE)',
                    'description' => 'Pay with Byjuno invoice (DE)',
                ],
                'en-GB' => [
                    'name' => 'Byjuno invoice',
                    'description' => 'Pay with Byjuno invoice',
                ],
            ],
        ];
        $optionsInstallment = [
            'handlerIdentifier' => ByjunoCorePayment::class,
            'id' => self::BYJUNO_INSTALLMENT,
            'position' => 0,
            'active' => false,
            'pluginId' => $pluginId,
            'translations' => [
                'de-DE' => [
                    'name' => 'Byjuno installment (DE)',
                    'description' => 'Pay with Byjuno installment (DE)',
                ],
                'en-GB' => [
                    'name' => 'Byjuno installment',
                    'description' => 'Pay with Byjuno installment',
                ],
            ],
        ];
        if ($paymentMethodExists) {
            foreach ($paymentMethodExists as $key => $val) {
                if ($val == self::BYJUNO_INVOICE) {
                    $paymentRepository->update([$optionsInvoice], $context);
                }
                if ($val == self::BYJUNO_INSTALLMENT) {
                    $paymentRepository->update([$optionsInstallment], $context);
                }
            }
        } else {
            $paymentRepository->create([$optionsInvoice], $context);
            $paymentRepository->create([$optionsInstallment], $context);
        }
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodIds = $this->getPaymentMethodIds();

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodIds) {
            return;
        }

        foreach ($paymentMethodIds as $key => $val) {
            $paymentMethod = [
                'id' => $val,
                'active' => $active,
            ];
            $paymentRepository->update([$paymentMethod], $context);
        }
    }

    private function getPaymentMethodIds(): ?array
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', ByjunoCorePayment::class));
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds();
    }
}
