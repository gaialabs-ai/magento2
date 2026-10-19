<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\ObjectRelationProcessor;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\ResourceModel\Tag as TagResource;
use Magento\Tag\Model\Tag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Coverage for Magento\Tag\Model\ResourceModel\Tag, the AbstractDb-based resource model
 * for tags. Public behaviour (loadByName) is exercised directly; protected AbstractDb
 * hook methods (_beforeSave/_afterSave/_getLoadSelect/_afterLoad/_initUniqueFields) are
 * exercised via reflection against a connection/select double, since AbstractDb talks to
 * a real DB adapter in production.
 */
class TagTest extends TestCase
{
    /**
     * @var MockObject|AdapterInterface
     */
    private $connection;

    /**
     * @var MockObject|Select
     */
    private $select;

    /**
     * @var MockObject|ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var MockObject|StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TagResource
     */
    private $resourceModel;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();

        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quoteIdentifier')->willReturnArgument(0);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(
            static function ($table) {
                return is_array($table) ? implode('_', $table) : $table;
            }
        );

        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($this->resourceConnection);
        $context->method('getTransactionManager')->willReturn($this->createMock(TransactionManagerInterface::class));
        $context->method('getObjectRelationProcessor')->willReturn($this->createMock(ObjectRelationProcessor::class));

        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->objectManager = new ObjectManagerHelper($this);
        $this->resourceModel = $this->objectManager->getObject(
            TagResource::class,
            [
                'context' => $context,
                'storeManager' => $this->storeManager,
            ]
        );
    }

    /**
     * Invoke a protected/private method via reflection.
     *
     * @param object $object
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function invokeMethod(object $object, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    /**
     * Build a real Tag model instance (so the custom getApprovedStatus()/getPendingStatus()/
     * isObjectNew()/hasStoreId() behaviour used by the resource model's protected hooks is
     * real), with its own resource dependency mocked out so construction never touches the
     * global ObjectManager singleton.
     *
     * @return Tag
     */
    private function createTagObject(): Tag
    {
        $tagResource = $this->createMock(TagResource::class);
        $tagResource->method('getIdFieldName')->willReturn('tag_id');

        return $this->objectManager->getObject(Tag::class, ['resource' => $tagResource]);
    }

    public function testLoadByNameReturnsFalseAndNeverTouchesConnectionWhenNameIsEmpty(): void
    {
        $this->connection->expects($this->never())->method('fetchRow');

        $model = new DataObject();
        $result = $this->resourceModel->loadByName($model, '');

        $this->assertFalse($result);
    }

    public function testLoadByNameFetchesRowAndSetsModelDataWhenFound(): void
    {
        $row = ['tag_id' => 5, 'name' => 'shoes', 'status' => 1];
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['name' => 'shoes'])
            ->willReturn($row);

        $model = new DataObject();
        $result = $this->resourceModel->loadByName($model, 'shoes');

        $this->assertNull($result);
        $this->assertSame($row, $model->getData());
    }

    public function testLoadByNameSetsEmptyArrayWhenNoRowFound(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        $model = new DataObject();
        $this->resourceModel->loadByName($model, 'missing');

        $this->assertSame([], $model->getData());
    }

    public function testLoadByNameTruncatesNameLongerThan255CharsBeforeBindingParameter(): void
    {
        $longName = str_repeat('a', 300);
        $truncated = str_repeat('a', 255);

        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['name' => $truncated])
            ->willReturn(false);

        $model = new DataObject();
        $this->resourceModel->loadByName($model, $longName);
    }

    public function testBeforeSaveSetsIdFromExistingPendingTagWhenCreatingApprovedTagWithSameName(): void
    {
        $object = $this->createTagObject();
        $object->setName('shoes');
        $object->setStatus(Tag::STATUS_APPROVED);

        $existingRow = ['tag_id' => 77, 'name' => 'shoes', 'status' => Tag::STATUS_PENDING];
        $this->connection->method('fetchRow')->willReturn($existingRow);

        $this->invokeMethod($this->resourceModel, '_beforeSave', [$object]);

        $this->assertSame(77, $object->getId());
    }

    public function testBeforeSaveDoesNotSetIdWhenExistingTagWithSameNameIsNotPending(): void
    {
        $object = $this->createTagObject();
        $object->setName('shoes');
        $object->setStatus(Tag::STATUS_APPROVED);

        $existingRow = ['tag_id' => 77, 'name' => 'shoes', 'status' => Tag::STATUS_APPROVED];
        $this->connection->method('fetchRow')->willReturn($existingRow);

        $this->invokeMethod($this->resourceModel, '_beforeSave', [$object]);

        $this->assertNull($object->getId());
    }

    public function testBeforeSaveDoesNotDedupWhenObjectAlreadyHasId(): void
    {
        $object = $this->createTagObject();
        $object->setId(999);
        $object->setName('shoes');
        $object->setStatus(Tag::STATUS_APPROVED);

        $this->connection->expects($this->never())->method('fetchRow');

        $this->invokeMethod($this->resourceModel, '_beforeSave', [$object]);

        $this->assertSame(999, $object->getId());
    }

    public function testBeforeSaveDoesNotDedupWhenObjectStatusIsNotApproved(): void
    {
        $object = $this->createTagObject();
        $object->setName('shoes');
        $object->setStatus(Tag::STATUS_PENDING);

        $this->connection->expects($this->never())->method('fetchRow');

        $this->invokeMethod($this->resourceModel, '_beforeSave', [$object]);

        $this->assertNull($object->getId());
    }

    public function testBeforeSaveTruncatesNameLongerThan255Chars(): void
    {
        $object = $this->createTagObject();
        $object->setName(str_repeat('a', 300));
        $object->setStatus(Tag::STATUS_PENDING);

        $this->invokeMethod($this->resourceModel, '_beforeSave', [$object]);

        $this->assertSame(str_repeat('a', 255), $object->getName());
    }

    public function testAfterSaveInsertsTagPropertiesForNewObjectWhenAdminStoreSaveWithStoreSet(): void
    {
        $object = $this->createTagObject();
        $object->setStore(1);
        $object->setBasePopularity(5);
        $object->setTagId(42);
        // getId() is null (not set) -> isObjectNew() is true -> tagId comes from getTagId()

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn(Store::ADMIN_CODE);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'tag_properties',
                [
                    'tag_id' => 42,
                    'store_id' => 1,
                    'base_popularity' => 5,
                ]
            );

        $this->invokeMethod($this->resourceModel, '_afterSave', [$object]);
    }

    public function testAfterSaveInsertsTagPropertiesForExistingObjectUsingObjectId(): void
    {
        $object = $this->createTagObject();
        $object->setStore(1);
        $object->setId(99);
        // base popularity left unset -> falls back to 0

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn(Store::ADMIN_CODE);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'tag_properties',
                [
                    'tag_id' => 99,
                    'store_id' => 1,
                    'base_popularity' => 0,
                ]
            );

        $this->invokeMethod($this->resourceModel, '_afterSave', [$object]);
    }

    public function testAfterSaveFallsThroughToParentWhenObjectHasNoStore(): void
    {
        $object = $this->createTagObject();
        // getStore() left unset -> falsy

        $this->storeManager->expects($this->never())->method('getStore');
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->invokeMethod($this->resourceModel, '_afterSave', [$object]);
    }

    public function testAfterSaveFallsThroughToParentWhenStoreCodeIsNotAdmin(): void
    {
        $object = $this->createTagObject();
        $object->setStore(1);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->invokeMethod($this->resourceModel, '_afterSave', [$object]);
    }

    public function testGetLoadSelectAddsBasePopularityJoinWhenFlagSetAndStoreIdPresent(): void
    {
        $object = $this->createTagObject();
        $object->setAddBasePopularity(true);
        $object->setStoreId(5);

        $this->select->expects($this->once())
            ->method('joinLeft')
            ->with(
                ['properties' => 'tag_properties'],
                $this->stringContains('properties.store_id = 5'),
                'base_popularity'
            )
            ->willReturnSelf();

        $result = $this->invokeMethod($this->resourceModel, '_getLoadSelect', ['tag_id', 1, $object]);

        $this->assertSame($this->select, $result);
    }

    public function testGetLoadSelectDoesNotAddJoinWhenAddBasePopularityFlagIsFalse(): void
    {
        $object = $this->createTagObject();
        $object->setStoreId(5);

        $this->select->expects($this->never())->method('joinLeft');

        $this->invokeMethod($this->resourceModel, '_getLoadSelect', ['tag_id', 1, $object]);
    }

    public function testGetLoadSelectDoesNotAddJoinWhenStoreIdNotSet(): void
    {
        $object = $this->createTagObject();
        $object->setAddBasePopularity(true);
        // hasStoreId() left false

        $this->select->expects($this->never())->method('joinLeft');

        $this->invokeMethod($this->resourceModel, '_getLoadSelect', ['tag_id', 1, $object]);
    }

    public function testAfterLoadSetsVisibleInStoreIdsFromTagSummary(): void
    {
        $object = $this->createTagObject();
        $object->setId(10);

        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 10])
            ->willReturn([1, 2, 3]);

        $this->invokeMethod($this->resourceModel, '_afterLoad', [$object]);

        $this->assertSame([1, 2, 3], $object->getVisibleInStoreIds());
    }

    public function testInitUniqueFieldsReturnsNameFieldShape(): void
    {
        $result = $this->invokeMethod($this->resourceModel, '_initUniqueFields');

        $this->assertSame($this->resourceModel, $result);

        $ref = new ReflectionProperty(AbstractDb::class, '_uniqueFields');
        $ref->setAccessible(true);
        $uniqueFields = $ref->getValue($this->resourceModel);

        $this->assertCount(1, $uniqueFields);
        $this->assertSame('name', $uniqueFields[0]['field']);
        $this->assertSame('Tag', (string)$uniqueFields[0]['title']);
    }
}
