<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Tag\Model\Indexer\Summary as SummaryIndexer;
use Magento\Tag\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Tag\Model\ResourceModel\Popular\Collection as PopularCollection;
use Magento\Tag\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Tag\Model\ResourceModel\Tag\Collection as TagCollection;
use Magento\Tag\Model\ResourceModel\Tag\Relation\Collection as RelationCollection;
use Magento\Tag\Model\Tag\Relation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Saves a tag, assigns it to a product and a customer (tag_relation) and reads it back through the collections.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class TagTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testSaveTagAssignToProductAndCustomerAndReadBackViaCollections(): void
    {
        $productId = (int)$this->objectManager->get(ProductRepositoryInterface::class)->get('simple')->getId();
        $customerId = (int)$this->objectManager->get(CustomerRepositoryInterface::class)
            ->get('customer@example.com')->getId();

        /** @var Tag $tag */
        $tag = $this->objectManager->create(Tag::class);
        $tag->setName('summer sale')
            ->setStatus(Tag::STATUS_APPROVED)
            ->setFirstCustomerId($customerId)
            ->setFirstStoreId(self::STORE_ID)
            ->save();
        $this->assertNotEmpty($tag->getId());

        $this->assertSame(Tag::ADD_STATUS_SUCCESS, $tag->saveRelation($productId, $customerId, self::STORE_ID));
        // second assignment of the very same relation must not create a duplicate
        $this->assertSame(Tag::ADD_STATUS_EXIST, $tag->saveRelation($productId, $customerId, self::STORE_ID));

        // tag is loadable by name
        /** @var Tag $loaded */
        $loaded = $this->objectManager->create(Tag::class)->loadByName('summer sale');
        $this->assertEquals($tag->getId(), $loaded->getId());
        $this->assertSame('summer sale', $loaded->getName());
        $this->assertEquals(Tag::STATUS_APPROVED, $loaded->getStatus());

        // relation model / collection
        /** @var RelationCollection $relations */
        $relations = $this->objectManager->create(RelationCollection::class);
        $relations->addTagFilter((int)$tag->getId())->addActiveFilter();
        $this->assertSame(1, $relations->getSize());
        /** @var Relation $relation */
        $relation = $relations->getFirstItem();
        $this->assertEquals($productId, $relation->getProductId());
        $this->assertEquals($customerId, $relation->getCustomerId());
        $this->assertEquals(self::STORE_ID, $relation->getStoreId());

        $this->assertEquals([$productId], $loaded->setStoreId(self::STORE_ID)->getRelatedProductIds());

        // product collection
        /** @var ProductCollection $products */
        $products = $loaded->getEntityCollection();
        $products->addTagFilter((int)$tag->getId())->addCustomerFilter($customerId)->addStoreFilter(self::STORE_ID);
        $this->assertCount(1, $products);
        $this->assertEquals($productId, $products->getFirstItem()->getId());
        $this->assertSame('summer sale', $products->getFirstItem()->getTagName());

        // customer collection
        /** @var CustomerCollection $customers */
        $customers = $loaded->getCustomerCollection();
        $customers->addTagFilter((int)$tag->getId())->addProductFilter($productId);
        $this->assertCount(1, $customers);
        $this->assertEquals('customer@example.com', $customers->getFirstItem()->getEmail());

        // tag collection joined with relation
        /** @var TagCollection $tags */
        $tags = $this->objectManager->create(TagCollection::class);
        $tags->joinRel()->addProductFilter($productId)->addTagGroup()->setActiveFilter();
        $this->assertSame(['summer sale'], $tags->getColumnValues('name'));

        // summary indexer populates tag_summary used by the popular collection
        $this->objectManager->get(SummaryIndexer::class)->executeRow((int)$tag->getId());
        /** @var PopularCollection $popular */
        $popular = $loaded->getPopularCollection();
        $popular->joinFields(self::STORE_ID)->load();
        $this->assertCount(1, $popular);
        $this->assertEquals(1, $popular->getFirstItem()->getData('popularity'));

        // full reindex keeps the data
        $this->objectManager->get(SummaryIndexer::class)->executeFull();
        $popularAfterFull = $loaded->getPopularCollection()->joinFields(0);
        $this->assertCount(1, $popularAfterFull);

        // tag visible in store after aggregation
        $reloaded = $this->objectManager->create(Tag::class)->load($tag->getId());
        $this->assertTrue($reloaded->isAvailableInStore(self::STORE_ID));

        // admin only deletion is protected unless secure area is set
        $this->objectManager->get(Registry::class)->unregister('isSecureArea');
        $this->objectManager->get(Registry::class)->register('isSecureArea', true);
        $reloaded->delete();
        $this->objectManager->get(Registry::class)->unregister('isSecureArea');
        $this->assertNull($this->objectManager->create(Tag::class)->load($tag->getId())->getId());
    }

    public function testApprovedTagTakesOverPendingTagWithSameName(): void
    {
        /** @var Tag $pending */
        $pending = $this->objectManager->create(Tag::class);
        $pending->setName('duplicate')->setStatus(Tag::STATUS_PENDING)->save();

        /** @var Tag $approved */
        $approved = $this->objectManager->create(Tag::class);
        $approved->setName('duplicate')->setStatus(Tag::STATUS_APPROVED)->save();

        $this->assertEquals($pending->getId(), $approved->getId());
        $this->assertEquals(
            Tag::STATUS_APPROVED,
            $this->objectManager->create(Tag::class)->loadByName('duplicate')->getStatus()
        );
    }
}
