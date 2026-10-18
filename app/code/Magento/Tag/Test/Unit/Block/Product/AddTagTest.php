<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Block\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Tag\Block\Product\AddTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddTagTest extends TestCase
{
    private const PRODUCT_ID = 42;
    private const ENCODED_URL = 'aHR0cDovL2V4YW1wbGUuY29tLw%2C%2C';

    private AddTag $block;

    private Registry|MockObject $registry;

    private Product|MockObject $product;

    private UrlHelper|MockObject $urlHelper;

    private UrlInterface|MockObject $urlBuilder;

    private RequestInterface|MockObject $requestMock;

    private Context|MockObject $context;

    protected function setUp(): void
    {
        $this->product = $this->createPartialMock(Product::class, ['getId']);
        $this->registry = $this->createPartialMock(Registry::class, ['registry']);

        $this->urlHelper = $this->createMock(UrlHelper::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->requestMock = $this->createMock(RequestInterface::class);

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->requestMock);
        $this->context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $objectManager = new ObjectManagerHelper($this);
        $this->block = $objectManager->getObject(
            AddTag::class,
            [
                'context' => $this->context,
                'registry' => $this->registry,
                'urlHelper' => $this->urlHelper,
            ]
        );
    }

    public function testGetProductIdReturnsIdWhenCurrentProductIsRegistered(): void
    {
        $this->registry->expects($this->once())
            ->method('registry')
            ->with('current_product')
            ->willReturn($this->product);
        $this->product->method('getId')->willReturn(self::PRODUCT_ID);

        $this->assertSame(self::PRODUCT_ID, $this->block->getProductId());
    }

    public function testGetProductIdReturnsFalseWhenNoCurrentProductIsRegistered(): void
    {
        $this->registry->expects($this->once())
            ->method('registry')
            ->with('current_product')
            ->willReturn(null);

        $this->assertFalse($this->block->getProductId());
    }

    #[DataProvider('isSecureDataProvider')]
    public function testGetFormActionBuildsUrlWithProductEncodedUrlAndSecureFlag(bool $isSecure): void
    {
        $this->registry->method('registry')->with('current_product')->willReturn($this->product);
        $this->product->method('getId')->willReturn(self::PRODUCT_ID);
        $this->urlHelper->method('getEncodedUrl')->willReturn(self::ENCODED_URL);
        $this->requestMock->method('isSecure')->willReturn($isSecure);

        $expectedUrl = 'https://example.com/tag/index/save/';
        $this->urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with(
                'tag/index/save',
                [
                    'product' => self::PRODUCT_ID,
                    ActionInterface::PARAM_NAME_URL_ENCODED => self::ENCODED_URL,
                    '_secure' => $isSecure,
                ]
            )
            ->willReturn($expectedUrl);

        $this->assertSame($expectedUrl, $this->block->getFormAction());
    }

    public static function isSecureDataProvider(): array
    {
        return [
            'secure request' => [true],
            'non-secure request' => [false],
        ];
    }

    public function testGetFormActionUsesFalseProductParamWhenNoCurrentProductIsRegistered(): void
    {
        $this->registry->method('registry')->with('current_product')->willReturn(null);
        $this->urlHelper->method('getEncodedUrl')->willReturn(self::ENCODED_URL);
        $this->requestMock->method('isSecure')->willReturn(false);

        $expectedUrl = 'http://example.com/tag/index/save/';
        $this->urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with(
                'tag/index/save',
                [
                    'product' => false,
                    ActionInterface::PARAM_NAME_URL_ENCODED => self::ENCODED_URL,
                    '_secure' => false,
                ]
            )
            ->willReturn($expectedUrl);

        $this->assertSame($expectedUrl, $this->block->getFormAction());
    }
}
