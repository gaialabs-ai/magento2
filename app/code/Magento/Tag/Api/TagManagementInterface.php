<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api;

use Magento\Tag\Api\Data\TagInterface;
use Magento\Tag\Api\Data\TagsAddedResultInterface;

/**
 * Product-scoped tag operations (legacy Mage_Tag_Model_Api items/info/add/update/remove).
 *
 * @api
 */
interface TagManagementInterface
{
    /**
     * List tags attached to the given product, optionally scoped to a store.
     *
     * @param int $productId
     * @param int|null $storeId
     * @return TagInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the product does not exist.
     */
    public function getTagsForProduct(int $productId, ?int $storeId = null): array;

    /**
     * Add customer-authored tags to a product (parses `$tagString` with Helper\Data::extractTags/cleanTags).
     *
     * @param int $productId
     * @param int $customerId
     * @param int $storeId
     * @param string $tagString
     * @return TagsAddedResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function addTagsToProduct(
        int $productId,
        int $customerId,
        int $storeId,
        string $tagString
    ): TagsAddedResultInterface;

    /**
     * Remove a customer's tag from a product.
     *
     * @param int $tagId
     * @param int $productId
     * @param int $customerId
     * @param int $storeId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function removeTagFromProduct(int $tagId, int $productId, int $customerId, int $storeId): bool;
}
