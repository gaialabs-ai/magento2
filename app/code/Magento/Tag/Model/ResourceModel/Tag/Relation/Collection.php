<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\ResourceModel\Tag\Relation;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag\Relation;

/**
 * Tag relation collection.
 *
 * Legacy Mage_Tag has no dedicated relation collection; this thin collection exposes tag_relation rows
 * (filters mirror the relation resource model queries) for later milestones and integration tests.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(Relation::class, RelationResource::class);
    }

    /**
     * Filter by tag id
     *
     * @param int|int[] $tagId
     * @return $this
     */
    public function addTagFilter($tagId)
    {
        $this->addFieldToFilter('main_table.tag_id', ['in' => (array)$tagId]);
        return $this;
    }

    /**
     * Filter by product id
     *
     * @param int|int[] $productId
     * @return $this
     */
    public function addProductFilter($productId)
    {
        $this->addFieldToFilter('main_table.product_id', ['in' => (array)$productId]);
        return $this;
    }

    /**
     * Filter by customer id
     *
     * @param int|int[] $customerId
     * @return $this
     */
    public function addCustomerFilter($customerId)
    {
        $this->addFieldToFilter('main_table.customer_id', ['in' => (array)$customerId]);
        return $this;
    }

    /**
     * Filter by store id(s)
     *
     * @param int|int[] $storeId
     * @return $this
     */
    public function addStoreFilter($storeId)
    {
        $this->addFieldToFilter('main_table.store_id', ['in' => (array)$storeId]);
        return $this;
    }

    /**
     * Filter by active flag
     *
     * @param bool $active
     * @return $this
     */
    public function addActiveFilter($active = true)
    {
        $this->addFieldToFilter(
            'main_table.active',
            $active ? Relation::STATUS_ACTIVE : Relation::STATUS_NOT_ACTIVE
        );
        return $this;
    }
}
