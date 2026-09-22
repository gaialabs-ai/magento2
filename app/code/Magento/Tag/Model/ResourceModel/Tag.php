<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Tag resource model.
 */
class Tag extends AbstractDb
{
    /**
     * @var StringUtils
     */
    private $stringUtils;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var AppState
     */
    private $appState;

    /**
     * @param Context $context
     * @param StringUtils $stringUtils
     * @param StoreManagerInterface $storeManager
     * @param AppState $appState
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        StringUtils $stringUtils,
        StoreManagerInterface $storeManager,
        AppState $appState,
        ?string $connectionName = null
    ) {
        $this->stringUtils = $stringUtils;
        $this->storeManager = $storeManager;
        $this->appState = $appState;
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('tag', 'tag_id');
    }

    /**
     * @inheritDoc
     */
    protected function _initUniqueFields()
    {
        $this->_uniqueFields = [[
            'field' => 'name',
            'title' => __('Tag'),
        ]];
        return $this;
    }

    /**
     * Load a tag record by name.
     *
     * @param DataObject $model
     * @param string $name
     * @return void
     */
    public function loadByName(DataObject $model, string $name): void
    {
        if ($name === '') {
            $model->setData([]);
            return;
        }

        if ($this->stringUtils->strlen($name) > 255) {
            $name = $this->stringUtils->substr($name, 0, 255);
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('name = :name');
        $data = $connection->fetchRow($select, ['name' => $name]);

        $model->setData(is_array($data) ? $data : []);
    }

    /**
     * @inheritDoc
     */
    protected function _beforeSave(AbstractModel $object)
    {
        if (!$object->getId() && (int) $object->getData('status') === \Magento\Tag\Model\Tag::STATUS_APPROVED) {
            $searchTag = new DataObject();
            $this->loadByName($searchTag, (string) $object->getData('name'));
            if ($searchTag->getData($this->getIdFieldName())
                && (int) $searchTag->getData('status') === \Magento\Tag\Model\Tag::STATUS_PENDING
            ) {
                $object->setId($searchTag->getData($this->getIdFieldName()));
            }
        }

        if ($this->stringUtils->strlen((string) $object->getData('name')) > 255) {
            $object->setData('name', $this->stringUtils->substr((string) $object->getData('name'), 0, 255));
        }

        return parent::_beforeSave($object);
    }

    /**
     * @inheritDoc
     */
    protected function _afterSave(AbstractModel $object)
    {
        // Legacy Mage_Tag_Model_Resource_Tag::_afterSave persisted base popularity only when
        // the current request was running in the admin area (Mage::app()->getStore()->isAdmin()).
        // We reproduce that by comparing the current app area to Backend / adminhtml. Callers
        // may still set the object's `store` field to target a specific store view — that value
        // becomes the store_id we upsert against tag_properties.
        if (!$this->isAdminAreaRequest()) {
            return parent::_afterSave($object);
        }

        $store = (int) $object->getData('store');
        $tagId = $object->isObjectNew() ? (int) $object->getData('tag_id') : (int) $object->getId();

        $writeAdapter = $this->getConnection();
        $writeAdapter->insertOnDuplicate($this->getTable('tag_properties'), [
            'tag_id' => $tagId,
            'store_id' => $store,
            'base_popularity' => $object->getData('base_popularity') ? (int) $object->getData('base_popularity') : 0,
        ]);

        return parent::_afterSave($object);
    }

    /**
     * True when the current request is being processed in the admin area.
     *
     * @return bool
     */
    private function isAdminAreaRequest(): bool
    {
        try {
            return $this->appState->getAreaCode() === \Magento\Framework\App\Area::AREA_ADMINHTML;
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            return false;
        }
    }

    /**
     * Decrement products count in tag_summary for the given tag ids.
     *
     * @param array $tagIds
     * @return int
     */
    public function decrementProducts(array $tagIds): int
    {
        if ($tagIds === []) {
            return 0;
        }
        $writeAdapter = $this->getConnection();
        return (int) $writeAdapter->update(
            $this->getTable('tag_summary'),
            ['products' => new \Zend_Db_Expr('products - 1')],
            ['tag_id IN (?)' => $tagIds]
        );
    }

    /**
     * Add summary data to specified object.
     *
     * @param DataObject $object
     * @return DataObject
     */
    public function addSummary(DataObject $object): DataObject
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['relation' => $this->getTable('tag_relation')], [])
            ->joinLeft(
                ['summary' => $this->getTable('tag_summary')],
                'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
                ['customers', 'products', 'popularity']
            )
            ->where('relation.tag_id = :tag_id')
            ->where('relation.store_id = :store_id')
            ->limit(1);
        $bind = [
            'tag_id' => (int) $object->getId(),
            'store_id' => (int) $object->getData('store_id'),
        ];
        $row = $connection->fetchRow($select, $bind);
        if ($row) {
            $object->addData($row);
        }
        return $object;
    }

    /**
     * @inheritDoc
     */
    protected function _getLoadSelect($field, $value, $object)
    {
        $select = parent::_getLoadSelect($field, $value, $object);
        if (method_exists($object, 'getAddBasePopularity')
            && $object->getAddBasePopularity()
            && method_exists($object, 'getStoreId')
            && $object->getStoreId() !== null
        ) {
            $select->joinLeft(
                ['properties' => $this->getTable('tag_properties')],
                sprintf(
                    'properties.tag_id = %s.tag_id AND properties.store_id = %d',
                    $this->getMainTable(),
                    (int) $object->getStoreId()
                ),
                'base_popularity'
            );
        }
        return $select;
    }

    /**
     * @inheritDoc
     */
    protected function _afterLoad(AbstractModel $object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('tag_summary'), ['store_id'])
            ->where('tag_id = :tag_id');
        $storeIds = $connection->fetchCol($select, ['tag_id' => (int) $object->getId()]);
        if (method_exists($object, 'setVisibleInStoreIds')) {
            $object->setVisibleInStoreIds(array_map('intval', $storeIds));
        } else {
            $object->setData('visible_in_store_ids', array_map('intval', $storeIds));
        }
        return $this;
    }
}
