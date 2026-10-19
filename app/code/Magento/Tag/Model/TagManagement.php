<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Tag\Helper\Data as TagHelper;
use Magento\Tag\Model\Tag\RelationFactory;

/**
 * Orchestrates tag tokenization, dedupe and relation persistence for product tagging.
 *
 * Wraps the "add tags to a product" and "remove a tag from a product" data-layer
 * orchestration that was duplicated across the legacy frontend controllers
 * (Mage_Tag_IndexController::saveAction() and Mage_Tag_CustomerController::removeAction())
 * behind a single reusable, HTTP-agnostic API consumed by controllers and GraphQL resolvers.
 */
class TagManagement
{
    /**
     * @var TagFactory
     */
    private $tagFactory;

    /**
     * @var RelationFactory
     */
    private $tagRelationFactory;

    /**
     * @var TagHelper
     */
    private $tagHelper;

    /**
     * @param TagFactory $tagFactory
     * @param RelationFactory $tagRelationFactory
     * @param TagHelper $tagHelper
     */
    public function __construct(
        TagFactory $tagFactory,
        RelationFactory $tagRelationFactory,
        TagHelper $tagHelper
    ) {
        $this->tagFactory = $tagFactory;
        $this->tagRelationFactory = $tagRelationFactory;
        $this->tagHelper = $tagHelper;
    }

    /**
     * Tokenize and attach the given tag names to a product on behalf of a customer.
     *
     * For each cleaned tag name, loads an existing tag by name, or creates a new
     * pending tag when none exists, then persists the tag-product-customer relation
     * via Tag::saveRelation() and buckets the outcome by the returned ADD_STATUS_*
     * constant - mirroring the legacy $counter array built by
     * Mage_Tag_IndexController::saveAction() so callers can build the same
     * success/duplicate/pending/rejected messaging from it.
     *
     * @param string $tagNamesString raw, whitespace/quote delimited tag names
     * @param int $productId
     * @param int $customerId
     * @param int $storeId
     * @return array<string, string[]> tag names bucketed by Tag::ADD_STATUS_* constant
     */
    public function addTagsToProduct(string $tagNamesString, int $productId, int $customerId, int $storeId): array
    {
        $counter = [
            Tag::ADD_STATUS_NEW => [],
            Tag::ADD_STATUS_EXIST => [],
            Tag::ADD_STATUS_SUCCESS => [],
            Tag::ADD_STATUS_REJECTED => [],
        ];

        $tagNamesArr = $this->tagHelper->cleanTags($this->tagHelper->extractTags($tagNamesString));

        $tagModel = $this->tagFactory->create();
        foreach ($tagNamesArr as $tagName) {
            $tagModel->unsetData()->loadByName($tagName);
            if (!$tagModel->getId()) {
                $tagModel->setName($tagName)
                    ->setFirstCustomerId($customerId)
                    ->setFirstStoreId($storeId)
                    ->setStatus($tagModel->getPendingStatus())
                    ->save();
            }
            $relationStatus = $tagModel->saveRelation($productId, $customerId, $storeId);
            $counter[$relationStatus][] = $tagName;
        }

        return $counter;
    }

    /**
     * Deactivate a customer's relation to the given tag.
     *
     * Ported from the orchestration performed by Mage_Tag_CustomerController::removeAction():
     * loads the tag/customer relation (regardless of product) and deactivates it. The deprecated
     * Mage_Tag_Model_Tag::aggregate() call made by the legacy controller after deactivation is
     * intentionally not ported - it has been dead code since Magento 1.4.0.0 and is superseded by
     * indexer logic out of scope for this milestone.
     *
     * @param int $tagId
     * @param int $customerId
     * @return void
     */
    public function removeTagFromProduct(int $tagId, int $customerId): void
    {
        $relationModel = $this->tagRelationFactory->create();
        $relationModel->loadByTagCustomer(null, $tagId, $customerId);
        $relationModel->deactivate();
    }
}
