<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Tag;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\ObjectRelationProcessor;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Tag\Model\ResourceModel\Tag\Relation as RelationResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for Magento\Tag\Model\ResourceModel\Tag\Relation, the AbstractDb-based resource
 * model that keeps the tag_relation table in sync. Public behaviour is exercised directly
 * against a connection/select double (mirroring the technique used in the sibling
 * Magento\Tag\Test\Unit\Model\ResourceModel\TagTest), since AbstractDb talks to a real DB
 * adapter in production. Rather than hard-coding the array keys array_diff() happens to
 * produce, where()/join() calls and insertMultiple()/delete() arguments are captured via
 * callbacks and asserted on their meaningful content, so the tests exercise the actual
 * diff/conditional-SQL logic instead of just mocking it away.
 */
class RelationTest extends TestCase
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
     * @var MockObject|DateTime
     */
    private $dateTime;

    /**
     * @var RelationResource
     */
    private $resourceModel;

    /**
     * @var ObjectManagerHelper
     */
    private $objectManager;

    /**
     * Captured arguments of every $select->where(...) call made during a test.
     *
     * @var array[]
     */
    private $whereCalls = [];

    /**
     * Captured arguments of every $select->join(...) call made during a test.
     *
     * @var array[]
     */
    private $joinCalls = [];

    protected function setUp(): void
    {
        $this->whereCalls = [];
        $this->joinCalls = [];

        $this->connection = $this->createMock(AdapterInterface::class);

        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('where')->willReturnCallback(function (...$args) {
            $this->whereCalls[] = $args;
            return $this->select;
        });
        $this->select->method('join')->willReturnCallback(function (...$args) {
            $this->joinCalls[] = $args;
            return $this->select;
        });

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

        $this->dateTime = $this->createMock(DateTime::class);

        $this->objectManager = new ObjectManagerHelper($this);
        $this->resourceModel = $this->objectManager->getObject(
            RelationResource::class,
            [
                'context' => $context,
                'dateTime' => $this->dateTime,
            ]
        );
    }

    // --------------------------------------------------------------------
    // loadByTagCustomer()
    // --------------------------------------------------------------------

    public function testLoadByTagCustomerDoesNothingWhenTagIdIsNotSet(): void
    {
        $this->connection->expects($this->never())->method('fetchRow');

        $model = new DataObject(['customer_id' => 9]);
        $result = $this->resourceModel->loadByTagCustomer($model);

        $this->assertSame($this->resourceModel, $result);
        $this->assertSame([], $this->whereCalls);
    }

    public function testLoadByTagCustomerDoesNothingWhenCustomerIdIsNotSet(): void
    {
        $this->connection->expects($this->never())->method('fetchRow');

        $model = new DataObject(['tag_id' => 5]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertSame([], $this->whereCalls);
    }

    public function testLoadByTagCustomerBuildsBaseConditionsAndSetsDataFromFetchedRow(): void
    {
        $row = ['tag_relation_id' => 1, 'tag_id' => 5, 'customer_id' => 9, 'product_id' => 10];
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['tag_id' => 5, 'customer_id' => 9])
            ->willReturn($row);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertCount(1, $this->joinCalls);
        $this->assertSame('tag', $this->joinCalls[0][0]);
        $this->assertSame('tag.tag_id = tag_relation.tag_id', $this->joinCalls[0][1]);

        // Only the base tag_id/customer_id conditions - no product_id or store_id narrowing.
        $this->assertCount(2, $this->whereCalls);
        $this->assertSame('tag_relation.tag_id = :tag_id', $this->whereCalls[0][0]);
        $this->assertSame('customer_id = :customer_id', $this->whereCalls[1][0]);

        $this->assertSame($row, $model->getData());
    }

    public function testLoadByTagCustomerSetsEmptyArrayWhenNoRowFound(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertSame([], $model->getData());
    }

    public function testLoadByTagCustomerAddsProductIdConditionWhenProductIdIsSet(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['tag_id' => 5, 'customer_id' => 9, 'product_id' => 10])
            ->willReturn(false);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9, 'product_id' => 10]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertCount(3, $this->whereCalls);
        $this->assertSame('tag_relation.product_id = :product_id', $this->whereCalls[2][0]);
    }

    public function testLoadByTagCustomerDoesNotAddProductIdConditionWhenProductIdIsFalsy(): void
    {
        // getProductId() returning 0/null must not trigger the narrowing branch.
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['tag_id' => 5, 'customer_id' => 9])
            ->willReturn(false);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9, 'product_id' => 0]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertCount(2, $this->whereCalls);
    }

    public function testLoadByTagCustomerAddsStoreIdConditionWheneverStoreIdKeyIsPresentEvenIfZero(): void
    {
        // hasStoreId() checks for key presence, not truthiness, so store_id = 0 still narrows.
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['tag_id' => 5, 'customer_id' => 9, 'sore_id' => 0])
            ->willReturn(false);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9, 'store_id' => 0]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertCount(3, $this->whereCalls);
        $this->assertSame('tag_relation.store_id = :sore_id', $this->whereCalls[2][0]);
    }

    public function testLoadByTagCustomerAddsBothProductAndStoreConditionsWhenBothPresent(): void
    {
        $expectedBind = ['tag_id' => 5, 'customer_id' => 9, 'product_id' => 10, 'sore_id' => 2];
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, $expectedBind)
            ->willReturn(false);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9, 'product_id' => 10, 'store_id' => 2]);
        $this->resourceModel->loadByTagCustomer($model);

        $this->assertCount(4, $this->whereCalls);
    }

    // --------------------------------------------------------------------
    // getProductIds()
    // --------------------------------------------------------------------

    public function testGetProductIdsQueriesByTagIdOnlyByDefault(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5])
            ->willReturn([1, 2, 3]);

        $model = new DataObject(['tag_id' => 5]);
        $result = $this->resourceModel->getProductIds($model);

        $this->assertSame([1, 2, 3], $result);
        $this->assertCount(1, $this->whereCalls);
        $this->assertSame('tag_relation.tag_id=:tag_id', $this->whereCalls[0][0]);
        $this->assertSame([], $this->joinCalls);
    }

    public function testGetProductIdsAddsCustomerIdConditionWhenCustomerIdIsNotNull(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5, 'customer_id' => 9])
            ->willReturn([1]);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => 9]);
        $this->resourceModel->getProductIds($model);

        $this->assertCount(2, $this->whereCalls);
        $this->assertSame('tag_relation.customer_id= :customer_id', $this->whereCalls[1][0]);
    }

    public function testGetProductIdsDoesNotAddCustomerIdConditionWhenCustomerIdIsExplicitlyNull(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5])
            ->willReturn([]);

        $model = new DataObject(['tag_id' => 5, 'customer_id' => null]);
        $this->resourceModel->getProductIds($model);

        $this->assertCount(1, $this->whereCalls);
    }

    public function testGetProductIdsAddsStoreIdConditionWheneverStoreIdKeyIsPresent(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5, 'store_id' => 0])
            ->willReturn([]);

        $model = new DataObject(['tag_id' => 5, 'store_id' => 0]);
        $this->resourceModel->getProductIds($model);

        $this->assertCount(2, $this->whereCalls);
        $this->assertSame('tag_relation.store_id = :store_id', $this->whereCalls[1][0]);
    }

    public function testGetProductIdsJoinsTagTableAndFiltersByStatusWhenStatusFilterIsNotNull(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5, 't_status' => 1])
            ->willReturn([4, 5]);

        $model = new DataObject(['tag_id' => 5, 'status_filter' => 1]);
        $result = $this->resourceModel->getProductIds($model);

        $this->assertSame([4, 5], $result);
        $this->assertCount(1, $this->joinCalls);
        $this->assertSame('tag', $this->joinCalls[0][0]);
        $this->assertSame('tag.tag_id = tag_relation.tag_id', $this->joinCalls[0][1]);
        $this->assertCount(2, $this->whereCalls);
        $this->assertSame('tag.status = :t_status', $this->whereCalls[1][0]);
    }

    public function testGetProductIdsCombinesAllFiltersAndBindsWhenEveryConditionIsPresent(): void
    {
        $expectedBind = [
            'tag_id' => 5,
            'customer_id' => 9,
            'store_id' => 2,
            't_status' => 1,
        ];
        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, $expectedBind)
            ->willReturn([]);

        $model = new DataObject([
            'tag_id' => 5,
            'customer_id' => 9,
            'store_id' => 2,
            'status_filter' => 1,
        ]);
        $this->resourceModel->getProductIds($model);

        $this->assertCount(4, $this->whereCalls);
        $this->assertCount(1, $this->joinCalls);
    }

    // --------------------------------------------------------------------
    // addRelations() - diff-based upsert
    // --------------------------------------------------------------------

    public function testAddRelationsInsertsOnlyProductIdsAbsentFromOldRelationsAndDeletesTheRest(): void
    {
        $this->dateTime->method('gmtDate')->willReturn('2026-10-18 00:00:00');

        $this->connection->expects($this->once())
            ->method('fetchCol')
            ->with($this->select, ['tag_id' => 5, 'store_id' => 2])
            ->willReturn([1, 2, 3]);

        $insertedRows = null;
        $this->connection->expects($this->once())
            ->method('insertMultiple')
            ->willReturnCallback(function ($table, $rows) use (&$insertedRows) {
                $this->assertSame('tag_relation', $table);
                $insertedRows = $rows;
                return 1;
            });

        $deleteCondition = null;
        $this->connection->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function ($table, $condition) use (&$deleteCondition) {
                $this->assertSame('tag_relation', $table);
                $deleteCondition = $condition;
                return 1;
            });

        $model = new DataObject([
            'tag_id' => 5,
            'store_id' => 2,
            'customer_id' => 9,
            'added_product_ids' => [2, 3, 4],
        ]);
        $result = $this->resourceModel->addRelations($model);

        $this->assertSame($this->resourceModel, $result);

        // Only product_id 4 is new (2 and 3 already existed) -> exactly one inserted row.
        $this->assertCount(1, $insertedRows);
        $this->assertSame(
            [
                'tag_id' => 5,
                'store_id' => 2,
                'product_id' => 4,
                'customer_id' => 9,
                'created_at' => '2026-10-18 00:00:00',
            ],
            array_values($insertedRows)[0]
        );

        // Only product_id 1 fell out of the new list -> exactly that id is deleted.
        $this->assertArrayHasKey('product_id IN (?)', $deleteCondition);
        $this->assertSame([1], array_values($deleteCondition['product_id IN (?)']));
        $this->assertSame(2, $deleteCondition['store_id = ?']);
    }

    public function testAddRelationsInsertsAllProductIdsWhenThereAreNoExistingRelations(): void
    {
        $this->dateTime->method('gmtDate')->willReturn('2026-10-18 00:00:00');

        $this->connection->method('fetchCol')->willReturn([]);

        $insertedRows = null;
        $this->connection->expects($this->once())
            ->method('insertMultiple')
            ->willReturnCallback(function ($table, $rows) use (&$insertedRows) {
                $insertedRows = $rows;
                return 1;
            });
        $this->connection->expects($this->never())->method('delete');

        $model = new DataObject([
            'tag_id' => 5,
            'store_id' => 2,
            'customer_id' => null,
            'added_product_ids' => [7, 8],
        ]);
        $this->resourceModel->addRelations($model);

        $this->assertCount(2, $insertedRows);
        $productIds = array_column($insertedRows, 'product_id');
        sort($productIds);
        $this->assertSame([7, 8], $productIds);
    }

    public function testAddRelationsOnlyDeletesWhenNoProductIdsAreAddedButOldRelationsExist(): void
    {
        $this->connection->method('fetchCol')->willReturn([5, 6]);

        $this->connection->expects($this->never())->method('insertMultiple');

        $deleteCondition = null;
        $this->connection->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function ($table, $condition) use (&$deleteCondition) {
                $deleteCondition = $condition;
                return 1;
            });

        $model = new DataObject([
            'tag_id' => 5,
            'store_id' => 2,
            'customer_id' => 9,
            'added_product_ids' => [],
        ]);
        $this->resourceModel->addRelations($model);

        $deletedIds = array_values($deleteCondition['product_id IN (?)']);
        sort($deletedIds);
        $this->assertSame([5, 6], $deletedIds);
    }

    public function testAddRelationsDoesNothingWhenOldAndNewProductIdListsAreIdentical(): void
    {
        $this->connection->method('fetchCol')->willReturn([1, 2]);

        $this->connection->expects($this->never())->method('insertMultiple');
        $this->connection->expects($this->never())->method('delete');

        $model = new DataObject([
            'tag_id' => 5,
            'store_id' => 2,
            'customer_id' => 9,
            'added_product_ids' => [1, 2],
        ]);
        $this->resourceModel->addRelations($model);
    }
}
