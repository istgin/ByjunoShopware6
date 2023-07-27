<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Core\Content\Flow\Subscriber;

use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Byjuno\ByjunoPayments\Core\Framework\Event\ByjunoAuthAware;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ByjunoAuthEventCollectorSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            BusinessEventCollectorEvent::NAME => 'addAuth',
        ];
    }

    public function addAuth(BusinessEventCollectorEvent $event): void
    {
        foreach ($event->getCollection()->getElements() as $definition) {
            $className = \explode('\\', ByjunoAuthAware::class);
            $definition->addAware(\lcfirst(\end($className)));
        }
    }
}
