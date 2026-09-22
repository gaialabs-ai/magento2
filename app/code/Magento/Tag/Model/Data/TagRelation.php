<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Magento\Tag\Api\Data\TagRelationInterface;

class TagRelation extends AbstractSimpleObject implements TagRelationInterface
{
    public function getTagRelationId(): ?int
    {
        $value = $this->_get(self::KEY_TAG_RELATION_ID);
        return $value === null ? null : (int) $value;
    }

    public function setTagRelationId(?int $id)
    {
        return $this->setData(self::KEY_TAG_RELATION_ID, $id);
    }

    public function getTagId(): int
    {
        return (int) $this->_get(self::KEY_TAG_ID);
    }

    public function setTagId(int $tagId)
    {
        return $this->setData(self::KEY_TAG_ID, $tagId);
    }

    public function getCustomerId(): ?int
    {
        $value = $this->_get(self::KEY_CUSTOMER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setCustomerId(?int $customerId)
    {
        return $this->setData(self::KEY_CUSTOMER_ID, $customerId);
    }

    public function getProductId(): int
    {
        return (int) $this->_get(self::KEY_PRODUCT_ID);
    }

    public function setProductId(int $productId)
    {
        return $this->setData(self::KEY_PRODUCT_ID, $productId);
    }

    public function getStoreId(): int
    {
        return (int) $this->_get(self::KEY_STORE_ID);
    }

    public function setStoreId(int $storeId)
    {
        return $this->setData(self::KEY_STORE_ID, $storeId);
    }

    public function getActive(): int
    {
        return (int) $this->_get(self::KEY_ACTIVE);
    }

    public function setActive(int $active)
    {
        return $this->setData(self::KEY_ACTIVE, $active);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->_get(self::KEY_CREATED_AT);
        return $value === null ? null : (string) $value;
    }

    public function setCreatedAt(?string $createdAt)
    {
        return $this->setData(self::KEY_CREATED_AT, $createdAt);
    }
}
