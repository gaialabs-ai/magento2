<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
namespace Magento\Tag\Model\Tag;

use Magento\Framework\Model\AbstractModel;
use Magento\Tag\Model\Tag;

/**
 * Tag relation model
 *
 * @method int getTagId()
 * @method \Magento\Tag\Model\Tag\Relation setTagId(int $value)
 * @method int getCustomerId()
 * @method \Magento\Tag\Model\Tag\Relation setCustomerId(int|null $value)
 * @method int getProductId()
 * @method \Magento\Tag\Model\Tag\Relation setProductId(int $value)
 * @method int getStoreId()
 * @method \Magento\Tag\Model\Tag\Relation setStoreId(int $value)
 * @method int getActive()
 * @method \Magento\Tag\Model\Tag\Relation setActive(int $value)
 * @method \Magento\Tag\Model\Tag\Relation setCreatedAt(string $value)
 * @method int getStatusFilter()
 * @method \Magento\Tag\Model\Tag\Relation setStatusFilter(int|null $value)
 * @method array getAddedProductIds()
 * @method \Magento\Tag\Model\Tag\Relation setAddedProductIds(array $value)
 * @method \Magento\Tag\Model\Tag\Relation setProductIds(array $value)
 * @method \Magento\Tag\Model\Tag\Relation setRelatedTagIds(array $value)
 */
class Relation extends AbstractModel
{
    /**
     * Active relation status
     */
    public const STATUS_ACTIVE = 1;

    /**
     * Inactive relation status
     */
    public const STATUS_NOT_ACTIVE = 0;

    /**
     * Entity code
     */
    public const ENTITY = 'tag_relation';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\Tag\Model\ResourceModel\Tag\Relation::class);
    }

    /**
     * Load relation by tag and customer (and optionally product/store)
     *
     * @param int $productId
     * @param int $tagId
     * @param int $customerId
     * @param int|null $storeId
     * @return $this
     */
    public function loadByTagCustomer($productId, $tagId, $customerId, $storeId = null)
    {
        $this->setProductId($productId);
        $this->setTagId($tagId);
        $this->setCustomerId($customerId);
        if ($storeId !== null) {
            $this->setStoreId($storeId);
        }
        $this->_getResource()->loadByTagCustomer($this);
        return $this;
    }

    /**
     * Get product ids related to the tag
     *
     * @return array
     */
    public function getProductIds()
    {
        $ids = $this->getDataByKey('product_ids');
        if ($ids === null) {
            $ids = $this->_getResource()->getProductIds($this);
            $this->setProductIds($ids);
        }
        return $ids;
    }

    /**
     * Get tag ids related to the product(s)
     *
     * @return array
     */
    public function getRelatedTagIds()
    {
        if ($this->getDataByKey('related_tag_ids') === null) {
            $this->setRelatedTagIds($this->_getResource()->getRelatedTagIds($this));
        }
        return $this->getDataByKey('related_tag_ids');
    }

    /**
     * Deactivate relation for the given tag and customer
     *
     * @return $this
     */
    public function deactivate()
    {
        $this->_getResource()->deactivate($this->getTagId(), $this->getCustomerId());
        return $this;
    }

    /**
     * Add/remove relations between a tag and a set of products
     *
     * @param Tag $model
     * @param array $productIds
     * @return $this
     */
    public function addRelations(Tag $model, $productIds = [])
    {
        $this->setAddedProductIds($productIds);
        $this->setTagId($model->getTagId());
        $this->setCustomerId(null);
        $this->setStoreId($model->getStore());
        $this->_getResource()->addRelations($this);
        return $this;
    }
}
