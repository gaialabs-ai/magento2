<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tag\Api\Data\TagInterface;
use Magento\Tag\Api\Data\TagInterfaceFactory;
use Magento\Tag\Api\Data\TagSearchResultsInterface;
use Magento\Tag\Api\Data\TagSearchResultsInterfaceFactory;
use Magento\Tag\Api\TagRepositoryInterface;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;

/**
 * Default TagRepositoryInterface implementation.
 */
class TagRepository implements TagRepositoryInterface
{
    /**
     * @var TagResource
     */
    private $tagResource;

    /**
     * @var TagFactory
     */
    private $tagFactory;

    /**
     * @var TagCollectionFactory
     */
    private $collectionFactory;

    /**
     * @var TagSearchResultsInterfaceFactory
     */
    private $searchResultsFactory;

    /**
     * @var TagInterfaceFactory
     */
    private $tagDataFactory;

    /**
     * @var DataObjectHelper
     */
    private $dataObjectHelper;

    /**
     * @var CollectionProcessorInterface
     */
    private $collectionProcessor;

    /**
     * @param TagResource $tagResource
     * @param TagFactory $tagFactory
     * @param TagCollectionFactory $collectionFactory
     * @param TagSearchResultsInterfaceFactory $searchResultsFactory
     * @param TagInterfaceFactory $tagDataFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param CollectionProcessorInterface $collectionProcessor
     */
    public function __construct(
        TagResource $tagResource,
        TagFactory $tagFactory,
        TagCollectionFactory $collectionFactory,
        TagSearchResultsInterfaceFactory $searchResultsFactory,
        TagInterfaceFactory $tagDataFactory,
        DataObjectHelper $dataObjectHelper,
        CollectionProcessorInterface $collectionProcessor
    ) {
        $this->tagResource = $tagResource;
        $this->tagFactory = $tagFactory;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->tagDataFactory = $tagDataFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->collectionProcessor = $collectionProcessor;
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): TagInterface
    {
        $tagModel = $this->tagFactory->create()->loadByName($name);
        if (!$tagModel->getId()) {
            throw NoSuchEntityException::singleField('name', $name);
        }
        return $this->toDataObject($tagModel);
    }

    /**
     * @inheritDoc
     */
    public function getById(int $tagId): TagInterface
    {
        $tagModel = $this->tagFactory->create();
        $this->tagResource->load($tagModel, $tagId);
        if (!$tagModel->getId()) {
            throw NoSuchEntityException::singleField('tag_id', $tagId);
        }
        return $this->toDataObject($tagModel);
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): TagSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $items = [];
        foreach ($collection->getItems() as $tagModel) {
            $items[] = $this->toDataObject($tagModel);
        }

        /** @var TagSearchResultsInterface $result */
        $result = $this->searchResultsFactory->create();
        $result->setSearchCriteria($searchCriteria);
        $result->setItems($items);
        $result->setTotalCount($collection->getSize());
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function save(TagInterface $tag): TagInterface
    {
        $tagModel = $this->tagFactory->create();
        if ($tag->getTagId()) {
            $this->tagResource->load($tagModel, $tag->getTagId());
            if (!$tagModel->getId()) {
                throw NoSuchEntityException::singleField('tag_id', $tag->getTagId());
            }
        }

        $tagModel->setName((string) $tag->getName());
        $tagModel->setStatus($tag->getStatus());
        $tagModel->setFirstCustomerId($tag->getFirstCustomerId());
        $tagModel->setFirstStoreId($tag->getFirstStoreId());
        if ($tag->getBasePopularity() !== null) {
            $tagModel->setData('base_popularity', $tag->getBasePopularity());
        }

        try {
            $this->tagResource->save($tagModel);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(__('Could not save the tag: %1', $exception->getMessage()), $exception);
        }
        return $this->toDataObject($tagModel);
    }

    /**
     * @inheritDoc
     */
    public function delete(TagInterface $tag): bool
    {
        if (!$tag->getTagId()) {
            throw new CouldNotDeleteException(__('The tag has no ID.'));
        }
        return $this->deleteById($tag->getTagId());
    }

    /**
     * @inheritDoc
     */
    public function deleteById(int $tagId): bool
    {
        $tagModel = $this->tagFactory->create();
        $this->tagResource->load($tagModel, $tagId);
        if (!$tagModel->getId()) {
            throw NoSuchEntityException::singleField('tag_id', $tagId);
        }
        try {
            $this->tagResource->delete($tagModel);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(__('Could not delete the tag: %1', $exception->getMessage()), $exception);
        }
        return true;
    }

    /**
     * Convert a Tag ORM model to its data-interface DTO.
     *
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
