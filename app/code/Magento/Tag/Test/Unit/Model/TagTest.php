<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use Magento\Tag\Model\Tag\RelationFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TagTest extends TestCase
{
    /**
     * @var Context&MockObject
     */
    private $context;

    /**
     * @var Registry&MockObject
     */
    private $registry;

    /**
     * @var RelationFactory&MockObject
     */
    private $relationFactory;

    /**
     * @var DateTime&MockObject
     */
    private $dateTime;

    /**
     * @var TagResource&MockObject
     */
    private $tagResource;

    protected function setUp(): void
    {
        $eventManager = $this->createMock(EventManager::class);
        $this->context = $this->createMock(Context::class);
        $this->context->method('getEventDispatcher')->willReturn($eventManager);

        $this->registry = $this->createMock(Registry::class);
        $this->relationFactory = $this->createMock(RelationFactory::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->tagResource = $this->createMock(TagResource::class);
        $this->tagResource->method('getIdFieldName')->willReturn('tag_id');
    }

    private function createTag(int $status, int $existingId = 42): Tag
    {
        $tag = new Tag(
            $this->context,
            $this->registry,
            $this->relationFactory,
            $this->dateTime,
            $this->tagResource
        );
        $tag->setData('tag_id', $existingId);
        $tag->setStatus($status);
        return $tag;
    }

    private function createRelationMock(bool $existingRelation = false, array $productIds = []): Relation
    {
        $relationResource = $this->createMock(RelationResource::class);
        $relationResource->method('formatDate')->willReturn('2023-11-14 22:13:20');
        // First relation created inside saveRelation()
        $freshRelation = $this->getMockBuilder(Relation::class)
            ->onlyMethods(['save', 'getResource', 'loadByTagCustomer', 'getProductIds'])
            ->disableOriginalConstructor()
            ->getMock();
        $freshRelation->method('getResource')->willReturn($relationResource);
        $freshRelation->method('loadByTagCustomer')->willReturnSelf();
        $freshRelation->method('getProductIds')->willReturn($productIds);

        // Second (linked) relation created via _getLinkBetweenTagCustomerProduct
        $linkedRelation = $this->getMockBuilder(Relation::class)
            ->onlyMethods(['save', 'getResource', 'loadByTagCustomer', 'getProductIds', 'getActive'])
            ->disableOriginalConstructor()
            ->getMock();
        $linkedRelation->method('getResource')->willReturn($relationResource);
        $linkedRelation->method('loadByTagCustomer')->willReturnSelf();
        $linkedRelation->method('getProductIds')->willReturn($productIds);
        $linkedRelation->method('getActive')->willReturn(1);
        if ($existingRelation) {
            $linkedRelation->setData('tag_relation_id', 7);
        }

        $this->relationFactory->method('create')->willReturnOnConsecutiveCalls($freshRelation, $linkedRelation);
        return $freshRelation;
    }

    public function testSaveRelationApprovedNewLinkReturnsSuccess(): void
    {
        $tag = $this->createTag(Tag::STATUS_APPROVED);
        $this->createRelationMock(false, []);
        $result = $tag->saveRelation(100, 5, 1);
        $this->assertSame(Tag::ADD_STATUS_SUCCESS, $result);
    }

    public function testSaveRelationApprovedExistingLinkReturnsExist(): void
    {
        $tag = $this->createTag(Tag::STATUS_APPROVED);
        // Product 100 already linked in tag_relation for tag 42
        $this->createRelationMock(false, [100]);
        $result = $tag->saveRelation(100, 5, 1);
        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
    }

    public function testSaveRelationPendingReturnsNew(): void
    {
        $tag = $this->createTag(Tag::STATUS_PENDING);
        $this->createRelationMock(false, []);
        $result = $tag->saveRelation(100, 5, 1);
        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
    }

    public function testSaveRelationDisabledWithExistingCustomerLinkReturnsRejected(): void
    {
        $tag = $this->createTag(Tag::STATUS_DISABLED);
        // Customer already linked this tag+product — checkLinkBetweenTagCustomerProduct returns true
        $this->createRelationMock(false, [100]);
        $result = $tag->saveRelation(100, 5, 1);
        $this->assertSame(Tag::ADD_STATUS_REJECTED, $result);
    }
}
