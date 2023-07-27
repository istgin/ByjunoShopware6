<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Log;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(ByjunoLogEntity $entity)
 * @method void              set(string $key, ByjunoLogEntity $entity)
 * @method ByjunoLogEntity[]    getIterator()
 * @method ByjunoLogEntity[]    getElements()
 * @method ByjunoLogEntity|null get(string $key)
 * @method ByjunoLogEntity|null first()
 * @method ByjunoLogEntity|null last()
 */
class ByjunoLogEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ByjunoLogEntity::class;
    }
}