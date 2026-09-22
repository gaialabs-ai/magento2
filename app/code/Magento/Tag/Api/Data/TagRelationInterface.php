<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api\Data;

/**
 * Tag-to-product relation DTO.
 *
 * @api
 */
interface TagRelationInterface
{
    public const KEY_TAG_RELATION_ID = 'tag_relation_id';
    public const KEY_TAG_ID = 'tag_id';
    public const KEY_CUSTOMER_ID = 'customer_id';
    public const KEY_PRODUCT_ID = 'product_id';
    public const KEY_STORE_ID = 'store_id';
    public const KEY_ACTIVE = 'active';
    public const KEY_CREATED_AT = 'created_at';

    /**
     * @return int|null
     */
    public function getTagRelationId(): ?int;

    /**
     * @param int|null $id
     * @return $this
     */
    public function setTagRelationId(?int $id);

    /**
     * @return int
     */
    public function getTagId(): int;

    /**
     * @param int $tagId
     * @return $this
     */
    public function setTagId(int $tagId);

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int;

    /**
     * @param int|null $customerId
     * @return $this
     */
    public function setCustomerId(?int $customerId);

    /**
     * @return int
     */
    public function getProductId(): int;

    /**
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId);

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId);

    /**
     * @return int
     */
    public function getActive(): int;

    /**
     * @param int $active
     * @return $this
     */
    public function setActive(int $active);

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @param string|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?string $createdAt);
}
