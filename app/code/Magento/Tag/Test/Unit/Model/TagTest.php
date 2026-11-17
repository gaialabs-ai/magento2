<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Tag\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Tag\Model\ResourceModel\Popular\Collection as PopularCollection;
use Magento\Tag\Model\ResourceModel\Popular\CollectionFactory as PopularCollectionFactory;
use Magento\Tag\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Tag\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\CollectionFactory as TagCollectionFactory;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use Magento\Tag\Model\Tag\RelationFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TagTest extends TestCase
{
    /**
     * @var TagResource|MockObject
     */
    private $resource;

    /**
     * @var RelationFactory|MockObject
     */
    private $relationFactory;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManager;

    /**
     * @var UrlInterface|MockObject
     */
    private $urlBuilder;

    /**
     * @var IndexerRegistry|MockObject
     */
    private $indexerRegistry;

    /**
     * @var Registry|MockObject
     */
    private $registry;

    /**
     * @var PopularCollectionFactory|MockObject
     */
    private $popularFactory;

    /**
     * @var ProductCollectionFactory|MockObject
     */
    private $productFactory;

    /**
     * @var CustomerCollectionFactory|MockObject
     */
    private $customerFactory;

    /**
     * @var Tag
     */
    private $tag;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createActionValidator());
        $this->registry = $this->createMock(Registry::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->relationFactory = $this->createMock(RelationFactory::class);
        $this->popularFactory = $this->createMock(PopularCollectionFactory::class);
        $this->productFactory = $this->createMock(ProductCollectionFactory::class);
        $this->customerFactory = $this->createMock(CustomerCollectionFactory::class);
        $this->resource = $this->createMock(TagResource::class);
        $this->resource->method('getIdFieldName')->willReturn('tag_id');

        $this->tag = new Tag(
            $context,
            $this->registry,
            $this->storeManager,
            $this->urlBuilder,
            $dateTime,
            $this->indexerRegistry,
            $this->relationFactory,
            $this->createMock(TagCollectionFactory::class),
            $this->popularFactory,
            $this->productFactory,
            $this->customerFactory,
            $this->resource
        );
    }

    public function testConstantsAndStatusGetters(): void
    {
        $this->assertSame(-1, Tag::STATUS_DISABLED);
        $this->assertSame(0, Tag::STATUS_PENDING);
        $this->assertSame(1, Tag::STATUS_APPROVED);
        $this->assertSame('success', Tag::ADD_STATUS_SUCCESS);
        $this->assertSame('new', Tag::ADD_STATUS_NEW);
        $this->assertSame('exist', Tag::ADD_STATUS_EXIST);
        $this->assertSame('rejected', Tag::ADD_STATUS_REJECTED);
        $this->assertSame('tag', Tag::ENTITY);
        $this->assertSame(Tag::STATUS_APPROVED, $this->tag->getApprovedStatus());
        $this->assertSame(Tag::STATUS_PENDING, $this->tag->getPendingStatus());
        $this->assertSame(Tag::STATUS_DISABLED, $this->tag->getDisabledStatus());
    }

    public function testDataGetters(): void
    {
        $this->tag->setData(['tag_id' => 4, 'name' => 'summer', 'popularity' => 12]);
        $this->tag->setRatio(0.5);

        $this->assertSame(4, $this->tag->getTagId());
        $this->assertSame('summer', $this->tag->getName());
        $this->assertSame(12, $this->tag->getPopularity());
        $this->assertSame(0.5, $this->tag->getRatio());
    }

    public function testAddBasePopularityFlag(): void
    {
        $this->assertFalse($this->tag->getAddBasePopularity());
        $this->assertSame($this->tag, $this->tag->setAddBasePopularity());
        $this->assertTrue($this->tag->getAddBasePopularity());
        $this->tag->setAddBasePopularity(false);
        $this->assertFalse($this->tag->getAddBasePopularity());
    }

    public function testLoadByNameAndAggregateDelegateToResource(): void
    {
        $this->resource->expects($this->once())->method('loadByName')->with($this->tag, 'summer');
        $this->resource->expects($this->once())->method('aggregate')->with($this->tag);

        $this->assertSame($this->tag, $this->tag->loadByName('summer'));
        $this->assertSame($this->tag, $this->tag->aggregate());
    }

    public function testAddSummarySetsStoreAndDelegates(): void
    {
        $this->resource->expects($this->once())->method('addSummary')->with($this->tag);

        $this->assertSame($this->tag, $this->tag->addSummary(3));
        $this->assertSame(3, $this->tag->getStoreId());
    }

    public function testUrlGetters(): void
    {
        $this->tag->setData('tag_id', 4);
        $this->urlBuilder->expects($this->exactly(4))->method('getUrl')->willReturnCallback(
            static fn(string $route, array $params): string => $route . '?' . http_build_query($params)
        );

        $this->assertSame('tag/product/list?tagId=4', $this->tag->getTaggedProductsUrl());
        $this->assertSame('tag/customer/view?tagId=4', $this->tag->getViewTagUrl());
        $this->assertSame('tag/customer/edit?tagId=4', $this->tag->getEditTagUrl());
        $this->assertSame('tag/customer/remove?tagId=4', $this->tag->getRemoveTagUrl());
    }

    public function testCollectionGetters(): void
    {
        $popular = $this->createMock(PopularCollection::class);
        $product = $this->createMock(ProductCollection::class);
        $customer = $this->createMock(CustomerCollection::class);
        $this->popularFactory->method('create')->willReturn($popular);
        $this->productFactory->method('create')->willReturn($product);
        $this->customerFactory->method('create')->willReturn($customer);

        $this->assertSame($popular, $this->tag->getPopularCollection());
        $this->assertSame($product, $this->tag->getEntityCollection());
        $this->assertSame($customer, $this->tag->getCustomerCollection());
    }

    public function testGetRelatedProductIds(): void
    {
        $relation = $this->createRelation(['getProductIds']);
        $relation->expects($this->once())->method('getProductIds')->willReturn([10, 11]);
        $this->relationFactory->expects($this->once())->method('create')->willReturn($relation);
        $this->tag->setData(['tag_id' => 4, 'store_id' => 2, 'status_filter' => 1]);

        $this->assertSame([10, 11], $this->tag->getRelatedProductIds());
        $this->assertSame(4, $relation->getTagId());
        $this->assertSame(2, $relation->getStoreId());
        $this->assertSame(1, $relation->getStatusFilter());
        $this->assertNull($relation->getCustomerId());
    }

    public function testIsAvailableInStore(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->tag->setVisibleInStoreIds([1, 2]);

        $this->assertTrue($this->tag->isAvailableInStore());
        $this->assertTrue($this->tag->isAvailableInStore(1));
        $this->assertFalse($this->tag->isAvailableInStore(3));
    }

    public function testAfterCommitCallbackReindexesRowWhenNotScheduled(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects($this->once())->method('reindexRow')->with(4);
        $this->indexerRegistry->method('get')->with(Tag::INDEXER_ID)->willReturn($indexer);
        $this->tag->setData('tag_id', 4);

        $this->assertSame($this->tag, $this->tag->afterCommitCallback());
    }

    public function testBeforeDeleteIsRejectedOutsideAdmin(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->registry->method('registry')->with('isSecureArea')->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->tag->beforeDelete();
    }

    public function testBeforeDeleteAllowedInSecureArea(): void
    {
        $this->registry->method('registry')->with('isSecureArea')->willReturn(true);
        $this->storeManager->expects($this->never())->method('getStore');

        $this->assertSame($this->tag, $this->tag->beforeDelete());
    }

    public function testSaveRelationApprovedTagWithoutExistingLinkReturnsSuccess(): void
    {
        $main = $this->createRelation(['getProductIds', 'save']);
        $main->method('getProductIds')->willReturn([]);
        $main->expects($this->once())->method('save');
        $this->relationFactory->expects($this->once())->method('create')->willReturn($main);
        $this->tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_APPROVED]);

        $this->assertSame(Tag::ADD_STATUS_SUCCESS, $this->tag->saveRelation(10, 20, 1));
        $this->assertSame(4, $main->getTagId());
        $this->assertSame(10, $main->getProductId());
        $this->assertSame(20, $main->getCustomerId());
        $this->assertSame(1, $main->getStoreId());
        $this->assertSame(Relation::STATUS_ACTIVE, $main->getActive());
        $this->assertSame('2026-01-01 00:00:00', $main->getCreatedAt());
    }

    public function testSaveRelationApprovedTagAlreadyLinkedByOtherCustomerReturnsExist(): void
    {
        $main = $this->createRelation(['getProductIds', 'save']);
        $main->method('getProductIds')->willReturn([10]);
        $link = $this->createRelation(['loadByTagCustomer', 'getId']);
        $link->method('loadByTagCustomer')->willReturnSelf();
        $link->method('getId')->willReturn(null);
        $main->expects($this->once())->method('save');
        $this->queueRelations([$main, $link]);
        $this->tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_APPROVED]);

        $this->assertSame(Tag::ADD_STATUS_EXIST, $this->tag->saveRelation(10, 20, 1));
    }

    public function testSaveRelationPendingTagReactivatesInactiveRelation(): void
    {
        $main = $this->createRelation(['save', 'setId']);
        $link = $this->createRelation(['loadByTagCustomer', 'getId']);
        $link->method('loadByTagCustomer')->willReturnSelf();
        $link->method('getId')->willReturn(77);
        $link->setData('active', 0);
        $main->expects($this->once())->method('setId')->with(77);
        $main->expects($this->once())->method('save');
        $this->queueRelations([$main, $link]);
        $this->tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_PENDING]);

        $this->assertSame(Tag::ADD_STATUS_NEW, $this->tag->saveRelation(10, 20, 1));
    }

    public function testSaveRelationPendingTagWithActiveRelationDoesNotSave(): void
    {
        $main = $this->createRelation(['save']);
        $link = $this->createRelation(['loadByTagCustomer', 'getId']);
        $link->method('loadByTagCustomer')->willReturnSelf();
        $link->method('getId')->willReturn(77);
        $link->setData('active', 1);
        $main->expects($this->never())->method('save');
        $this->queueRelations([$main, $link]);
        $this->tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_PENDING]);

        $this->assertSame(Tag::ADD_STATUS_NEW, $this->tag->saveRelation(10, 20, 1));
    }

    public function testSaveRelationDisabledTagWithCustomerLinkIsRejected(): void
    {
        $main = $this->createRelation(['save']);
        $link = $this->createRelation(['loadByTagCustomer', 'getProductIds']);
        $link->method('loadByTagCustomer')->willReturnSelf();
        $link->method('getProductIds')->willReturn([10]);
        $main->expects($this->never())->method('save');
        $this->queueRelations([$main, $link]);
        $this->tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_DISABLED]);

        $this->assertSame(Tag::ADD_STATUS_REJECTED, $this->tag->saveRelation(10, 20, 1));
    }

    public function testSaveRelationDisabledTagWithoutLinkBecomesPending(): void
    {
        $main = $this->createRelation(['save']);
        $link = $this->createRelation(['loadByTagCustomer', 'getProductIds']);
        $link->method('loadByTagCustomer')->willReturnSelf();
        $link->method('getProductIds')->willReturn([]);
        $main->expects($this->once())->method('save');
        $this->queueRelations([$main, $link]);

        $tag = $this->getMockBuilder(Tag::class)
            ->setConstructorArgs($this->constructorArgs())
            ->onlyMethods(['save'])
            ->getMock();
        $tag->expects($this->once())->method('save')->willReturnSelf();
        $tag->setData(['tag_id' => 4, 'status' => Tag::STATUS_DISABLED]);

        $this->assertSame(Tag::ADD_STATUS_NEW, $tag->saveRelation(10, 20, 1));
        $this->assertSame(Tag::STATUS_PENDING, $tag->getStatus());
    }

    /**
     * Make relation factory return given relations one by one
     *
     * @param Relation[] $relations
     */
    private function queueRelations(array $relations): void
    {
        $this->relationFactory->method('create')->willReturnCallback(
            static function () use (&$relations) {
                return array_shift($relations);
            }
        );
    }

    /**
     * Create relation mock keeping magic data accessors functional
     *
     * @param string[] $methods
     * @return Relation|MockObject
     */
    private function createRelation(array $methods)
    {
        return $this->getMockBuilder(Relation::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }

    /**
     * @return RemoveAction|MockObject
     */
    private function createActionValidator()
    {
        $validator = $this->createMock(RemoveAction::class);
        $validator->method('isAllowed')->willReturn(true);

        return $validator;
    }

    /**
     * Rebuild constructor arguments for partial mocks of the Tag model
     *
     * @return array
     */
    private function constructorArgs(): array
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createActionValidator());
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');

        return [
            $context,
            $this->registry,
            $this->storeManager,
            $this->urlBuilder,
            $dateTime,
            $this->indexerRegistry,
            $this->relationFactory,
            $this->createMock(TagCollectionFactory::class),
            $this->popularFactory,
            $this->productFactory,
            $this->customerFactory,
            $this->resource,
        ];
    }
}
