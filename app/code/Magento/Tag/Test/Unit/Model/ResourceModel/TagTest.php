<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\ResourceModel\Tag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TagTest extends TestCase
{
    /**
     * @var Context&MockObject
     */
    private $context;

    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var ResourceConnection&MockObject
     */
    private $resource;

    /**
     * @var StringUtils
     */
    private $stringUtils;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);
        $this->context->method('getResources')->willReturn($this->resource);
        $this->stringUtils = new StringUtils();
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
    }

    public function testLoadByNameEmptyNameSetsEmptyData(): void
    {
        $tagResource = new Tag($this->context, $this->stringUtils, $this->storeManager);

        $model = new DataObject(['name' => 'wat']);
        $tagResource->loadByName($model, '');
        $this->assertSame([], $model->getData());
    }

    public function testLoadByNameQueriesConnectionAndPopulatesModel(): void
    {
        $tagResource = new Tag($this->context, $this->stringUtils, $this->storeManager);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn([
            'tag_id' => 3,
            'name' => 'foo',
            'status' => 1,
        ]);

        $model = new DataObject();
        $tagResource->loadByName($model, 'foo');
        $this->assertSame(3, $model->getData('tag_id'));
        $this->assertSame('foo', $model->getData('name'));
    }

    public function testLoadByNameHandlesMissingRow(): void
    {
        $tagResource = new Tag($this->context, $this->stringUtils, $this->storeManager);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        $model = new DataObject();
        $tagResource->loadByName($model, 'missing');
        $this->assertSame([], $model->getData());
    }

    public function testDecrementProductsWithEmptyTagIdsReturnsZero(): void
    {
        $tagResource = new Tag($this->context, $this->stringUtils, $this->storeManager);
        $this->assertSame(0, $tagResource->decrementProducts([]));
    }
}
