<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel\Tag;

use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Data\Collection as BaseCollection;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Tag\Model\ResourceModel\Tag\Collection;
use Magento\Tag\Model\Tag\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Coverage for Magento\Tag\Model\ResourceModel\Tag\Collection's SQL-building logic: flag-gated
 * joins/filters, the addFieldToFilter()/getSelectCountSql() overrides, and the post-load store
 * visibility hydration hook.
 */
class CollectionTest extends TestCase
{
    /** @var MockObject|AdapterInterface */
    private $connectionMock;

    /** @var MockObject|Select */
    private $selectMock;

    /** @var MockObject|AbstractDb */
    private $resourceMock;

    /** @var Collection */
    private $model;

    /** @var ObjectManagerHelper */
    private $objectManager;

    protected function setUp(): void
    {
        $this->selectMock = $this->createMock(Select::class);
        $this->selectMock->method('from')->willReturnSelf();

        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->connectionMock->method('select')->willReturn($this->selectMock);
        $this->connectionMock->method('quoteIdentifier')->willReturnArgument(0);

        $this->resourceMock = $this->createMock(AbstractDb::class);
        $this->resourceMock->method('getConnection')->willReturn($this->connectionMock);
        $this->resourceMock->method('getMainTable')->willReturn('tag');
        $this->resourceMock->method('getTable')->willReturnCallback(
            static fn ($table) => is_array($table) ? implode('_', $table) : $table
        );

        $this->objectManager = new ObjectManagerHelper($this);
        $this->model = $this->objectManager->getObject(
            Collection::class,
            ['resource' => $this->resourceMock]
        );
    }

    private function invokeMethod(object $object, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    // ---------------------------------------------------------------- addPopularity() ----

    public function testAddPopularityJoinsRelationAndSummaryAndGroupsByTagId(): void
    {
        $joinCalls = [];
        $this->selectMock->expects($this->exactly(2))
            ->method('joinLeft')
            ->willReturnCallback(function (...$args) use (&$joinCalls) {
                $joinCalls[] = $args;
                return $this->selectMock;
            });
        $this->selectMock->expects($this->once())->method('group')->with('main_table.tag_id')
            ->willReturnSelf();
        $this->selectMock->expects($this->never())->method('limit');

        $this->model->addPopularity();

        $this->assertSame(['relation' => 'tag_relation'], $joinCalls[0][0]);
        $this->assertSame('main_table.tag_id = relation.tag_id', $joinCalls[0][1]);
        $this->assertSame([], $joinCalls[0][2]);
        $this->assertSame(['summary' => 'tag_summary'], $joinCalls[1][0]);
        $this->assertSame(
            'relation.tag_id = summary.tag_id AND relation.store_id = summary.store_id',
            $joinCalls[1][1]
        );
        $this->assertSame(['popularity'], $joinCalls[1][2]);
        $this->assertTrue($this->model->getFlag('popularity'));
    }

    public function testAddPopularityAppliesLimitWhenProvided(): void
    {
        $this->selectMock->method('joinLeft')->willReturnSelf();
        $this->selectMock->method('group')->willReturnSelf();
        $this->selectMock->expects($this->once())->method('limit')->with(5)->willReturnSelf();

        $this->model->addPopularity(5);
    }

    public function testAddPopularityIsIdempotentWhenFlagAlreadySet(): void
    {
        $this->model->setFlag('popularity', true);
        $this->selectMock->expects($this->never())->method('joinLeft');

        $this->model->addPopularity();
    }

    // ------------------------------------------------------------------ addSummary() ------

    public function testAddSummaryJoinsSummaryTableAndRegistersFilterMap(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('quoteInto')
            ->with(' AND summary.store_id IN(?)', 5)
            ->willReturn(' AND summary.store_id IN(?)');
        $this->selectMock->expects($this->once())
            ->method('joinLeft')
            ->with(
                ['summary' => 'tag_summary'],
                'main_table.tag_id = summary.tag_id AND summary.store_id IN(?)',
                ['store_id', 'popularity', 'customers', 'products']
            )
            ->willReturnSelf();

        $this->model->addSummary(5);

        $this->assertSame('summary.store_id', $this->model->_map['fields']['store_id']);
        $this->assertSame('summary.popularity', $this->model->_map['fields']['popularity']);
        $this->assertSame('summary.customers', $this->model->_map['fields']['customers']);
        $this->assertSame('summary.products', $this->model->_map['fields']['products']);
        $this->assertTrue($this->model->getFlag('summary'));
    }

    public function testAddSummaryIsIdempotentWhenFlagAlreadySet(): void
    {
        $this->model->setFlag('summary', true);
        $this->selectMock->expects($this->never())->method('joinLeft');

        $this->model->addSummary(5);
    }

    // --------------------------------------------------------------- addStoreFilter() -----

    public function testAddStoreFilterAppliesOnlyBaseFilterWhenNoRelationOrPrelationFlags(): void
    {
        $this->selectMock->expects($this->once())
            ->method('joinLeft')
            ->with(['summary_store' => 'tag_summary'], 'main_table.tag_id = summary_store.tag_id')
            ->willReturnSelf();
        $whereCalls = [];
        $this->selectMock->expects($this->once())
            ->method('where')
            ->willReturnCallback(function (...$args) use (&$whereCalls) {
                $whereCalls[] = $args;
                return $this->selectMock;
            });
        $this->selectMock->expects($this->once())->method('group')->with('main_table.tag_id')
            ->willReturnSelf();

        $this->model->addStoreFilter(5);

        $this->assertSame('summary_store.store_id IN (?)', $whereCalls[0][0]);
        $this->assertSame(5, $whereCalls[0][1]);
        $this->assertTrue($this->model->getFlag('store_filter'));
    }

    public function testAddStoreFilterAppliesRelationAndPrelationFiltersWhenFlagsSetAndAllFilterTrue(): void
    {
        $this->model->setFlag('relation', true);
        $this->model->setFlag('prelation', true);
        $this->selectMock->method('joinLeft')->willReturnSelf();
        $this->selectMock->method('group')->willReturnSelf();
        $whereCalls = [];
        $this->selectMock->expects($this->exactly(3))
            ->method('where')
            ->willReturnCallback(function (...$args) use (&$whereCalls) {
                $whereCalls[] = $args;
                return $this->selectMock;
            });

        $this->model->addStoreFilter(5);

        $this->assertSame('summary_store.store_id IN (?)', $whereCalls[0][0]);
        $this->assertSame('relation.store_id IN (?)', $whereCalls[1][0]);
        $this->assertSame(5, $whereCalls[1][1]);
        $this->assertSame('prelation.store_id IN (?)', $whereCalls[2][0]);
        $this->assertSame(5, $whereCalls[2][1]);
    }

    public function testAddStoreFilterSkipsRelationAndPrelationFiltersWhenAllFilterIsFalse(): void
    {
        $this->model->setFlag('relation', true);
        $this->model->setFlag('prelation', true);
        $this->selectMock->method('joinLeft')->willReturnSelf();
        $this->selectMock->method('group')->willReturnSelf();
        $this->selectMock->expects($this->once())->method('where')->willReturnSelf();

        $this->model->addStoreFilter(5, false);
    }

    public function testAddStoreFilterIsIdempotentWhenStoreFilterFlagAlreadySet(): void
    {
        $this->model->setFlag('store_filter', true);
        $this->selectMock->expects($this->never())->method('joinLeft');

        $this->model->addStoreFilter(5);
    }

    // --------------------------------------------------------------- setActiveFilter() -----

    public function testSetActiveFilterAppliesRelationActiveConditionOnlyWithoutPrelationFlag(): void
    {
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('relation.active = ?', Relation::STATUS_ACTIVE)
            ->willReturnSelf();

        $this->model->setActiveFilter();
    }

    public function testSetActiveFilterAppliesBothRelationAndPrelationWhenPrelationFlagSet(): void
    {
        $this->model->setFlag('prelation', true);
        $whereCalls = [];
        $this->selectMock->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(function (...$args) use (&$whereCalls) {
                $whereCalls[] = $args;
                return $this->selectMock;
            });

        $this->model->setActiveFilter();

        $this->assertSame('relation.active = ?', $whereCalls[0][0]);
        $this->assertSame(Relation::STATUS_ACTIVE, $whereCalls[0][1]);
        $this->assertSame('prelation.active = ?', $whereCalls[1][0]);
        $this->assertSame(Relation::STATUS_ACTIVE, $whereCalls[1][1]);
    }

    // --------------------------------------------------------------- addStatusFilter() -----

    public function testAddStatusFilterAppliesStatusCondition(): void
    {
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('main_table.status = ?', 2)
            ->willReturnSelf();

        $this->model->addStatusFilter(2);
    }

    // --------------------------------------------------------------- addProductFilter() ----

    public function testAddProductFilterFiltersRelationProductIdOnlyWithoutPrelationFlag(): void
    {
        $this->connectionMock->method('prepareSqlCondition')
            ->willReturnCallback(static fn ($field, $cond) => $field . '=:cond');
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('relation.product_id=:cond', null, Select::TYPE_CONDITION)
            ->willReturnSelf();

        $this->model->addProductFilter(42);
    }

    public function testAddProductFilterAlsoFiltersPrelationProductIdWhenFlagSet(): void
    {
        $this->model->setFlag('prelation', true);
        $this->connectionMock->method('prepareSqlCondition')
            ->willReturnCallback(static fn ($field, $cond) => $field . '=:cond');
        $whereCalls = [];
        $this->selectMock->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(function (...$args) use (&$whereCalls) {
                $whereCalls[] = $args;
                return $this->selectMock;
            });

        $this->model->addProductFilter(42);

        $this->assertSame('relation.product_id=:cond', $whereCalls[0][0]);
        $this->assertSame('prelation.product_id=:cond', $whereCalls[1][0]);
    }

    // -------------------------------------------------------------- addCustomerFilter() ----

    public function testAddCustomerFilterFiltersRelationCustomerIdOnlyWithoutPrelationFlag(): void
    {
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('relation.customer_id = ?', 7)
            ->willReturnSelf();

        $this->model->addCustomerFilter(7);
    }

    public function testAddCustomerFilterAlsoFiltersPrelationCustomerIdWhenFlagSet(): void
    {
        $this->model->setFlag('prelation', true);
        $whereCalls = [];
        $this->selectMock->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(function (...$args) use (&$whereCalls) {
                $whereCalls[] = $args;
                return $this->selectMock;
            });

        $this->model->addCustomerFilter(7);

        $this->assertSame('relation.customer_id = ?', $whereCalls[0][0]);
        $this->assertSame(7, $whereCalls[0][1]);
        $this->assertSame('prelation.customer_id = ?', $whereCalls[1][0]);
        $this->assertSame(7, $whereCalls[1][1]);
    }

    // ------------------------------------------------------------------ joinRel() ----------

    public function testJoinRelJoinsRelationTableAndSetsFlag(): void
    {
        $this->selectMock->expects($this->once())
            ->method('joinLeft')
            ->with(['relation' => 'tag_relation'], 'main_table.tag_id=relation.tag_id')
            ->willReturnSelf();

        $this->model->joinRel();

        $this->assertTrue($this->model->getFlag('relation'));
    }

    // --------------------------------------------------------- addFieldToFilter() ---------

    public function testAddFieldToFilterUsesHavingForPopularityWhenRelationFlagSet(): void
    {
        $this->model->setFlag('relation', true);
        $this->connectionMock->expects($this->once())
            ->method('prepareSqlCondition')
            ->with('COUNT(relation.tag_relation_id)', ['gt' => 5])
            ->willReturn('COUNT(relation.tag_relation_id) > 5');
        $this->selectMock->expects($this->once())
            ->method('having')
            ->with('COUNT(relation.tag_relation_id) > 5')
            ->willReturnSelf();
        $this->selectMock->expects($this->never())->method('where');

        $this->model->addFieldToFilter('popularity', ['gt' => 5]);
    }

    public static function summaryFieldsProvider(): array
    {
        return [
            ['customers'],
            ['products'],
            ['uses'],
            ['historical_uses'],
            ['popularity'],
        ];
    }

    #[DataProvider('summaryFieldsProvider')]
    public function testAddFieldToFilterUsesWhereForSummaryFieldsWhenSummaryFlagSet(string $field): void
    {
        $this->model->setFlag('summary', true);
        $this->connectionMock->method('prepareSqlCondition')
            ->willReturnCallback(static fn ($f, $c) => $f . '_condition');
        $this->selectMock->expects($this->never())->method('having');
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('summary.' . $field . '_condition')
            ->willReturnSelf();

        $this->model->addFieldToFilter($field, ['eq' => 1]);
    }

    public function testAddFieldToFilterFallsBackToParentForUnflaggedFields(): void
    {
        $this->connectionMock->method('prepareSqlCondition')
            ->willReturnCallback(static fn ($f, $c) => $f . ' = foo');
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('name = foo', null, Select::TYPE_CONDITION)
            ->willReturnSelf();

        $this->model->addFieldToFilter('name', ['eq' => 'foo']);
    }

    // ------------------------------------------------------------ getSelectCountSql() ------

    public function testGetSelectCountSqlResetsColumnsGroupHavingAndSelectsDistinctTagIdCount(): void
    {
        /** @var Collection|MockObject $collectionMock */
        $collectionMock = $this->getMockBuilder(Collection::class)
            ->onlyMethods(['getSelect'])
            ->disableOriginalConstructor()
            ->getMock();
        $selectMock = $this->createMock(Select::class);
        $collectionMock->method('getSelect')->willReturn($selectMock);
        $selectMock->method('getPart')->willReturn([]);

        $resetCalls = [];
        $selectMock->method('reset')->willReturnCallback(function ($part) use (&$resetCalls, $selectMock) {
            $resetCalls[] = $part;
            return $selectMock;
        });
        $columnsCalls = [];
        $selectMock->method('columns')->willReturnCallback(
            function ($cols) use (&$columnsCalls, $selectMock) {
                $columnsCalls[] = $cols;
                return $selectMock;
            }
        );

        $result = $collectionMock->getSelectCountSql();

        $this->assertInstanceOf(Select::class, $result);
        $this->assertContains(Select::COLUMNS, $resetCalls);
        $this->assertContains(Select::GROUP, $resetCalls);
        $this->assertContains(Select::HAVING, $resetCalls);
        $this->assertSame('COUNT(DISTINCT main_table.tag_id)', end($columnsCalls));
    }

    // ------------------------------------------------------------ _addStoresVisibility() ---

    private function markCollectionLoaded(): void
    {
        $ref = new ReflectionProperty(BaseCollection::class, '_isCollectionLoaded');
        $ref->setAccessible(true);
        $ref->setValue($this->model, true);
    }

    public function testAddStoresVisibilityPopulatesStoresForEachItemFromTagSummary(): void
    {
        $this->markCollectionLoaded();
        $item1 = new DataObject();
        $item1->setId(5);
        $item1->setData('tag_id', 5);
        $item2 = new DataObject();
        $item2->setId(7);
        $item2->setData('tag_id', 7);
        $this->model->addItem($item1);
        $this->model->addItem($item2);

        $this->selectMock->method('where')->willReturnSelf();
        $this->connectionMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['store_id' => 1, 'tag_id' => 5],
                ['store_id' => 2, 'tag_id' => 5],
                ['store_id' => 3, 'tag_id' => 7],
            ]);

        $this->invokeMethod($this->model, '_addStoresVisibility');

        $this->assertSame([1, 2], $item1->getData('stores'));
        $this->assertSame([3], $item2->getData('stores'));
    }

    public function testAddStoresVisibilityNeverQueriesConnectionWhenCollectionHasNoItems(): void
    {
        $this->markCollectionLoaded();
        $this->connectionMock->expects($this->never())->method('fetchAll');

        $this->invokeMethod($this->model, '_addStoresVisibility');
    }

    public function testAddStoresVisibilitySetsEmptyStoresArrayWhenTagHasNoSummaryRows(): void
    {
        $this->markCollectionLoaded();
        $item = new DataObject();
        $item->setId(9);
        $item->setData('tag_id', 9);
        $this->model->addItem($item);

        $this->selectMock->method('where')->willReturnSelf();
        $this->connectionMock->method('fetchAll')->willReturn([]);

        $this->invokeMethod($this->model, '_addStoresVisibility');

        $this->assertSame([], $item->getData('stores'));
    }
}
