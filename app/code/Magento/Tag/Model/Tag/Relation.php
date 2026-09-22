<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Tag;

use Magento\Framework\Model\AbstractModel;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag as TagModel;

/**
 * Tag Relation model.
 */
class Relation extends AbstractModel
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_NOT_ACTIVE = 0;

    public const ENTITY = 'tag_relation';

    /**
     * @var string
     */
    protected $_eventPrefix = 'tag_relation';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(RelationResource::class);
    }

    /**
     * @return int|null
     */
    public function getTagId(): ?int
    {
        $value = $this->_getData('tag_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param int $tagId
     * @return $this
     */
    public function setTagId(int $tagId): self
    {
        $this->setData('tag_id', $tagId);
        return $this;
    }

    /**
     * @return int|null
     */
    public function getStoreId(): ?int
    {
        $value = $this->_getData('store_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        $this->setData('store_id', $storeId);
        return $this;
    }

    /**
     * @return bool
     */
    public function hasStoreId(): bool
    {
        return $this->hasData('store_id');
    }

    /**
     * @return int|null
     */
    public function getProductId()
    {
        return $this->_getData('product_id');
    }

    /**
     * @param int|array $productId
     * @return $this
     */
    public function setProductId($productId): self
    {
        $this->setData('product_id', $productId);
        return $this;
    }

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        $value = $this->_getData('customer_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param int|null $customerId
     * @return $this
     */
    public function setCustomerId(?int $customerId): self
    {
        $this->setData('customer_id', $customerId);
        return $this;
    }

    /**
     * @return int
     */
    public function getActive(): int
    {
        return (int) $this->_getData('active');
    }

    /**
     * @param int $active
     * @return $this
     */
    public function setActive(int $active): self
    {
        $this->setData('active', $active);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        $value = $this->_getData('created_at');
        return $value === null ? null : (string) $value;
    }

    /**
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self
    {
        $this->setData('created_at', $createdAt);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatusFilter(): ?string
    {
        $value = $this->_getData('status_filter');
        return $value === null ? null : (string) $value;
    }

    /**
     * @param string $statusFilter
     * @return $this
     */
    public function setStatusFilter(string $statusFilter): self
    {
        $this->setData('status_filter', $statusFilter);
        return $this;
    }

    /**
     * @return array
     */
    public function getAddedProductIds(): array
    {
        $value = $this->_getData('added_product_ids');
        return is_array($value) ? $value : [];
    }

    /**
     * @param array $productIds
     * @return $this
     */
    public function setAddedProductIds(array $productIds): self
    {
        $this->setData('added_product_ids', $productIds);
        return $this;
    }

    /**
     * Load relation by product (optional), tag, customer and store.
     *
     * @param int|null $productId
     * @param int $tagId
     * @param int|null $customerId
     * @param int|null $storeId
     * @return $this
     */
    public function loadByTagCustomer(?int $productId, int $tagId, ?int $customerId, ?int $storeId = null): self
    {
        $this->setProductId($productId);
        $this->setTagId($tagId);
        $this->setCustomerId($customerId);
        if ($storeId !== null) {
            $this->setStoreId($storeId);
        }

        $this->getResource()->loadByTagCustomer($this);
        return $this;
    }

    /**
     * Retrieve relation product ids (cached on the entity).
     *
     * @return array
     */
    public function getProductIds(): array
    {
        $ids = $this->_getData('product_ids');
        if ($ids === null) {
            $ids = $this->getResource()->getProductIds($this);
            $this->setData('product_ids', $ids);
        }
        return is_array($ids) ? $ids : [];
    }

    /**
     * @param array $productIds
     * @return $this
     */
    public function setProductIds(array $productIds): self
    {
        $this->setData('product_ids', $productIds);
        return $this;
    }

    /**
     * @return array
     */
    public function getRelatedTagIds(): array
    {
        if ($this->_getData('related_tag_ids') === null) {
            $this->setData('related_tag_ids', $this->getResource()->getRelatedTagIds($this));
        }
        $value = $this->_getData('related_tag_ids');
        return is_array($value) ? $value : [];
    }

    /**
     * @param array $relatedTagIds
     * @return $this
     */
    public function setRelatedTagIds(array $relatedTagIds): self
    {
        $this->setData('related_tag_ids', $relatedTagIds);
        return $this;
    }

    /**
     * Deactivate tag relations (using current settings).
     *
     * @return $this
     */
    public function deactivate(): self
    {
        $this->getResource()->deactivate((int) $this->getTagId(), (int) $this->getCustomerId());
        return $this;
    }

    /**
     * Add TAG-to-PRODUCT relations.
     *
     * @param TagModel $model
     * @param array $productIds
     * @return $this
     */
    public function addRelations(TagModel $model, array $productIds = []): self
    {
        $this->setAddedProductIds($productIds);
        $this->setTagId((int) $model->getTagId());
        $this->setCustomerId(null);
        $this->setStoreId((int) $model->getStore());
        $this->getResource()->addRelations($this);
        return $this;
    }
}
