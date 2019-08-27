<?php
namespace craft\gql\types\elements;

use craft\elements\Entry as EntryElement;
use craft\gql\interfaces\Element as ElementInterface;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use craft\gql\base\ObjectType;
use craft\helpers\StringHelper;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Class Entry
 */
class Entry extends ObjectType
{
    /**
     * @inheritdoc
     */
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            EntryInterface::getType(),
            ElementInterface::getType(),
        ];

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function resolve($source, $arguments, $context, ResolveInfo $resolveInfo)
    {
        /** @var EntryElement $source */
        $fieldName = $resolveInfo->fieldName;

        switch ($fieldName) {
            case 'sectionId':
                return $source->sectionId;
            case 'typeId':
                return $source->typeId;
            case 'sectionHandle':
                return $source->getSection()->handle;
            case 'typeHandle':
                return $source->getType()->handle;
        }

        return $source->$fieldName;
    }
}
