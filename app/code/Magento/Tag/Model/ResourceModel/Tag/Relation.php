<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Tag\Model\Tag\Relation as RelationModel;

/**
 * Tag relation resource model
 */
class Relation extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    private $dateTime;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param string $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        $connectionName = null
    ) {
        $this->dateTime = $dateTime;
        parent::__construct($context, $connectionName);
    }

    /**
     * Resource initialization
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('tag_relation', 'tag_relation_id');
    }

    /**
     * Load relation by tag id and customer id (optionally narrowed by product/store)
     *
     * @param \Magento\Tag\Model\Tag\Relation $model
     * @return $this
     */
    public function loadByTagCustomer($model)
    {
        if ($model->getTagId() && $model->getCustomerId()) {
            $connection = $this->getConnection();
            $bind = ['tag_id' => $model->getTagId(), 'customer_id' => $model->getCustomerId()];
            $select = $connection->select()
                ->from($this->getMainTable())
                ->join(
                    $this->getTable('tag'),
                    $this->getTable('tag') . '.tag_id = ' . $this->getMainTable() . '.tag_id'
                )
                ->where($this->getMainTable() . '.tag_id = :tag_id')
                ->where('customer_id = :customer_id');
            if ($model->getProductId()) {
                $select->where($this->getMainTable() . '.product_id = :product_id');
                $bind['product_id'] = $model->getProductId();
            }
            if ($model->hasStoreId()) {
                $select->where($this->getMainTable() . '.store_id = :sore_id');
                $bind['sore_id'] = $model->getStoreId();
            }
            $data = $connection->fetchRow($select, $bind);
            $model->setData(is_array($data) ? $data : []);
        }
        return $this;
    }

    /**
     * Get product ids related to the tag
     *
     * @param \Magento\Tag\Model\Tag\Relation $model
     * @return array
     */
    public function getProductIds($model)
    {
        $bind = ['tag_id' => $model->getTagId()];
        $select = $this->getConnection()->select()
            ->from($this->getMainTable(), 'product_id')
            ->where($this->getMainTable() . '.tag_id=:tag_id');
        if ($model->getCustomerId() !== null) {
            $select->where($this->getMainTable() . '.customer_id= :customer_id');
            $bind['customer_id'] = $model->getCustomerId();
        }
        if ($model->hasStoreId()) {
            $select->where($this->getMainTable() . '.store_id = :store_id');
            $bind['store_id'] = $model->getStoreId();
        }
        if ($model->getStatusFilter() !== null) {
            $select->join(
                $this->getTable('tag'),
                $this->getTable('tag') . '.tag_id = ' . $this->getMainTable() . '.tag_id'
            )->where($this->getTable('tag') . '.status = :t_status');
            $bind['t_status'] = $model->getStatusFilter();
        }
        return $this->getConnection()->fetchCol($select, $bind);
    }

    /**
     * Get tag ids related to the product(s)
     *
     * @param \Magento\Tag\Model\Tag\Relation $model
     * @return array
     */
    public function getRelatedTagIds($model)
    {
        $productIds = is_array($model->getProductId()) ? $model->getProductId() : [$model->getProductId()];
        $select = $this->getConnection()->select()
            ->from($this->getMainTable(), 'tag_id')
            ->where('product_id IN(?)', $productIds)
            ->order('tag_id');
        return $this->getConnection()->fetchCol($select);
    }

    /**
     * Deactivate the relation for the given tag and customer
     *
     * @param int $tagId
     * @param int $customerId
     * @return $this
     */
    public function deactivate($tagId, $customerId)
    {
        $condition = ['tag_id = ?' => $tagId, 'customer_id = ?' => $customerId];
        $data = ['active' => RelationModel::STATUS_NOT_ACTIVE];
        $this->getConnection()->update($this->getMainTable(), $data, $condition);
        return $this;
    }

    /**
     * Synchronize the relations between a tag and a set of products
     *
     * @param \Magento\Tag\Model\Tag\Relation $model
     * @return $this
     */
    public function addRelations($model)
    {
        $addedIds = $model->getAddedProductIds();
        $bind = ['tag_id' => $model->getTagId(), 'store_id' => $model->getStoreId()];
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'product_id')
            ->where('tag_id = :tag_id')
            ->where('store_id = :store_id');
        $oldRelationIds = $connection->fetchCol($select, $bind);
        $insert = array_diff($addedIds, $oldRelationIds);
        $delete = array_diff($oldRelationIds, $addedIds);
        if ($insert !== []) {
            $insertData = [];
            foreach ($insert as $value) {
                $insertData[] = [
                    'tag_id' => $model->getTagId(),
                    'store_id' => $model->getStoreId(),
                    'product_id' => $value,
                    'customer_id' => $model->getCustomerId(),
                    'created_at' => $this->dateTime->gmtDate(),
                ];
            }
            $connection->insertMultiple($this->getMainTable(), $insertData);
        }
        if ($delete !== []) {
            $connection->delete(
                $this->getMainTable(),
                ['product_id IN (?)' => $delete, 'store_id = ?' => $model->getStoreId()]
            );
        }
        return $this;
    }
}
