<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use Magento\Tag\Model\Tag\RelationFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Coverage for Magento\Tag\Model\Tag, with particular focus on the saveRelation()
 * approve/reactivate/reject state machine, which is the highest-risk piece of logic
 * ported into this module.
 */
class TagTest extends TestCase
{
    /**
     * Build a Tag instance with its constructor skipped (so no DB/ObjectManager singleton
     * is touched) and only the resource-dependent save() method replaced by a mock. All
     * other inherited AbstractModel/DataObject magic getters/setters (setStatus, getId,
     * setVisibleInStoreIds, etc.) remain real.
     *
     * @return MockObject|Tag
     */
    private function createTagMock(): MockObject
    {
        return $this->getMockBuilder(Tag::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
    }

    /**
     * Build a Tag\Relation instance with its constructor skipped and only the given
     * resource-dependent methods replaced by mocks. Fluent magic setters/getters
     * (setTagId, getCustomerId, setActive, ...) remain real.
     *
     * @param string[] $onlyMethods
     * @return MockObject|Relation
     */
    private function createRelationMock(array $onlyMethods): MockObject
    {
        return $this->getMockBuilder(Relation::class)
            ->disableOriginalConstructor()
            ->onlyMethods($onlyMethods)
            ->getMock();
    }

    /**
     * Inject a value into one of Tag's private constructor-injected properties, bypassing
     * the constructor we deliberately skipped above.
     *
     * @param object $object
     * @param string $property
     * @param mixed $value
     * @return void
     */
    private function setPrivateProperty(object $object, string $property, $value): void
    {
        $ref = new ReflectionProperty(Tag::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    /**
     * Build a fully wired Tag + Relation pair ready for saveRelation() tests:
     * relationFactory->create() always returns the same relation mock, mirroring the
     * production code's behaviour of re-fetching "the" relation via the factory multiple
     * times within a single saveRelation() call while actually operating on one row.
     *
     * @param string[] $relationOnlyMethods
     * @return array{0: MockObject|Tag, 1: MockObject|Relation}
     */
    private function createTagWithRelation(array $relationOnlyMethods): array
    {
        $tag = $this->createTagMock();
        $relation = $this->createRelationMock(array_unique(array_merge($relationOnlyMethods, ['loadByTagCustomer'])));
        $relation->method('loadByTagCustomer')->willReturnSelf();

        $relationFactory = $this->createMock(RelationFactory::class);
        $relationFactory->method('create')->willReturn($relation);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-18 00:00:00');

        $this->setPrivateProperty($tag, 'relationFactory', $relationFactory);
        $this->setPrivateProperty($tag, 'dateTime', $dateTime);
        $this->setPrivateProperty($tag, 'storeManager', $this->createMock(StoreManagerInterface::class));

        return [$tag, $relation];
    }

    public function testGetApprovedStatusReturnsConstant(): void
    {
        $tag = $this->createTagMock();
        $this->assertSame(Tag::STATUS_APPROVED, $tag->getApprovedStatus());
    }

    public function testGetPendingStatusReturnsConstant(): void
    {
        $tag = $this->createTagMock();
        $this->assertSame(Tag::STATUS_PENDING, $tag->getPendingStatus());
    }

    public function testGetDisabledStatusReturnsConstant(): void
    {
        $tag = $this->createTagMock();
        $this->assertSame(Tag::STATUS_DISABLED, $tag->getDisabledStatus());
    }

    public function testLoadByNameDelegatesToResource(): void
    {
        $tag = $this->createTagMock();

        $resource = $this->createMock(TagResource::class);
        $resource->expects($this->once())
            ->method('loadByName')
            ->with($this->identicalTo($tag), 'shoes');

        $ref = new ReflectionProperty(AbstractModel::class, '_resource');
        $ref->setAccessible(true);
        $ref->setValue($tag, $resource);

        $result = $tag->loadByName('shoes');

        $this->assertSame($tag, $result);
    }

    public function testSetAddBasePopularityAndGetAddBasePopularityRoundTrip(): void
    {
        $tag = $this->createTagMock();

        $this->assertFalse($tag->getAddBasePopularity());

        $this->assertSame($tag, $tag->setAddBasePopularity(true));
        $this->assertTrue($tag->getAddBasePopularity());

        $tag->setAddBasePopularity(false);
        $this->assertFalse($tag->getAddBasePopularity());

        // default argument value is true
        $tag->setAddBasePopularity();
        $this->assertTrue($tag->getAddBasePopularity());
    }

    public function testSetRatioSetsRatioDataKey(): void
    {
        $tag = $this->createTagMock();

        $this->assertSame($tag, $tag->setRatio(4.5));
        $this->assertSame(4.5, $tag->getData('ratio'));
        $this->assertSame(4.5, $tag->getRatio());
    }

    public function testIsAvailableInStoreWithExplicitStoreIdReturnsTrueWhenStoreIdInVisibleList(): void
    {
        $tag = $this->createTagMock();
        $tag->setVisibleInStoreIds([1, 2, 3]);

        $this->assertTrue($tag->isAvailableInStore(2));
    }

    public function testIsAvailableInStoreWithExplicitStoreIdReturnsFalseWhenStoreIdNotInVisibleList(): void
    {
        $tag = $this->createTagMock();
        $tag->setVisibleInStoreIds([1, 2, 3]);

        $this->assertFalse($tag->isAvailableInStore(9));
    }

    public function testIsAvailableInStoreFallsBackToStoreManagerGetStoreWhenStoreIdIsNull(): void
    {
        $tag = $this->createTagMock();
        $tag->setVisibleInStoreIds([5]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(5);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStore')->willReturn($store);

        $this->setPrivateProperty($tag, 'storeManager', $storeManager);

        $this->assertTrue($tag->isAvailableInStore());
    }

    public function testIsAvailableInStoreFallsBackToStoreManagerGetStoreAndReturnsFalseWhenNotVisible(): void
    {
        $tag = $this->createTagMock();
        $tag->setVisibleInStoreIds([5]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(99);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStore')->willReturn($store);

        $this->setPrivateProperty($tag, 'storeManager', $storeManager);

        $this->assertFalse($tag->isAvailableInStore());
    }

    public function testGetRelatedProductIdsDelegatesThroughRelationFactoryFluentChain(): void
    {
        $tag = $this->createTagMock();
        $tag->setTagId(11);
        $tag->setStoreId(2);
        $tag->setStatusFilter(1);

        $relation = $this->createRelationMock(['getProductIds']);
        $relation->method('getProductIds')->willReturn([100, 200]);

        $relationFactory = $this->createMock(RelationFactory::class);
        $relationFactory->method('create')->willReturn($relation);
        $this->setPrivateProperty($tag, 'relationFactory', $relationFactory);

        $result = $tag->getRelatedProductIds();

        $this->assertSame([100, 200], $result);
        $this->assertSame(11, $relation->getTagId());
        $this->assertSame(2, $relation->getStoreId());
        $this->assertSame(1, $relation->getStatusFilter());
        $this->assertNull($relation->getCustomerId());
    }

    /**
     * Approved tag, product not yet linked to the tag at all -> relation is created and
     * saved, result is ADD_STATUS_SUCCESS.
     */
    public function testSaveRelationApprovedNotLinkedReturnsSuccessAndSavesRelation(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_APPROVED);

        // product 55 is not among the products already linked to this tag
        $relation->method('getProductIds')->willReturn([1, 2, 3]);
        $relation->expects($this->once())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_SUCCESS, $result);
    }

    /**
     * Approved tag, product already linked to the tag, but no existing tag/customer/product
     * relation row -> relation is (re)created and saved, result is ADD_STATUS_EXIST.
     */
    public function testSaveRelationApprovedLinkedWithNoExistingCustomerRelationSavesAndReturnsExist(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_APPROVED);

        $relation->method('getProductIds')->willReturn([55]);
        $relation->method('getId')->willReturn(null);
        $relation->expects($this->once())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
    }

    /**
     * Approved tag, product already linked, an existing relation row exists but is
     * inactive -> relation is reactivated (id copied over) and saved, ADD_STATUS_EXIST.
     */
    public function testSaveRelationApprovedLinkedWithInactiveExistingRelationReactivatesAndReturnsExist(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_APPROVED);

        $relation->method('getProductIds')->willReturn([55]);
        $relation->method('getId')->willReturn(321);
        $relation->method('getActive')->willReturn(0);
        $relation->expects($this->once())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
        $this->assertSame(321, $relation->getData('id'));
    }

    /**
     * Approved tag, product already linked, an existing relation row exists and is already
     * active -> nothing is saved, ADD_STATUS_EXIST is still returned.
     */
    public function testSaveRelationApprovedLinkedWithActiveExistingRelationDoesNotSaveAndReturnsExist(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_APPROVED);

        $relation->method('getProductIds')->willReturn([55]);
        $relation->method('getId')->willReturn(321);
        $relation->method('getActive')->willReturn(1);
        $relation->expects($this->never())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_EXIST, $result);
    }

    /**
     * Pending tag, no existing tag/customer/product relation row -> relation is created and
     * saved, ADD_STATUS_NEW.
     */
    public function testSaveRelationPendingWithNoExistingRelationSavesAndReturnsNew(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_PENDING);

        $relation->method('getId')->willReturn(null);
        $relation->expects($this->once())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
    }

    /**
     * Pending tag, an existing relation row exists but is inactive -> relation is
     * reactivated (id copied over) and saved, ADD_STATUS_NEW.
     */
    public function testSaveRelationPendingWithInactiveExistingRelationReactivatesAndReturnsNew(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_PENDING);

        $relation->method('getId')->willReturn(55);
        $relation->method('getActive')->willReturn(0);
        $relation->expects($this->once())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertSame(55, $relation->getData('id'));
    }

    /**
     * Pending tag, an existing relation row exists and is already active -> nothing is
     * saved, but (per the ported legacy quirk) ADD_STATUS_NEW is still returned regardless.
     */
    public function testSaveRelationPendingWithActiveExistingRelationDoesNotSaveButStillReturnsNew(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getId', 'getActive', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_PENDING);

        $relation->method('getId')->willReturn(55);
        $relation->method('getActive')->willReturn(1);
        $relation->expects($this->never())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
    }

    /**
     * Disabled tag, the customer already has a relation to this tag+product -> rejected,
     * nothing is saved, and the tag's own status is left untouched.
     */
    public function testSaveRelationDisabledWithExistingCustomerRelationReturnsRejected(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_DISABLED);

        // non-empty product ids -> _checkLinkBetweenTagCustomerProduct() is true
        $relation->method('getProductIds')->willReturn([55]);
        $relation->expects($this->never())->method('save');
        $tag->expects($this->never())->method('save');

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_REJECTED, $result);
        $this->assertSame(Tag::STATUS_DISABLED, $tag->getStatus());
    }

    /**
     * Disabled tag, the customer has no existing relation to this tag+product -> the tag
     * itself is bumped back to pending and saved, the relation is created and saved,
     * ADD_STATUS_NEW is returned.
     */
    public function testSaveRelationDisabledWithoutExistingCustomerRelationBumpsStatusToPendingAndReturnsNew(): void
    {
        [$tag, $relation] = $this->createTagWithRelation(['getProductIds', 'save']);
        $tag->setId(10);
        $tag->setStatus(Tag::STATUS_DISABLED);

        // empty product ids -> _checkLinkBetweenTagCustomerProduct() is false
        $relation->method('getProductIds')->willReturn([]);
        $relation->expects($this->once())->method('save');
        $tag->expects($this->once())->method('save')->willReturnSelf();

        $result = $tag->saveRelation(55, 7, 1);

        $this->assertSame(Tag::ADD_STATUS_NEW, $result);
        $this->assertSame(Tag::STATUS_PENDING, $tag->getStatus());
    }
}
