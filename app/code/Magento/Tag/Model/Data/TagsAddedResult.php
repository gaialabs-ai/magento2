<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Magento\Tag\Api\Data\TagsAddedResultInterface;

class TagsAddedResult extends AbstractSimpleObject implements TagsAddedResultInterface
{
    public const KEY_TAGS = 'tags';

    public function getTags(): array
    {
        $value = $this->_get(self::KEY_TAGS);
        return is_array($value) ? $value : [];
    }

    public function setTags(array $tags)
    {
        return $this->setData(self::KEY_TAGS, $tags);
    }
}
