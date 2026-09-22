<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Tag\Helper\Data;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    /**
     * @var Data
     */
    private Data $helper;

    protected function setUp(): void
    {
        /** @var Context&MockObject $context */
        $context = $this->createMock(Context::class);
        $this->helper = new Data($context);
    }

    public function testExtractTagsSplitsOnWhitespace(): void
    {
        $result = $this->helper->extractTags('red green blue');
        $result = $this->helper->cleanTags($result);
        $this->assertSame(['red', 'green', 'blue'], array_values($result));
    }

    public function testExtractTagsKeepsQuotedPhrases(): void
    {
        $result = $this->helper->extractTags("red 'baby blue' green");
        $result = $this->helper->cleanTags($result);
        $this->assertSame(['red', 'baby blue', 'green'], array_values($result));
    }

    public function testCleanTagsStripsSurroundingWhitespaceAndQuotes(): void
    {
        $result = $this->helper->cleanTags(["  hello  ", "'world'", "''", "  "]);
        $this->assertSame([0 => 'hello', 1 => 'world'], $result);
    }

    public function testCleanTagsDropsEmptyEntries(): void
    {
        $result = $this->helper->cleanTags(['', 'foo', "   ", 'bar']);
        $this->assertSame([1 => 'foo', 3 => 'bar'], $result);
    }

    public function testStatusesArrayHasThreeEntries(): void
    {
        $statuses = $this->helper->getStatusesArray();
        $this->assertCount(3, $statuses);
        $this->assertArrayHasKey(-1, $statuses);
        $this->assertArrayHasKey(0, $statuses);
        $this->assertArrayHasKey(1, $statuses);
    }

    public function testStatusesOptionsArrayShape(): void
    {
        $options = $this->helper->getStatusesOptionsArray();
        $this->assertCount(3, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('value', $option);
        }
    }
}
