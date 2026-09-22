<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Controller\Index;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * End-to-end integration test for the /tag/index/save controller.
 *
 * Exercises the full Foundation-milestone stack:
 *   HTTP request  → Save controller → Helper\Data extract/clean →
 *   TagFactory::loadByName → Tag::saveRelation →
 *   Model/ResourceModel/{Tag,Tag/Relation} → declarative-schema tables
 *
 * Uses the standard Magento integration testing framework fixtures for a customer
 * and a simple product; asserts that a tag row and its relation land in the DB.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class SaveTest extends AbstractController
{
    /** @var CustomerSession */
    private $customerSession;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var FormKey */
    private $formKey;

    /** @var ResourceConnection */
    private $resourceConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerSession = $this->_objectManager->get(CustomerSession::class);
        $this->productRepository = $this->_objectManager->get(ProductRepositoryInterface::class);
        $this->formKey = $this->_objectManager->get(FormKey::class);
        $this->resourceConnection = $this->_objectManager->get(ResourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->customerSession->logout();
        parent::tearDown();
    }

    /**
     * When the customer is not logged in, the controller must stash the input and
     * redirect to customer/account/login without touching the DB.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testSaveWhenAnonymousStashesAndRedirectsToLogin(): void
    {
        $product = $this->productRepository->get('simple');

        $this->getRequest()
            ->setMethod(HttpRequest::METHOD_POST)
            ->setPostValue([
                'tags' => 'foo bar',
                'product' => (int) $product->getId(),
                'form_key' => $this->formKey->getFormKey(),
            ]);

        $this->dispatch('tag/index/save');

        $this->assertRedirect($this->stringContains('customer/account/login'));

        $connection = $this->resourceConnection->getConnection();
        $tagTable = $this->resourceConnection->getTableName('tag');
        $this->assertSame(
            0,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM {$tagTable} WHERE name IN ('foo','bar')"),
            'Anonymous save must not persist tag rows.'
        );
    }

    /**
     * When the customer IS logged in the controller must create the tag rows
     * and populate tag_relation for the (tag, product, customer, store) tuple.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testSaveAuthenticatedPersistsTagAndRelation(): void
    {
        $product = $this->productRepository->get('simple');
        $customer = $this->_objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->get('customer@example.com');
        $this->customerSession->loginById((int) $customer->getId());

        $this->getRequest()
            ->setMethod(HttpRequest::METHOD_POST)
            ->setPostValue([
                'tags' => 'sample-tag',
                'product' => (int) $product->getId(),
                'form_key' => $this->formKey->getFormKey(),
            ]);

        $this->dispatch('tag/index/save');

        // Post-save redirect is back to the (empty) HTTP_REFERER — anywhere in-app.
        $this->assertNotEmpty($this->getResponse()->getHeader('Location'));

        $connection = $this->resourceConnection->getConnection();
        $tagTable = $this->resourceConnection->getTableName('tag');
        $relationTable = $this->resourceConnection->getTableName('tag_relation');

        $tagId = (int) $connection->fetchOne(
            "SELECT tag_id FROM {$tagTable} WHERE name = ?",
            ['sample-tag']
        );
        $this->assertGreaterThan(0, $tagId, 'Tag row was not persisted.');

        $relationCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$relationTable} WHERE tag_id = ? AND product_id = ? AND customer_id = ?",
            [$tagId, (int) $product->getId(), (int) $customer->getId()]
        );
        $this->assertSame(1, $relationCount, 'tag_relation row was not persisted.');
    }
}
