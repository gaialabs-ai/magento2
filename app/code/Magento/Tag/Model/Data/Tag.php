<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Magento\Tag\Api\Data\TagInterface;

class Tag extends AbstractSimpleObject implements TagInterface
{
    public function getTagId(): ?int
    {
        $value = $this->_get(self::KEY_TAG_ID);
        return $value === null ? null : (int) $value;
    }

    public function setTagId(?int $tagId)
    {
        return $this->setData(self::KEY_TAG_ID, $tagId);
    }

    public function getName(): ?string
    {
        $value = $this->_get(self::KEY_NAME);
        return $value === null ? null : (string) $value;
    }

    public function setName(?string $name)
    {
        return $this->setData(self::KEY_NAME, $name);
    }

    public function getStatus(): int
    {
        return (int) $this->_get(self::KEY_STATUS);
    }

    public function setStatus(int $status)
    {
        return $this->setData(self::KEY_STATUS, $status);
    }

    public function getFirstCustomerId(): ?int
    {
        $value = $this->_get(self::KEY_FIRST_CUSTOMER_ID);
        return $value === null ? null : (int) $value;
    }

    public function setFirstCustomerId(?int $customerId)
    {
        return $this->setData(self::KEY_FIRST_CUSTOMER_ID, $customerId);
    }

    public function getFirstStoreId(): ?int
    {
        $value = $this->_get(self::KEY_FIRST_STORE_ID);
        return $value === null ? null : (int) $value;
    }

    public function setFirstStoreId(?int $storeId)
    {
        return $this->setData(self::KEY_FIRST_STORE_ID, $storeId);
    }

    public function getBasePopularity(): ?int
    {
        $value = $this->_get(self::KEY_BASE_POPULARITY);
        return $value === null ? null : (int) $value;
    }

    public function setBasePopularity(?int $basePopularity)
    {
        return $this->setData(self::KEY_BASE_POPULARITY, $basePopularity);
    }
}
