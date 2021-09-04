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

class ByjunoLogEntityDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'byjuno_log_entity';
    }

    public function getCollectionClass(): string
    {
        return ByjunoLogEntityCollection::class;
    }

    public function getEntityClass(): string
    {
        return ByjunoLogEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new StringField('request_id', 'request_id'),
            new StringField('request_type', 'request_type'),
            new StringField('firstname', 'firstname'),
            new StringField('lastname', 'lastname'),
            new StringField('ip', 'ip'),
            new StringField('byjuno_status', 'byjuno_status'),
            (new LongTextField('xml_request', 'xml_request'))->addFlags(new Required(), new AllowHtml(), new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            (new LongTextField('xml_response', 'xml_response'))->addFlags(new Required(), new AllowHtml(), new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
