<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Tag\Model\ResourceModel\Indexer\Summary as SummaryResource;

/**
 * Tag summary indexer (port of Mage_Tag_Model_Indexer_Summary).
 *
 * Mview subscription (etc/mview.xml) reports changed tag ids from the "tag" and "tag_relation" tables.
 */
class Summary implements IndexerActionInterface, MviewActionInterface
{
    /**
     * Product attributes which have an effect on tag summary
     */
    private const PRODUCT_ATTRIBUTES_DEPEND_ON = ['visibility', 'status', 'website_ids'];

    /**
     * @param SummaryResource $resource
     */
    public function __construct(private readonly SummaryResource $resource)
    {
    }

    /**
     * Retrieve attribute list that has an effect on tags
     *
     * @return string[]
     */
    public function getProductAttributesDependOn(): array
    {
        return self::PRODUCT_ATTRIBUTES_DEPEND_ON;
    }

    /**
     * Reindex all tags
     *
     * @return void
     */
    public function executeFull()
    {
        $this->resource->reindexAll();
    }

    /**
     * Reindex tags by ids
     *
     * @param int[] $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        $this->execute($ids);
    }

    /**
     * Reindex single tag
     *
     * @param int $id
     * @return void
     */
    public function executeRow($id)
    {
        $this->execute([$id]);
    }

    /**
     * Mview entry point: reindex tags by ids. Empty id list is a no-op (an empty list must not trigger full rebuild)
     *
     * @param int[] $ids
     * @return void
     */
    public function execute($ids)
    {
        $ids = array_values(array_unique(array_map('intval', (array)$ids)));
        if ($ids === []) {
            return;
        }
        $this->resource->aggregate($ids);
    }

    /**
     * Reindex tags related to changed products (product status/visibility/website changes or deletion)
     *
     * @param int[] $productIds
     * @return void
     */
    public function executeByProductIds(array $productIds): void
    {
        $tagIds = $this->resource->getRelatedTagIds($productIds);
        if ($tagIds !== []) {
            $this->execute($tagIds);
        }
    }
}
