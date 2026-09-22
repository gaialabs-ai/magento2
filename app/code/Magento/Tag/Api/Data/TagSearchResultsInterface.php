<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

/**
 * Search results for {@see \Magento\Tag\Api\TagRepositoryInterface::getList()}.
 *
 * @api
 */
interface TagSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Magento\Tag\Api\Data\TagInterface[]
     */
    public function getItems();

    /**
     * @param \Magento\Tag\Api\Data\TagInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
