<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Api\Data;

/**
 * Result of {@see \Magento\Tag\Api\TagManagementInterface::addTagsToProduct()}.
 *
 * Mirrors the legacy Mage_Tag_Model_Api::add() response shape (name => tag id).
 *
 * @api
 */
interface TagsAddedResultInterface
{
    public const KEY_TAG_NAME = 'tag_name';
    public const KEY_TAG_ID = 'tag_id';

    /**
     * Map of tag name -> tag id for every tag that was created or reused.
     *
     * @return array<string, int>
     */
    public function getTags(): array;

    /**
     * @param array<string, int> $tags
     * @return $this
     */
    public function setTags(array $tags);
}
