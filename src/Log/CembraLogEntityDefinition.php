<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Log;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\AllowHtml;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class CembraLogEntityDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'cembra_log_entity';
    }

    public function getCollectionClass(): string
    {
        return CembraLogEntityCollection::class;
    }

    public function getEntityClass(): string
    {
        return CembraLogEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new StringField('request_id', 'request_id'),
            new StringField('request_type', 'request_type'),
            new StringField('firstname', 'firstname'),
            new StringField('lastname', 'lastname'),
            new StringField('town', 'town'),
            new StringField('postcode', 'postcode'),
            new StringField('street', 'street'),
            new StringField('country', 'country'),
            new StringField('ip', 'ip'),
            new StringField('cembra_status', 'cembra_status'),
            new StringField('order_id', 'order_id'),
            new StringField('transaction_id', 'transaction_id'),
            (new LongTextField('request', 'request'))->addFlags(new Required(), new AllowHtml(false), new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            (new LongTextField('response', 'response'))->addFlags(new Required(), new AllowHtml(false), new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
