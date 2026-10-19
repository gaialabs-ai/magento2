<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
namespace Magento\Tag\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Tag resource model
 */
class Tag extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param StoreManagerInterface $storeManager
     * @param string $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        StoreManagerInterface $storeManager,
        $connectionName = null
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context, $connectionName);
    }

    /**
     * Resource initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('tag', 'tag_id');
    }

    /**
     * Initialize unique fields
     *
     * @return $this
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
     * Load tag data by name
     *
     * @param \Magento\Framework\DataObject $model
     * @param string $name
     * @return bool|null
     */
    public function loadByName($model, $name)
    {
        if ($name) {
            $connection = $this->getConnection();
            $select = $connection->select();
            if (mb_strlen($name) > 255) {
                $name = mb_substr($name, 0, 255);
            }
            $select->from($this->getMainTable())->where('name = :name');
            $data = $connection->fetchRow($select, ['name' => $name]);
            $model->setData(is_array($data) ? $data : []);
        } else {
            return false;
        }
        return null;
    }

    /**
     * Perform actions before object save
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _beforeSave(AbstractModel $object)
    {
        if (!$object->getId() && $object->getStatus() == $object->getApprovedStatus()) {
            $searchTag = new \Magento\Framework\DataObject();
            $this->loadByName($searchTag, $object->getName());
            if ($searchTag->getData($this->getIdFieldName())
                && $searchTag->getStatus() == $object->getPendingStatus()
            ) {
                $object->setId($searchTag->getData($this->getIdFieldName()));
            }
        }
        if (mb_strlen((string)$object->getName()) > 255) {
            $object->setName(mb_substr($object->getName(), 0, 255));
        }
        return parent::_beforeSave($object);
    }

    /**
     * Perform actions after object save
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        if (!$object->getStore() || $this->storeManager->getStore()->getCode() !== Store::ADMIN_CODE) {
            return parent::_afterSave($object);
        }
        $tagId = $object->isObjectNew() ? $object->getTagId() : $object->getId();
        $connection = $this->getConnection();
        $connection->insertOnDuplicate(
            $this->getTable('tag_properties'),
            [
                'tag_id' => $tagId,
                'store_id' => $object->getStore(),
                'base_popularity' => $object->getBasePopularity() ? $object->getBasePopularity() : 0,
            ]
        );
        return parent::_afterSave($object);
    }

    /**
     * Retrieve select object for load object data
     *
     * @param string $field
     * @param mixed $value
     * @param AbstractModel $object
     * @return \Magento\Framework\DB\Select
     */
    protected function _getLoadSelect($field, $value, $object)
    {
        $select = parent::_getLoadSelect($field, $value, $object);
        if ($object->getAddBasePopularity() && $object->hasStoreId()) {
            $select->joinLeft(
                ['properties' => $this->getTable('tag_properties')],
                "properties.tag_id = {$this->getMainTable()}.tag_id"
                    . " AND properties.store_id = {$object->getStoreId()}",
                'base_popularity'
            );
        }
        return $select;
    }

    /**
     * Perform actions after object load
     *
     * @param AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('tag_summary'), ['store_id'])
            ->where('tag_id = :tag_id');
        $storeIds = $connection->fetchCol($select, ['tag_id' => $object->getId()]);
        $object->setVisibleInStoreIds($storeIds);
        return $this;
    }
}
