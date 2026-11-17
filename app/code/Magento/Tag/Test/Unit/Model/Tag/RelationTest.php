<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\Tag;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RelationTest extends TestCase
{
    /**
     * @var RelationResource|MockObject
     */
    private $resource;

    /**
     * @var IndexerRegistry|MockObject
     */
    private $indexerRegistry;

    /**
     * @var Relation
     */
    private $relation;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $this->resource = $this->createMock(RelationResource::class);
        $this->resource->method('getIdFieldName')->willReturn('tag_relation_id');
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);

        $this->relation = new Relation(
            $context,
            $this->createMock(Registry::class),
            $this->indexerRegistry,
            $this->resource
        );
    }

    public function testStatusConstants(): void
    {
        $this->assertSame(1, Relation::STATUS_ACTIVE);
        $this->assertSame(0, Relation::STATUS_NOT_ACTIVE);
        $this->assertSame('tag_relation', Relation::ENTITY);
    }

    public function testLoadByTagCustomerSetsDataAndDelegatesToResource(): void
    {
        $this->resource->expects($this->once())->method('loadByTagCustomer')->with($this->relation);

        $this->assertSame($this->relation, $this->relation->loadByTagCustomer(5, 3, 7, 1));
        $this->assertSame(5, $this->relation->getProductId());
        $this->assertSame(3, $this->relation->getTagId());
        $this->assertSame(7, $this->relation->getCustomerId());
        $this->assertSame(1, $this->relation->getStoreId());
    }

    public function testLoadByTagCustomerWithoutStoreDoesNotSetStore(): void
    {
        $this->relation->loadByTagCustomer(null, 3, 7);

        $this->assertFalse($this->relation->hasStoreId());
    }

    public function testGetProductIdsIsFetchedOnceAndCached(): void
    {
        $this->resource->expects($this->once())->method('getProductIds')->with($this->relation)->willReturn([1, 2]);

        $this->assertSame([1, 2], $this->relation->getProductIds());
        $this->assertSame([1, 2], $this->relation->getProductIds());
    }

    public function testGetRelatedTagIdsIsFetchedOnceAndCached(): void
    {
        $this->resource->expects($this->once())->method('getRelatedTagIds')->with($this->relation)->willReturn([4]);

        $this->assertSame([4], $this->relation->getRelatedTagIds());
        $this->assertSame([4], $this->relation->getRelatedTagIds());
    }

    public function testDeactivateUsesTagAndCustomer(): void
    {
        $this->relation->setTagId(3)->setCustomerId(7);
        $this->resource->expects($this->once())->method('deactivate')->with(3, 7);

        $this->assertSame($this->relation, $this->relation->deactivate());
    }

    public function testAddRelationsPreparesDataAndDelegatesToResource(): void
    {
        $tag = $this->getMockBuilder(Tag::class)->disableOriginalConstructor()->onlyMethods(['save'])->getMock();
        $tag->setData(['tag_id' => 9, 'store' => 2]);

        $this->relation->setCustomerId(55);
        $this->resource->expects($this->once())->method('addRelations')->with($this->relation);

        $this->assertSame($this->relation, $this->relation->addRelations($tag, [1, 2, 3]));
        $this->assertSame([1, 2, 3], $this->relation->getAddedProductIds());
        $this->assertSame(9, $this->relation->getTagId());
        $this->assertNull($this->relation->getCustomerId());
        $this->assertSame(2, $this->relation->getStoreId());
    }

    public function testAfterCommitCallbackReindexesTagWhenNotScheduled(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->expects($this->once())->method('reindexRow')->with(3);
        $this->indexerRegistry->method('get')->with(Tag::INDEXER_ID)->willReturn($indexer);

        $this->relation->setTagId(3);
        $this->relation->afterCommitCallback();
    }

    public function testAfterCommitCallbackSkipsScheduledIndexer(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn(true);
        $indexer->expects($this->never())->method('reindexRow');
        $this->indexerRegistry->method('get')->willReturn($indexer);

        $this->relation->setTagId(3);
        $this->relation->afterCommitCallback();
    }
}
