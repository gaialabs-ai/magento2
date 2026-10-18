<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Tag\Helper\Data as TagHelper;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\Tag\Relation;
use Magento\Tag\Model\Tag\RelationFactory;
use Magento\Tag\Model\TagFactory;
use Magento\Tag\Model\TagManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the tag add/remove orchestration ported from
 * Mage_Tag_IndexController::saveAction() and Mage_Tag_CustomerController::removeAction().
 */
class TagManagementTest extends TestCase
{
    private const PRODUCT_ID = 123;
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManager;

    /**
     * @var TagManagement
     */
    private $tagManagement;

    /**
     * @var MockObject|TagFactory
     */
    private $tagFactory;

    /**
     * @var MockObject|RelationFactory
     */
    private $tagRelationFactory;

    /**
     * @var MockObject|TagHelper
     */
    private $tagHelper;

    protected function setUp(): void
    {
        $this->tagFactory = $this->createMock(TagFactory::class);
        $this->tagRelationFactory = $this->createMock(RelationFactory::class);
        $this->tagHelper = $this->createMock(TagHelper::class);

        $this->objectManager = new ObjectManagerHelper($this);
        $this->tagManagement = $this->objectManager->getObject(
            TagManagement::class,
            [
                'tagFactory' => $this->tagFactory,
                'tagRelationFactory' => $this->tagRelationFactory,
                'tagHelper' => $this->tagHelper,
            ]
        );
    }

    /**
     * Build a Tag mock with the resource-dependent methods (unsetData, loadByName, getId, save,
     * saveRelation) under our control, while leaving the magic setters/getters inherited from
     * AbstractModel/DataObject (setName, setFirstCustomerId, setFirstStoreId, setStatus) real so
     * they behave exactly as they do in production.
     *
     * @return MockObject|Tag
     */
    private function createTagMock(): MockObject
    {
        return $this->getMockBuilder(Tag::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['unsetData', 'loadByName', 'getId', 'save', 'saveRelation'])
            ->getMock();
    }

    /**
     * Build a Tag\Relation mock with the resource-dependent methods under our control.
     *
     * @return MockObject|Relation
     */
    private function createRelationMock(): MockObject
    {
        return $this->getMockBuilder(Relation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByTagCustomer', 'deactivate'])
            ->getMock();
    }

    public function testAddTagsToProductTokenizesViaHelperAndDoesNotReimplement(): void
    {
        $this->tagHelper->expects($this->once())
            ->method('extractTags')
            ->with('red blue')
            ->willReturn(['red', 'blue']);
        $this->tagHelper->expects($this->once())
            ->method('cleanTags')
            ->with(['red', 'blue'])
            ->willReturn(['red', 'blue']);

        $tagModel = $this->createTagMock();
        $tagModel->method('unsetData')->willReturnSelf();
        $tagModel->method('loadByName')->willReturnSelf();
        $tagModel->method('getId')->willReturn(1);
        $tagModel->expects($this->never())->method('save');
        $tagModel->method('saveRelation')->willReturn(Tag::ADD_STATUS_SUCCESS);

        $this->tagFactory->method('create')->willReturn($tagModel);

        $result = $this->tagManagement->addTagsToProduct(
            'red blue',
            self::PRODUCT_ID,
            self::CUSTOMER_ID,
            self::STORE_ID
        );

        $this->assertSame(['red', 'blue'], $result[Tag::ADD_STATUS_SUCCESS]);
    }

    public function testAddTagsToProductCreatesPendingTagWhenNameDoesNotExist(): void
    {
        $this->tagHelper->method('extractTags')->willReturn(['newtag']);
        $this->tagHelper->method('cleanTags')->willReturn(['newtag']);

        $tagModel = $this->createTagMock();
        $tagModel->expects($this->once())->method('unsetData')->willReturnSelf();
        $tagModel->expects($this->once())->method('loadByName')->with('newtag')->willReturnSelf();
        // getId() is read after loadByName() (empty -> create) and may be consulted again
        // while the magic setters/save() below are chained fluently.
        $tagModel->method('getId')->willReturn(null);
        $tagModel->expects($this->once())->method('save')->willReturnSelf();
        $tagModel->expects($this->once())
            ->method('saveRelation')
            ->with(self::PRODUCT_ID, self::CUSTOMER_ID, self::STORE_ID)
            ->willReturn(Tag::ADD_STATUS_NEW);

        $this->tagFactory->method('create')->willReturn($tagModel);

        $result = $this->tagManagement->addTagsToProduct(
            'newtag',
            self::PRODUCT_ID,
            self::CUSTOMER_ID,
            self::STORE_ID
        );

        $this->assertSame(['newtag'], $result[Tag::ADD_STATUS_NEW]);
        $this->assertSame([], $result[Tag::ADD_STATUS_EXIST]);
        $this->assertSame([], $result[Tag::ADD_STATUS_SUCCESS]);
        $this->assertSame([], $result[Tag::ADD_STATUS_REJECTED]);

        // Magic setters (inherited from AbstractModel/DataObject) really ran against the
        // (non-mocked) data store, proving the pending-tag fields were populated before save().
        $this->assertSame('newtag', $tagModel->getName());
        $this->assertSame(self::CUSTOMER_ID, $tagModel->getFirstCustomerId());
        $this->assertSame(self::STORE_ID, $tagModel->getFirstStoreId());
        $this->assertSame(Tag::STATUS_PENDING, $tagModel->getStatus());
    }

    public function testAddTagsToProductSkipsCreationWhenTagAlreadyExistsByName(): void
    {
        $this->tagHelper->method('extractTags')->willReturn(['existing']);
        $this->tagHelper->method('cleanTags')->willReturn(['existing']);

        $tagModel = $this->createTagMock();
        $tagModel->method('unsetData')->willReturnSelf();
        $tagModel->expects($this->once())->method('loadByName')->with('existing')->willReturnSelf();
        $tagModel->method('getId')->willReturn(99);
        $tagModel->expects($this->never())->method('save');
        $tagModel->expects($this->once())
            ->method('saveRelation')
            ->with(self::PRODUCT_ID, self::CUSTOMER_ID, self::STORE_ID)
            ->willReturn(Tag::ADD_STATUS_EXIST);

        $this->tagFactory->method('create')->willReturn($tagModel);

        $result = $this->tagManagement->addTagsToProduct(
            'existing',
            self::PRODUCT_ID,
            self::CUSTOMER_ID,
            self::STORE_ID
        );

        $this->assertSame(['existing'], $result[Tag::ADD_STATUS_EXIST]);
    }

    public function testAddTagsToProductBucketsMultipleTagNamesByTheirOwnOutcome(): void
    {
        $this->tagHelper->method('extractTags')->willReturn(['alpha', 'beta', 'gamma', 'delta']);
        $this->tagHelper->method('cleanTags')->willReturn(['alpha', 'beta', 'gamma', 'delta']);

        $tagModel = $this->createTagMock();
        $tagModel->method('unsetData')->willReturnSelf();
        $tagModel->method('loadByName')->willReturnSelf();
        $tagModel->method('getId')->willReturn(1);
        $tagModel->method('save')->willReturnSelf();
        $tagModel->method('saveRelation')->willReturnOnConsecutiveCalls(
            Tag::ADD_STATUS_NEW,
            Tag::ADD_STATUS_EXIST,
            Tag::ADD_STATUS_SUCCESS,
            Tag::ADD_STATUS_REJECTED
        );

        $this->tagFactory->method('create')->willReturn($tagModel);

        $result = $this->tagManagement->addTagsToProduct(
            'alpha beta gamma delta',
            self::PRODUCT_ID,
            self::CUSTOMER_ID,
            self::STORE_ID
        );

        $this->assertSame(['alpha'], $result[Tag::ADD_STATUS_NEW]);
        $this->assertSame(['beta'], $result[Tag::ADD_STATUS_EXIST]);
        $this->assertSame(['gamma'], $result[Tag::ADD_STATUS_SUCCESS]);
        $this->assertSame(['delta'], $result[Tag::ADD_STATUS_REJECTED]);
    }

    public function testAddTagsToProductAlwaysReturnsAllFourStatusKeysEvenWhenEmpty(): void
    {
        $this->tagHelper->method('extractTags')->willReturn([]);
        $this->tagHelper->method('cleanTags')->willReturn([]);

        $this->tagFactory->method('create')->willReturn($this->createTagMock());

        $result = $this->tagManagement->addTagsToProduct(
            '',
            self::PRODUCT_ID,
            self::CUSTOMER_ID,
            self::STORE_ID
        );

        $this->assertSame(
            [
                Tag::ADD_STATUS_NEW => [],
                Tag::ADD_STATUS_EXIST => [],
                Tag::ADD_STATUS_SUCCESS => [],
                Tag::ADD_STATUS_REJECTED => [],
            ],
            $result
        );
    }

    public function testRemoveTagFromProductLoadsRelationByTagAndCustomerThenDeactivates(): void
    {
        $tagId = 7;

        $relationModel = $this->createRelationMock();
        $relationModel->expects($this->once())
            ->method('loadByTagCustomer')
            ->with(null, $tagId, self::CUSTOMER_ID)
            ->willReturnSelf();
        $relationModel->expects($this->once())
            ->method('deactivate')
            ->willReturnSelf();

        $this->tagRelationFactory->expects($this->once())
            ->method('create')
            ->willReturn($relationModel);

        $this->tagManagement->removeTagFromProduct($tagId, self::CUSTOMER_ID);
    }
}
