<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Tag\Model\Indexer\Summary;
use Magento\Tag\Model\ResourceModel\Indexer\Summary as SummaryResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    /**
     * @var SummaryResource|MockObject
     */
    private $resource;

    /**
     * @var Summary
     */
    private $indexer;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(SummaryResource::class);
        $this->indexer = new Summary($this->resource);
    }

    public function testImplementsIndexerAndMviewInterfaces(): void
    {
        $this->assertInstanceOf(ActionInterface::class, $this->indexer);
        $this->assertInstanceOf(MviewActionInterface::class, $this->indexer);
    }

    public function testExecuteFullReindexesAll(): void
    {
        $this->resource->expects($this->once())->method('reindexAll');
        $this->resource->expects($this->never())->method('aggregate');

        $this->indexer->executeFull();
    }

    public function testExecuteListAggregatesGivenTags(): void
    {
        $this->resource->expects($this->once())->method('aggregate')->with([3, 5]);

        $this->indexer->executeList([3, 5]);
    }

    public function testExecuteRowAggregatesSingleTag(): void
    {
        $this->resource->expects($this->once())->method('aggregate')->with([7]);

        $this->indexer->executeRow(7);
    }

    public function testExecuteNormalizesIds(): void
    {
        $this->resource->expects($this->once())->method('aggregate')->with([1, 2]);

        $this->indexer->execute(['1', 2, 2]);
    }

    public function testExecuteWithEmptyIdsDoesNotRebuildEverything(): void
    {
        $this->resource->expects($this->never())->method('aggregate');

        $this->indexer->execute([]);
    }

    public function testExecuteByProductIdsAggregatesRelatedTags(): void
    {
        $this->resource->expects($this->once())->method('getRelatedTagIds')->with([10, 11])->willReturn([4, 6]);
        $this->resource->expects($this->once())->method('aggregate')->with([4, 6]);

        $this->indexer->executeByProductIds([10, 11]);
    }

    public function testExecuteByProductIdsWithoutRelatedTagsIsNoop(): void
    {
        $this->resource->method('getRelatedTagIds')->willReturn([]);
        $this->resource->expects($this->never())->method('aggregate');

        $this->indexer->executeByProductIds([10]);
    }

    public function testGetProductAttributesDependOn(): void
    {
        $this->assertSame(['visibility', 'status', 'website_ids'], $this->indexer->getProductAttributesDependOn());
    }
}
