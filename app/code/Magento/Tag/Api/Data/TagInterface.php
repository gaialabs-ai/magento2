<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api\Data;

/**
 * Tag DTO.
 *
 * @api
 */
interface TagInterface
{
    public const KEY_TAG_ID = 'tag_id';
    public const KEY_NAME = 'name';
    public const KEY_STATUS = 'status';
    public const KEY_FIRST_CUSTOMER_ID = 'first_customer_id';
    public const KEY_FIRST_STORE_ID = 'first_store_id';
    public const KEY_BASE_POPULARITY = 'base_popularity';

    /**
     * @return int|null
     */
    public function getTagId(): ?int;

    /**
     * @param int|null $tagId
     * @return $this
     */
    public function setTagId(?int $tagId);

    /**
     * @return string|null
     */
    public function getName(): ?string;

    /**
     * @param string|null $name
     * @return $this
     */
    public function setName(?string $name);

    /**
     * @return int
     */
    public function getStatus(): int;

    /**
     * @param int $status
     * @return $this
     */
    public function setStatus(int $status);

    /**
     * @return int|null
     */
    public function getFirstCustomerId(): ?int;

    /**
     * @param int|null $customerId
     * @return $this
     */
    public function setFirstCustomerId(?int $customerId);

    /**
     * @return int|null
     */
    public function getFirstStoreId(): ?int;

    /**
     * @param int|null $storeId
     * @return $this
     */
    public function setFirstStoreId(?int $storeId);

    /**
     * @return int|null
     */
    public function getBasePopularity(): ?int;

    /**
     * @param int|null $basePopularity
     * @return $this
     */
    public function setBasePopularity(?int $basePopularity);
}
