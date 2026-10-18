<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
namespace Magento\Tag\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Tag model
 *
 * @method int getPopularity()
 * @method string getName()
 * @method int getTagId()
 * @method float getRatio()
 * @method int getStatus()
 * @method \Magento\Tag\Model\Tag setStatus(int $value)
 * @method int getStoreId()
 * @method \Magento\Tag\Model\Tag setStoreId(int $value)
 * @method int getStatusFilter()
 * @method \Magento\Tag\Model\Tag setStatusFilter(int $value)
 * @method array getVisibleInStoreIds()
 * @method \Magento\Tag\Model\Tag setVisibleInStoreIds(array $value)
 * @method int getStore()
 * @method int getBasePopularity()
 */
class Tag extends AbstractModel
{
    /**
     * Disabled tag status
     */
    public const STATUS_DISABLED = -1;

    /**
     * Pending tag status
     */
    public const STATUS_PENDING = 0;

    /**
     * Approved tag status
     */
    public const STATUS_APPROVED = 1;

    /**
     * Tag relation add result: relation successfully created
     */
    public const ADD_STATUS_SUCCESS = 'success';

    /**
     * Tag relation add result: relation newly created, pending approval
     */
    public const ADD_STATUS_NEW = 'new';

    /**
     * Tag relation add result: relation already existed
     */
    public const ADD_STATUS_EXIST = 'exist';

    /**
     * Tag relation add result: relation rejected (tag is disabled)
     */
    public const ADD_STATUS_REJECTED = 'rejected';

    /**
     * Entity code
     */
    public const ENTITY = 'tag';

    /**
     * Event prefix for observer
     *
     * @var string
     */
    protected $_eventPrefix = 'tag';

    /**
     * Whether base popularity should be added when loading the tag
     *
     * @var bool
     */
    protected $_addBasePopularity = false;

    /**
     * @var \Magento\Tag\Model\Tag\RelationFactory
     */
    private $relationFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    private $dateTime;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Tag\Model\Tag\RelationFactory $relationFactory
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Tag\Model\Tag\RelationFactory $relationFactory,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->relationFactory = $relationFactory;
        $this->dateTime = $dateTime;
        $this->storeManager = $storeManager;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\Tag\Model\ResourceModel\Tag::class);
    }

    /**
     * Set the flag that defines whether base popularity should be added on load
     *
     * @param bool $flag
     * @return $this
     */
    public function setAddBasePopularity($flag = true)
    {
        $this->_addBasePopularity = $flag;
        return $this;
    }

    /**
     * Get the flag that defines whether base popularity should be added on load
     *
     * @return bool
     */
    public function getAddBasePopularity()
    {
        return $this->_addBasePopularity;
    }

    /**
     * Set tag ratio
     *
     * @param float $ratio
     * @return $this
     */
    public function setRatio($ratio)
    {
        $this->setData('ratio', $ratio);
        return $this;
    }

    /**
     * Load tag by name
     *
     * @param string $name
     * @return $this
     */
    public function loadByName($name)
    {
        $this->_getResource()->loadByName($this, $name);
        return $this;
    }

    /**
     * Get approved tag status
     *
     * @return int
     */
    public function getApprovedStatus()
    {
        return self::STATUS_APPROVED;
    }

    /**
     * Get pending tag status
     *
     * @return int
     */
    public function getPendingStatus()
    {
        return self::STATUS_PENDING;
    }

    /**
     * Get disabled tag status
     *
     * @return int
     */
    public function getDisabledStatus()
    {
        return self::STATUS_DISABLED;
    }

    /**
     * Get product ids related to this tag
     *
     * @return array
     */
    public function getRelatedProductIds()
    {
        return $this->relationFactory->create()
            ->setTagId($this->getTagId())
            ->setStoreId($this->getStoreId())
            ->setStatusFilter($this->getStatusFilter())
            ->setCustomerId(null)
            ->getProductIds();
    }

    /**
     * Check whether tag is available in the given store
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAvailableInStore($storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }
        return in_array($storeId, (array)$this->getVisibleInStoreIds());
    }

    /**
     * Save tag relation with product, customer and store
     *
     * Implements the tag approve/reactivate/reject state machine:
     *  - an approved tag is attached to the product immediately (or reactivated/left
     *    as-is if the relation already exists)
     *  - a pending tag creates (or reactivates) an inactive relation awaiting approval
     *  - a disabled tag rejects the relation unless no relation exists yet, in which
     *    case the tag itself is bumped back to pending and a new relation is created
     *
     * @param int $productId
     * @param int $customerId
     * @param int $storeId
     * @return string relation add status
     */
    public function saveRelation($productId, $customerId, $storeId)
    {
        /** @var \Magento\Tag\Model\Tag\Relation $relationModel */
        $relationModel = $this->relationFactory->create();
        $relationModel->setTagId($this->getId())
            ->setStoreId($storeId)
            ->setProductId($productId)
            ->setCustomerId($customerId)
            ->setActive(\Magento\Tag\Model\Tag\Relation::STATUS_ACTIVE)
            ->setCreatedAt($this->dateTime->gmtDate());

        $result = '';
        $relationModelSaveNeed = false;
        switch ($this->getStatus()) {
            case $this->getApprovedStatus():
                if ($this->_checkLinkBetweenTagProduct($relationModel)) {
                    $relation = $this->_getLinkBetweenTagCustomerProduct($relationModel);
                    if ($relation->getId()) {
                        if (!$relation->getActive()) {
                            // activate relation if it was inactive
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
                $relation = $this->_getLinkBetweenTagCustomerProduct($relationModel);
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
                if ($this->_checkLinkBetweenTagCustomerProduct($relationModel)) {
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
     * Check whether the product is already linked to this tag (regardless of customer)
     *
     * @param \Magento\Tag\Model\Tag\Relation $relationModel
     * @return bool
     */
    protected function _checkLinkBetweenTagProduct($relationModel)
    {
        $customerId = $relationModel->getCustomerId();
        $relationModel->setCustomerId(null);
        $result = in_array($relationModel->getProductId(), $relationModel->getProductIds());
        $relationModel->setCustomerId($customerId);
        return $result;
    }

    /**
     * Check whether the product is already linked to this tag by this customer
     *
     * @param \Magento\Tag\Model\Tag\Relation $relationModel
     * @return bool
     */
    protected function _checkLinkBetweenTagCustomerProduct($relationModel)
    {
        return count($this->_getLinkBetweenTagCustomerProduct($relationModel)->getProductIds()) > 0;
    }

    /**
     * Get relation between tag, customer and product
     *
     * @param \Magento\Tag\Model\Tag\Relation $relationModel
     * @return \Magento\Tag\Model\Tag\Relation
     */
    protected function _getLinkBetweenTagCustomerProduct($relationModel)
    {
        return $this->relationFactory->create()->loadByTagCustomer(
            $relationModel->getProductId(),
            $this->getId(),
            $relationModel->getCustomerId(),
            $relationModel->getStoreId()
        );
    }
}
