<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Data\Collection\AbstractDb as ResourceCollection;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag\Relation as TagRelation;
use Magento\Tag\Model\Tag\RelationFactory;

/**
 * Tag model.
 */
class Tag extends AbstractModel
{
    public const STATUS_DISABLED = -1;
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;

    public const ADD_STATUS_SUCCESS = 'success';
    public const ADD_STATUS_NEW = 'new';
    public const ADD_STATUS_EXIST = 'exist';
    public const ADD_STATUS_REJECTED = 'rejected';

    public const ENTITY = 'tag';

    /**
     * @var string
     */
    protected $_eventPrefix = 'tag';

    /**
     * @var bool
     */
    protected $_addBasePopularity = false;

    /**
     * @var RelationFactory
     */
    private $relationFactory;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param RelationFactory $relationFactory
     * @param DateTime $dateTime
     * @param AbstractResource|null $resource
     * @param ResourceCollection|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        RelationFactory $relationFactory,
        DateTime $dateTime,
        ?AbstractResource $resource = null,
        ?ResourceCollection $resourceCollection = null,
        array $data = []
    ) {
        $this->relationFactory = $relationFactory;
        $this->dateTime = $dateTime;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(TagResource::class);
    }

    /**
     * @param bool $flag
     * @return $this
     */
    public function setAddBasePopularity(bool $flag = true): self
    {
        $this->_addBasePopularity = $flag;
        return $this;
    }

    /**
     * @return bool
     */
    public function getAddBasePopularity(): bool
    {
        return $this->_addBasePopularity;
    }

    /**
     * @return int
     */
    public function getPopularity(): int
    {
        return (int) $this->_getData('popularity');
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        $name = $this->_getData('name');
        return $name === null ? null : (string) $name;
    }

    /**
     * @return int|null
     */
    public function getTagId(): ?int
    {
        $tagId = $this->_getData('tag_id');
        return $tagId === null ? null : (int) $tagId;
    }

    /**
     * @return int|null
     */
    public function getRatio(): ?int
    {
        $ratio = $this->_getData('ratio');
        return $ratio === null ? null : (int) $ratio;
    }

    /**
     * @param int $ratio
     * @return $this
     */
    public function setRatio(int $ratio): self
    {
        $this->setData('ratio', $ratio);
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function loadByName(string $name): self
    {
        $this->getResource()->loadByName($this, $name);
        return $this;
    }

    /**
     * @return int
     */
    public function getApprovedStatus(): int
    {
        return self::STATUS_APPROVED;
    }

    /**
     * @return int
     */
    public function getPendingStatus(): int
    {
        return self::STATUS_PENDING;
    }

    /**
     * @return int
     */
    public function getDisabledStatus(): int
    {
        return self::STATUS_DISABLED;
    }

    /**
     * Save tag relation with product, customer and store.
     *
     * @param int $productId
     * @param int $customerId
     * @param int $storeId
     * @return string relation add status (one of self::ADD_STATUS_*)
     */
    public function saveRelation(int $productId, int $customerId, int $storeId): string
    {
        /** @var TagRelation $relationModel */
        $relationModel = $this->relationFactory->create();
        $relationModel->setTagId((int) $this->getId())
            ->setStoreId($storeId)
            ->setProductId($productId)
            ->setCustomerId($customerId)
            ->setActive(TagRelation::STATUS_ACTIVE)
            ->setCreatedAt($relationModel->getResource()->formatDate($this->dateTime->gmtTimestamp()));

        $result = '';
        $relationModelSaveNeed = false;
        switch ((int) $this->getStatus()) {
            case $this->getApprovedStatus():
                if ($this->checkLinkBetweenTagProduct($relationModel)) {
                    $relation = $this->getLinkBetweenTagCustomerProduct($relationModel);
                    if ($relation->getId()) {
                        if (!$relation->getActive()) {
                            $relationModel->setId($relation->getId());
                            $relationModelSaveNeed = true;
                        }
                    } else {
                        $relationModelSaveNeed = true;
                    }
                    $result = self::ADD_STATUS_EXIST;
                } else {
                    $relationModelSaveNeed = true;
                    $result = self::ADD_STATUS_SUCCESS;
                }
                break;
            case $this->getPendingStatus():
                $relation = $this->getLinkBetweenTagCustomerProduct($relationModel);
                if ($relation->getId()) {
                    if (!$relation->getActive()) {
                        $relationModel->setId($relation->getId());
                        $relationModelSaveNeed = true;
                    }
                } else {
                    $relationModelSaveNeed = true;
                }
                $result = self::ADD_STATUS_NEW;
                break;
            case $this->getDisabledStatus():
                if ($this->checkLinkBetweenTagCustomerProduct($relationModel)) {
                    $result = self::ADD_STATUS_REJECTED;
                } else {
                    $this->setStatus($this->getPendingStatus())->save();
                    $relationModelSaveNeed = true;
                    $result = self::ADD_STATUS_NEW;
                }
                break;
        }

        if ($relationModelSaveNeed) {
            $relationModel->save();
        }

        return $result;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return (int) $this->_getData('status');
    }

    /**
     * @param int $status
     * @return $this
     */
    public function setStatus(int $status): self
    {
        $this->setData('status', $status);
        return $this;
    }

    /**
     * @param int|null $customerId
     * @return $this
     */
    public function setFirstCustomerId(?int $customerId): self
    {
        $this->setData('first_customer_id', $customerId);
        return $this;
    }

    /**
     * @param int|null $storeId
     * @return $this
     */
    public function setFirstStoreId(?int $storeId): self
    {
        $this->setData('first_store_id', $storeId);
        return $this;
    }

    /**
     * @return int|null
     */
    public function getFirstCustomerId(): ?int
    {
        $value = $this->_getData('first_customer_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @return int|null
     */
    public function getFirstStoreId(): ?int
    {
        $value = $this->_getData('first_store_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->setData('name', $name);
        return $this;
    }

    /**
     * @return int|null
     */
    public function getStoreId(): ?int
    {
        $value = $this->_getData('store_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        $this->setData('store_id', $storeId);
        return $this;
    }

    /**
     * Store used for _afterSave() properties insertion (mirrors legacy getStore()).
     *
     * @return int|null
     */
    public function getStore(): ?int
    {
        $value = $this->_getData('store');
        return $value === null ? null : (int) $value;
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStore(int $storeId): self
    {
        $this->setData('store', $storeId);
        return $this;
    }

    /**
     * @return array
     */
    public function getVisibleInStoreIds(): array
    {
        $data = $this->_getData('visible_in_store_ids');
        return is_array($data) ? $data : [];
    }

    /**
     * @param array $storeIds
     * @return $this
     */
    public function setVisibleInStoreIds(array $storeIds): self
    {
        $this->setData('visible_in_store_ids', $storeIds);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatusFilter()
    {
        return $this->_getData('status_filter');
    }

    /**
     * @param string $status
     * @return $this
     */
    public function setStatusFilter(string $status): self
    {
        $this->setData('status_filter', $status);
        return $this;
    }

    /**
     * Check whether product is already marked in store with tag.
     *
     * @param TagRelation $relationModel
     * @return bool
     */
    protected function checkLinkBetweenTagProduct(TagRelation $relationModel): bool
    {
        $customerId = $relationModel->getCustomerId();
        $relationModel->setCustomerId(null);
        $result = in_array($relationModel->getProductId(), $relationModel->getProductIds());
        $relationModel->setCustomerId($customerId);
        return $result;
    }

    /**
     * Check whether product is already marked in store with tag by customer.
     *
     * @param TagRelation $relationModel
     * @return bool
     */
    protected function checkLinkBetweenTagCustomerProduct(TagRelation $relationModel): bool
    {
        return count($this->getLinkBetweenTagCustomerProduct($relationModel)->getProductIds()) > 0;
    }

    /**
     * Get relation model for product marked in store with tag by customer.
     *
     * @param TagRelation $relationModel
     * @return TagRelation
     */
    protected function getLinkBetweenTagCustomerProduct(TagRelation $relationModel): TagRelation
    {
        return $this->relationFactory->create()->loadByTagCustomer(
            (int) $relationModel->getProductId(),
            (int) $this->getId(),
            $relationModel->getCustomerId() === null ? null : (int) $relationModel->getCustomerId(),
            $relationModel->getStoreId() === null ? null : (int) $relationModel->getStoreId()
        );
    }

    /**
     * @inheritDoc
     */
    protected function _afterSave()
    {
        if ($this->hasData('tag_assigned_products')) {
            /** @var TagRelation $relationModel */
            $relationModel = $this->relationFactory->create();
            $relationModel->addRelations($this, (array) $this->getDataByKey('tag_assigned_products'));
        }

        return parent::_afterSave();
    }
}
