<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Log;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(CembraLogEntity $entity)
 * @method void              set(string $key, CembraLogEntity $entity)
 * @method CembraLogEntity[]    getIterator()
 * @method CembraLogEntity[]    getElements()
 * @method CembraLogEntity|null get(string $key)
 * @method CembraLogEntity|null first()
 * @method CembraLogEntity|null last()
 */
class CembraLogEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return CembraLogEntity::class;
    }
}