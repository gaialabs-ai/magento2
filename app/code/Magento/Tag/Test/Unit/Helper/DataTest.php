<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Helper;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Tag\Helper\Data;
use Magento\Tag\Model\Tag;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Mage_Tag_Helper_Data behaviour
 */
class DataTest extends TestCase
{
    /**
     * @var Data
     */
    private $helper;

    protected function setUp(): void
    {
        $this->helper = (new ObjectManager($this))->getObject(Data::class);
    }

    public function testGetStatusesArray(): void
    {
        $this->assertSame(
            [
                Tag::STATUS_DISABLED => 'Disabled',
                Tag::STATUS_PENDING => 'Pending',
                Tag::STATUS_APPROVED => 'Approved',
            ],
            $this->helper->getStatusesArray()
        );
    }

    public function testGetStatusesOptionsArray(): void
    {
        $this->assertSame(
            [
                ['label' => 'Disabled', 'value' => -1],
                ['label' => 'Pending', 'value' => 0],
                ['label' => 'Approved', 'value' => 1],
            ],
            $this->helper->getStatusesOptionsArray()
        );
    }

    /**
     * @param string $input
     * @param array $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extractTagsDataProvider')]
    public function testExtractTags(string $input, array $expected): void
    {
        $this->assertSame($expected, $this->helper->extractTags($input));
    }

    /**
     * @return array
     */
    public static function extractTagsDataProvider(): array
    {
        return [
            'single' => ['foo', ['foo']],
            'whitespace separated' => ['foo bar', ['foo', 'bar']],
            'quoted phrase' => ["foo 'bar baz'", ['foo', "'bar baz'", '']],
            'tabs and newlines' => ["foo\tbar\nbaz", ['foo', 'bar', 'baz']],
        ];
    }

    public function testCleanTagsStripsQuotesAndEmptyItemsKeepingKeys(): void
    {
        $result = $this->helper->cleanTags(["'bar baz'", '', 'foo', "  ' spaced '  ", "''"]);

        $this->assertSame([0 => 'bar baz', 2 => 'foo', 3 => "' spaced '"], $result);
    }

    public function testExtractThenCleanRoundTrip(): void
    {
        $tags = $this->helper->cleanTags($this->helper->extractTags("summer 'hot sale' winter"));

        $this->assertSame(['summer', 'hot sale', 'winter'], array_values($tags));
    }
}
