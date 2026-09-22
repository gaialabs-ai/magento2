<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Catalog\Api\ProductRepositoryInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tag\Api\Data\TagInterface;
use Magento\Tag\Api\Data\TagInterfaceFactory;
use Magento\Tag\Api\Data\TagsAddedResultInterface;
use Magento\Tag\Api\Data\TagsAddedResultInterfaceFactory;
use Magento\Tag\Api\TagManagementInterface;
use Magento\Tag\Helper\Data as TagHelper;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Model\Tag\RelationFactory;

/**
 * Product-scoped tag management (replaces Mage_Tag_Model_Api items/add/remove).
 */
class TagManagement implements TagManagementInterface
{
    /**
     * @var TagCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var TagFactory
     */
    private $tagFactory;

    /**
     * @var RelationFactory
     */
    private $relationFactory;

    /**
     * @var TagInterfaceFactory
     */
    private $tagDataFactory;

    /**
     * @var TagsAddedResultInterfaceFactory
     */
    private $tagsAddedResultFactory;

    /**
     * @var TagHelper
     */
    private $tagHelper;

    /**
     * @var ProductRepositoryInterfaceFactory
     */
    private $productRepositoryFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var DataObjectHelper
     */
    private $dataObjectHelper;

    /**
     * @param TagCollectionFactory $collectionFactory
     * @param TagFactory $tagFactory
     * @param RelationFactory $relationFactory
     * @param TagInterfaceFactory $tagDataFactory
     * @param TagsAddedResultInterfaceFactory $tagsAddedResultFactory
     * @param TagHelper $tagHelper
     * @param ProductRepositoryInterfaceFactory $productRepositoryFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param DataObjectHelper $dataObjectHelper
     */
    public function __construct(
        TagCollectionFactory $collectionFactory,
        TagFactory $tagFactory,
        RelationFactory $relationFactory,
        TagInterfaceFactory $tagDataFactory,
        TagsAddedResultInterfaceFactory $tagsAddedResultFactory,
        TagHelper $tagHelper,
        ProductRepositoryInterfaceFactory $productRepositoryFactory,
        CustomerRepositoryInterface $customerRepository,
        DataObjectHelper $dataObjectHelper
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->tagFactory = $tagFactory;
        $this->relationFactory = $relationFactory;
        $this->tagDataFactory = $tagDataFactory;
        $this->tagsAddedResultFactory = $tagsAddedResultFactory;
        $this->tagHelper = $tagHelper;
        $this->productRepositoryFactory = $productRepositoryFactory;
        $this->customerRepository = $customerRepository;
        $this->dataObjectHelper = $dataObjectHelper;
    }

    /**
     * @inheritDoc
     */
    public function getTagsForProduct(int $productId, ?int $storeId = null): array
    {
        $this->productRepositoryFactory->create()->getById($productId);

        $collection = $this->collectionFactory->create()
            ->joinRel()
            ->addProductFilter($productId);
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }

        $result = [];
        foreach ($collection as $tagModel) {
            $result[] = $this->toDataObject($tagModel);
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function addTagsToProduct(
        int $productId,
        int $customerId,
        int $storeId,
        string $tagString
    ): TagsAddedResultInterface {
        $this->productRepositoryFactory->create()->getById($productId);
        $this->customerRepository->getById($customerId);

        $tagNames = $this->tagHelper->cleanTags($this->tagHelper->extractTags($tagString));
        $result = [];

        try {
            foreach ($tagNames as $tagName) {
                $tagName = (string) $tagName;
                $tagModel = $this->tagFactory->create();
                $tagModel->loadByName($tagName);
                if (!$tagModel->getId()) {
                    $tagModel->setName($tagName)
                        ->setFirstCustomerId($customerId)
                        ->setFirstStoreId($storeId)
                        ->setStatus(Tag::STATUS_PENDING)
                        ->save();
                }
                $tagModel->saveRelation($productId, $customerId, $storeId);
                $result[$tagName] = (int) $tagModel->getId();
            }
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__('Could not add tags: %1', $exception->getMessage()), $exception);
        }

        /** @var TagsAddedResultInterface $dto */
        $dto = $this->tagsAddedResultFactory->create();
        $dto->setTags($result);
        return $dto;
    }

    /**
     * @inheritDoc
     */
    public function removeTagFromProduct(int $tagId, int $productId, int $customerId, int $storeId): bool
    {
        $tagModel = $this->tagFactory->create();
        $tagModel->getResource()->load($tagModel, $tagId);
        if (!$tagModel->getId()) {
            throw NoSuchEntityException::singleField('tag_id', $tagId);
        }

        $relation = $this->relationFactory->create();
        $relation->loadByTagCustomer($productId, $tagId, $customerId, $storeId);
        if (!$relation->getId()) {
            return false;
        }
        $relation->getResource()->delete($relation);
        return true;
    }

    /**
     * @param Tag $tagModel
     * @return TagInterface
     */
    private function toDataObject(Tag $tagModel): TagInterface
    {
        /** @var TagInterface $dto */
        $dto = $this->tagDataFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $dto,
            [
                TagInterface::KEY_TAG_ID => $tagModel->getId() === null ? null : (int) $tagModel->getId(),
                TagInterface::KEY_NAME => $tagModel->getName(),
                TagInterface::KEY_STATUS => $tagModel->getStatus(),
                TagInterface::KEY_FIRST_CUSTOMER_ID => $tagModel->getFirstCustomerId(),
                TagInterface::KEY_FIRST_STORE_ID => $tagModel->getFirstStoreId(),
                TagInterface::KEY_BASE_POPULARITY => $tagModel->getData('base_popularity') === null
                    ? null
                    : (int) $tagModel->getData('base_popularity'),
            ],
            TagInterface::class
        );
        return $dto;
    }
}
