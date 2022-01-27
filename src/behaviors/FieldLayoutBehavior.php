<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\behaviors;

use Craft;
use craft\base\FieldInterface;
use craft\models\FieldLayout;
use yii\base\Behavior;
use yii\base\InvalidConfigException;

/**
 * Field Layout behavior.
 *
 * @property FieldLayout $fieldLayout
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class FieldLayoutBehavior extends Behavior
{
    /**
     * @var string|null The element type that the field layout will be associated with
     */
    public ?string $elementType = null;

    /**
     * @var string|null The attribute on the owner that holds the field layout ID
     */
    public ?string $idAttribute = null;

    /**
     * @var int|string|callable The field layout ID, or the name of a method on the owner that will return it, or a callback function that will return it
     */
    private $_fieldLayoutId;

    /**
     * @var FieldLayout|null The field layout associated with the owner
     */
    private ?FieldLayout $_fieldLayout = null;

    /**
     * @var FieldInterface[]|null The fields associated with the owner's field layout
     */
    private ?array $_fields = null;

    /**
     * @inheritdoc
     * @throws InvalidConfigException if the behavior was not configured properly
     */
    public function init(): void
    {
        parent::init();

        if (!isset($this->elementType)) {
            throw new InvalidConfigException('The element type has not been set.');
        }

        if (!isset($this->_fieldLayoutId) && !isset($this->idAttribute)) {
            $this->idAttribute = 'fieldLayoutId';
        }
    }

    /**
     * Returns the owner's field layout ID.
     *
     * @return int
     * @throws InvalidConfigException if the field layout ID could not be determined
     */
    public function getFieldLayoutId(): int
    {
        if (is_int($this->_fieldLayoutId)) {
            return $this->_fieldLayoutId;
        }

        if (isset($this->idAttribute)) {
            $id = $this->owner->{$this->idAttribute};
        } else if (is_callable($this->_fieldLayoutId)) {
            $id = call_user_func($this->_fieldLayoutId);
        } else if (is_string($this->_fieldLayoutId)) {
            $id = $this->owner->{$this->_fieldLayoutId}();
        }

        if (!isset($id) || !is_numeric($id)) {
            throw new InvalidConfigException('Unable to determine the field layout ID for ' . get_class($this->owner) . '.');
        }

        return $this->_fieldLayoutId = (int)$id;
    }

    /**
     * Sets the owner's field layout ID.
     *
     * @param int|string|callable $id
     */
    public function setFieldLayoutId($id): void
    {
        $this->_fieldLayoutId = $id;
    }

    /**
     * Returns the owner's field layout.
     *
     * @return FieldLayout
     * @throws InvalidConfigException if the configured field layout ID is invalid
     */
    public function getFieldLayout(): FieldLayout
    {
        if (isset($this->_fieldLayout)) {
            return $this->_fieldLayout;
        }

        try {
            $id = $this->getFieldLayoutId();
        } catch (InvalidConfigException $e) {
            return $this->_fieldLayout = new FieldLayout([
                'type' => $this->elementType,
            ]);
        }

        if (($fieldLayout = Craft::$app->getFields()->getLayoutById($id)) === null) {
            throw new InvalidConfigException('Invalid field layout ID: ' . $id);
        }

        return $this->_fieldLayout = $fieldLayout;
    }

    /**
     * Sets the owner's field layout.
     *
     * @param FieldLayout $fieldLayout
     */
    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $this->_fieldLayout = $fieldLayout;
    }

    /**
     * Returns the fields associated with the owner's field layout.
     *
     * @return FieldInterface[]
     */
    public function getFields(): array
    {
        if (isset($this->_fields)) {
            return $this->_fields;
        }

        try {
            $id = $this->getFieldLayoutId();
        } catch (InvalidConfigException $e) {
            return [];
        }

        return $this->_fields = Craft::$app->getFields()->getFieldsByLayoutId($id);
    }

    /**
     * Sets the fields associated with the owner's field layout
     *
     * @param FieldInterface[] $fields
     */
    public function setFields(array $fields): void
    {
        $this->_fields = $fields;
    }
}
