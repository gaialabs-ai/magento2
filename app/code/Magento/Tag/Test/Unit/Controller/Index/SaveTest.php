<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Controller\Index;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Controller\Index\Save;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\TagManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the storefront "add tag to product" POST handler, ported from
 * Mage_Tag_IndexController::saveAction().
 *
 * Authentication is enforced in Save::dispatch() (customerSession->authenticate(), see review
 * issue #1) before execute() ever runs, so execute() itself is tested here assuming an
 * authenticated customer; only the form-key (CSRF) check remains as a guard inside execute().
 */
class SaveTest extends TestCase
{
    private const TAG_NAME = 'red';
    private const PRODUCT_ID = 123;
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;
    private const REFERER_URL = 'https://example.com/catalog/product/view/id/123';

    /** @var MockObject|RequestInterface */
    private $request;

    /** @var MockObject|RedirectInterface */
    private $redirect;

    /** @var MockObject|Validator */
    private $formKeyValidator;

    /** @var MockObject|Session */
    private $customerSession;

    /** @var MockObject|ProductRepositoryInterface */
    private $productRepository;

    /** @var MockObject|StoreManagerInterface */
    private $storeManager;

    /** @var MockObject|TagManagement */
    private $tagManagement;

    /** @var MockObject|Escaper */
    private $escaper;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|MessageManagerInterface */
    private $messageManager;

    /** @var MockObject|ResultFactory */
    private $resultFactory;

    /** @var MockObject|Redirect */
    private $resultRedirect;

    /** @var Save */
    private $model;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->redirect = $this->createMock(RedirectInterface::class);
        $this->formKeyValidator = $this->createPartialMock(Validator::class, ['validate']);
        $this->customerSession = $this->createPartialMock(Session::class, ['getCustomerId']);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->tagManagement = $this->createMock(TagManagement::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);

        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->resultRedirect = $this->createMock(Redirect::class);
        // ResultFactory::create($type, array $arguments = []) - PHPUnit 12's generated double
        // invokes with() matchers using the full declared parameter list, so the second arg must
        // be asserted even though Save::execute() only passes the first (see PostTest.php precedent).
        $this->resultFactory->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_REDIRECT, [])
            ->willReturn($this->resultRedirect);

        $this->redirect->method('getRefererUrl')->willReturn(self::REFERER_URL);

        $objectManager = new ObjectManagerHelper($this);
        $context = $objectManager->getObject(
            Context::class,
            [
                'request' => $this->request,
                'redirect' => $this->redirect,
                'resultFactory' => $this->resultFactory,
                'messageManager' => $this->messageManager,
            ]
        );

        $this->model = $objectManager->getObject(
            Save::class,
            [
                'context' => $context,
                'customerSession' => $this->customerSession,
                'productRepository' => $this->productRepository,
                'storeManager' => $this->storeManager,
                'tagManagement' => $this->tagManagement,
                'formKeyValidator' => $this->formKeyValidator,
                'escaper' => $this->escaper,
                'logger' => $this->logger,
            ]
        );
    }

    /**
     * Stub getParam() for 'productTagName' / 'product' keyed only on the first positional
     * argument, since RequestInterface::getParam($key, $defaultValue = null) would otherwise
     * require matching PHPUnit's padded-with-defaults full argument list (see setUp() note).
     */
    private function stubRequestParams(string $tagName, int $productId): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn ($name) => match ($name) {
                'productTagName' => $tagName,
                'product' => $productId,
                default => null,
            }
        );
    }

    public function testExecuteRedirectsWithoutProcessingWhenFormKeyIsInvalid(): void
    {
        $this->formKeyValidator->expects($this->once())
            ->method('validate')
            ->with($this->request)
            ->willReturn(false);
        $this->productRepository->expects($this->never())->method('getById');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());
    }

    public function testExecuteDoesNothingWhenTagNameIsEmpty(): void
    {
        $this->formKeyValidator->method('validate')->willReturn(true);
        $this->stubRequestParams('', self::PRODUCT_ID);

        $this->productRepository->expects($this->never())->method('getById');
        $this->tagManagement->expects($this->never())->method('addTagsToProduct');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());
    }

    public function testExecuteDoesNothingWhenProductIdIsZero(): void
    {
        $this->formKeyValidator->method('validate')->willReturn(true);
        $this->stubRequestParams(self::TAG_NAME, 0);

        $this->productRepository->expects($this->never())->method('getById');
        $this->tagManagement->expects($this->never())->method('addTagsToProduct');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());
    }

    public function testExecuteAddsErrorMessageWhenProductDoesNotExist(): void
    {
        $this->formKeyValidator->method('validate')->willReturn(true);
        $this->stubRequestParams(self::TAG_NAME, self::PRODUCT_ID);

        $this->productRepository->expects($this->once())
            ->method('getById')
            ->with(self::PRODUCT_ID)
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $this->customerSession->expects($this->never())->method('getCustomerId');
        $this->storeManager->expects($this->never())->method('getStore');
        $this->tagManagement->expects($this->never())->method('addTagsToProduct');
        $this->logger->expects($this->never())->method('critical');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('Unable to save tag(s).'))
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());
    }

    /**
     * The branch under test: Save::execute()'s generic `catch (\Exception $e)` (lines 134-137),
     * reachable whenever TagManagement::addTagsToProduct() (or any collaborator invoked inside the
     * try block) throws something other than NoSuchEntityException - e.g. a persistence failure
     * surfaced by Tag::save()/saveRelation(). Not exercised by any existing functional/e2e test
     * because those cannot easily force an unexpected exception from the real stack. This is the
     * specific gap review issue #8 flags.
     */
    public function testExecuteLogsCriticalAndAddsErrorMessageOnUnexpectedException(): void
    {
        $this->formKeyValidator->method('validate')->willReturn(true);
        $this->stubRequestParams(self::TAG_NAME, self::PRODUCT_ID);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $this->productRepository->expects($this->once())
            ->method('getById')
            ->with(self::PRODUCT_ID)
            ->willReturn($product);

        $this->customerSession->expects($this->once())->method('getCustomerId')->willReturn(self::CUSTOMER_ID);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager->expects($this->once())->method('getStore')->willReturn($store);

        $unexpected = new \RuntimeException('db is on fire');
        $this->tagManagement->expects($this->once())
            ->method('addTagsToProduct')
            ->with(self::TAG_NAME, self::PRODUCT_ID, self::CUSTOMER_ID, self::STORE_ID)
            ->willThrowException($unexpected);

        // Capture positionally rather than asserting a full expected array (per the
        // positional-capture convention) - we only care that the exact exception instance
        // thrown inside the try block is the one passed to logger->critical().
        $loggedExceptions = [];
        $this->logger->expects($this->once())
            ->method('critical')
            ->willReturnCallback(function ($exception) use (&$loggedExceptions) {
                $loggedExceptions[] = $exception;
                return null;
            });

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('Unable to save tag(s).'))
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());

        $this->assertCount(1, $loggedExceptions);
        $this->assertSame($unexpected, $loggedExceptions[0]);
    }

    public function testExecuteFillsMessageBoxForEachCounterBucket(): void
    {
        $this->formKeyValidator->method('validate')->willReturn(true);
        $this->stubRequestParams(self::TAG_NAME, self::PRODUCT_ID);

        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $this->productRepository->method('getById')->willReturn($product);
        $this->customerSession->method('getCustomerId')->willReturn(self::CUSTOMER_ID);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager->method('getStore')->willReturn($store);

        $counter = [
            Tag::ADD_STATUS_NEW => ['newtag'],
            Tag::ADD_STATUS_EXIST => ['existingtag'],
            Tag::ADD_STATUS_SUCCESS => ['successtag'],
            Tag::ADD_STATUS_REJECTED => ['rejectedtag'],
        ];
        $this->tagManagement->method('addTagsToProduct')->willReturn($counter);

        $this->escaper->method('escapeHtml')->willReturnArgument(0);

        // One ADD_STATUS_NEW success message + one ADD_STATUS_SUCCESS success message.
        $this->messageManager->expects($this->exactly(2))->method('addSuccessMessage');
        // One ADD_STATUS_EXIST notice + one ADD_STATUS_REJECTED notice.
        $this->messageManager->expects($this->exactly(2))->method('addNoticeMessage');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with(self::REFERER_URL)->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->model->execute());
    }
}
