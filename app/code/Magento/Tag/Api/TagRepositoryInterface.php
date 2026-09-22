<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Tag\Api\Data\TagInterface;
use Magento\Tag\Api\Data\TagSearchResultsInterface;

/**
 * CRUD contract for {@see \Magento\Tag\Model\Tag}.
 *
 * @api
 */
interface TagRepositoryInterface
{
    /**
     * Get a tag by name (legacy Mage_Tag_Model_Tag::loadByName).
     *
     * @param string $name
     * @return TagInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(string $name): TagInterface;

    /**
     * @param int $tagId
     * @return TagInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $tagId): TagInterface;

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return TagSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): TagSearchResultsInterface;

    /**
     * @param TagInterface $tag
     * @return TagInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(TagInterface $tag): TagInterface;

    /**
     * @param TagInterface $tag
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(TagInterface $tag): bool;

    /**
     * @param int $tagId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById(int $tagId): bool;
}
