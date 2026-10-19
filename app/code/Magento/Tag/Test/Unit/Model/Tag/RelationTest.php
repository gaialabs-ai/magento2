<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\Tag;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for Magento\Tag\Model\Tag\Relation, which is mostly a thin AbstractModel
 * wrapper that delegates to its injected resource model.
 */
class RelationTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManager;

    /**
     * @var MockObject|RelationResource
     */
    private $resource;

    /**
     * @var Relation
     */
    private $relation;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManagerHelper($this);

        $this->resource = $this->createMock(RelationResource::class);
        $this->resource->method('getIdFieldName')->willReturn('tag_relation_id');

        // Passing 'resource' directly lets AbstractModel's _getResource() return our mock
        // instead of reaching into the global ObjectManager singleton.
        $this->relation = $this->objectManager->getObject(Relation::class, ['resource' => $this->resource]);
    }

    public function testLoadByTagCustomerSetsFieldsAndDelegatesToResourceWithStoreId(): void
    {
        $this->resource->expects($this->once())
            ->method('loadByTagCustomer')
            ->with($this->identicalTo($this->relation));

        $result = $this->relation->loadByTagCustomer(10, 5, 3, 2);

        $this->assertSame($this->relation, $result);
        $this->assertSame(10, $this->relation->getProductId());
        $this->assertSame(5, $this->relation->getTagId());
        $this->assertSame(3, $this->relation->getCustomerId());
        $this->assertSame(2, $this->relation->getStoreId());
    }

    public function testLoadByTagCustomerDoesNotSetStoreIdWhenStoreIdIsNull(): void
    {
        $this->resource->expects($this->once())
            ->method('loadByTagCustomer')
            ->with($this->identicalTo($this->relation));

        $this->relation->loadByTagCustomer(10, 5, 3);

        $this->assertFalse($this->relation->hasStoreId());
    }

    public function testGetProductIdsCachesResultAfterFirstResourceCall(): void
    {
        $this->resource->expects($this->once())
            ->method('getProductIds')
            ->with($this->identicalTo($this->relation))
            ->willReturn([1, 2, 3]);

        $first = $this->relation->getProductIds();
        $second = $this->relation->getProductIds();

        $this->assertSame([1, 2, 3], $first);
        $this->assertSame([1, 2, 3], $second);
    }

    public function testGetRelatedTagIdsCachesResultAfterFirstResourceCall(): void
    {
        $this->resource->expects($this->once())
            ->method('getRelatedTagIds')
            ->with($this->identicalTo($this->relation))
            ->willReturn([7, 8]);

        $first = $this->relation->getRelatedTagIds();
        $second = $this->relation->getRelatedTagIds();

        $this->assertSame([7, 8], $first);
        $this->assertSame([7, 8], $second);
    }

    public function testDeactivateDelegatesToResourceWithTagIdAndCustomerId(): void
    {
        $this->relation->setTagId(5);
        $this->relation->setCustomerId(9);

        $this->resource->expects($this->once())
            ->method('deactivate')
            ->with(5, 9);

        $result = $this->relation->deactivate();

        $this->assertSame($this->relation, $result);
    }

    public function testAddRelationsSetsFieldsFromTagModelAndDelegatesToResource(): void
    {
        $tagResource = $this->createMock(TagResource::class);
        $tagResource->method('getIdFieldName')->willReturn('tag_id');

        /** @var Tag $tagModel */
        $tagModel = $this->objectManager->getObject(Tag::class, ['resource' => $tagResource]);
        $tagModel->setTagId(42);
        $tagModel->setStore(7);

        $this->resource->expects($this->once())
            ->method('addRelations')
            ->with($this->identicalTo($this->relation));

        $result = $this->relation->addRelations($tagModel, [1, 2, 3]);

        $this->assertSame($this->relation, $result);
        $this->assertSame([1, 2, 3], $this->relation->getAddedProductIds());
        $this->assertSame(42, $this->relation->getTagId());
        $this->assertNull($this->relation->getCustomerId());
        $this->assertSame(7, $this->relation->getStoreId());
    }
}
