<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Tag\Model\Tag\Relation as RelationModel;

/**
 * Tag Relation resource model.
 */
class Relation extends AbstractDb
{
    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param Context $context
     * @param DateTime $dateTime
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        DateTime $dateTime,
        ?string $connectionName = null
    ) {
        $this->dateTime = $dateTime;
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('tag_relation', 'tag_relation_id');
    }

    /**
     * Load by tag and customer, optionally filtered by product and store.
     *
     * @param RelationModel $model
     * @return $this
     */
    public function loadByTagCustomer(RelationModel $model): self
    {
        if ($model->getTagId() && $model->getCustomerId()) {
            $connection = $this->getConnection();
            $bind = [
                'tag_id' => (int) $model->getTagId(),
                'customer_id' => (int) $model->getCustomerId(),
            ];
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
                $bind['product_id'] = (int) $model->getProductId();
            }
            if ($model->hasStoreId()) {
                $select->where($this->getMainTable() . '.store_id = :store_id');
                $bind['store_id'] = (int) $model->getStoreId();
            }
            $data = $connection->fetchRow($select, $bind);
            $model->setData(is_array($data) ? $data : []);
        }

        return $this;
    }

    /**
     * Retrieve product ids for a relation model's filters.
     *
     * @param RelationModel $model
     * @return array
     */
    public function getProductIds(RelationModel $model): array
    {
        $bind = ['tag_id' => (int) $model->getTagId()];
        $select = $this->getConnection()->select()
            ->from($this->getMainTable(), 'product_id')
            ->where($this->getMainTable() . '.tag_id = :tag_id');

        if ($model->getCustomerId() !== null) {
            $select->where($this->getMainTable() . '.customer_id = :customer_id');
            $bind['customer_id'] = (int) $model->getCustomerId();
        }
        if ($model->hasStoreId()) {
            $select->where($this->getMainTable() . '.store_id = :store_id');
            $bind['store_id'] = (int) $model->getStoreId();
        }
        if ($model->getStatusFilter() !== null) {
            $select->join(
                $this->getTable('tag'),
                $this->getTable('tag') . '.tag_id = ' . $this->getMainTable() . '.tag_id'
            )->where($this->getTable('tag') . '.status = :t_status');
            $bind['t_status'] = $model->getStatusFilter();
        }

        return array_map('intval', $this->getConnection()->fetchCol($select, $bind));
    }

    /**
     * Retrieve related tag ids for products specified on the model.
     *
     * @param RelationModel $model
     * @return array
     */
    public function getRelatedTagIds(RelationModel $model): array
    {
        $productIds = is_array($model->getProductId())
            ? $model->getProductId()
            : [$model->getProductId()];
        $select = $this->getConnection()->select()
            ->from($this->getMainTable(), 'tag_id')
            ->where('product_id IN (?)', $productIds)
            ->order('tag_id');
        return array_map('intval', $this->getConnection()->fetchCol($select));
    }

    /**
     * Deactivate tag relations by tag and customer.
     *
     * @param int $tagId
     * @param int $customerId
     * @return $this
     */
    public function deactivate(int $tagId, int $customerId): self
    {
        $condition = [
            'tag_id = ?' => $tagId,
            'customer_id = ?' => $customerId,
        ];
        $this->getConnection()->update(
            $this->getMainTable(),
            ['active' => RelationModel::STATUS_NOT_ACTIVE],
            $condition
        );
        return $this;
    }

    /**
     * Sync (insert/delete) tag-to-product relations from the added-product-ids list.
     *
     * @param RelationModel $model
     * @return $this
     */
    public function addRelations(RelationModel $model): self
    {
        $addedIds = $model->getAddedProductIds();
        $bind = [
            'tag_id' => (int) $model->getTagId(),
            'store_id' => (int) $model->getStoreId(),
        ];
        $write = $this->getConnection();
        $select = $write->select()
            ->from($this->getMainTable(), 'product_id')
            ->where('tag_id = :tag_id')
            ->where('store_id = :store_id');
        $oldRelationIds = $write->fetchCol($select, $bind);

        $insert = array_diff($addedIds, $oldRelationIds);
        $delete = array_diff($oldRelationIds, $addedIds);

        if ($insert !== []) {
            $insertData = [];
            foreach ($insert as $productId) {
                $insertData[] = [
                    'tag_id' => (int) $model->getTagId(),
                    'store_id' => (int) $model->getStoreId(),
                    'product_id' => (int) $productId,
                    'customer_id' => $model->getCustomerId() === null ? null : (int) $model->getCustomerId(),
                    'created_at' => $this->formatDate($this->dateTime->gmtTimestamp()),
                ];
            }
            $write->insertMultiple($this->getMainTable(), $insertData);
        }
        if ($delete !== []) {
            $write->delete($this->getMainTable(), [
                'product_id IN (?)' => $delete,
                'store_id = ?' => (int) $model->getStoreId(),
            ]);
        }

        return $this;
    }

    /**
     * Format a timestamp for storage in the DB.
     *
     * @param int|string $timestamp
     * @return string
     */
    public function formatDate($timestamp): string
    {
        return (new \DateTime('@' . (int) $timestamp))->format('Y-m-d H:i:s');
    }
}
