<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Tag\Helper\Data as HelperData;
use Magento\Tag\Model\Tag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage for the tag-name tokenizer (extractTags()/cleanTags()) ported, byte-for-byte,
 * from the legacy Mage_Tag_Helper_Data regex/explode/trim implementation.
 */
class DataTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManager;

    /**
     * @var HelperData
     */
    private $helper;

    /**
     * @var MockObject|Context
     */
    private $context;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);

        $this->objectManager = new ObjectManagerHelper($this);
        $this->helper = $this->objectManager->getObject(
            HelperData::class,
            [
                'context' => $this->context,
            ]
        );
    }

    /**
     * @dataProvider extractTagsDataProvider
     */
    public function testExtractTags(string $input, array $expected): void
    {
        $this->assertSame($expected, $this->helper->extractTags($input));
    }

    /**
     * Raw tokenization performed by extractTags(), before any trimming/cleaning.
     *
     * @return array
     */
    public static function extractTagsDataProvider(): array
    {
        return [
            'plain whitespace separated tags' => [
                'red blue green',
                ['red', 'blue', 'green'],
            ],
            'single-quoted multi-word phrase preserved as one raw token' => [
                "red 'dark blue' green",
                ['red', "'dark blue'", '', 'green'],
            ],
            'irregular whitespace (tabs, multiple spaces, newline) collapses token separators' => [
                "red\t\tblue   green\ngold",
                ['red', 'blue', 'green', 'gold'],
            ],
            'empty string input yields a single empty token' => [
                '',
                [''],
            ],
            'whitespace-only input yields empty tokens' => [
                "   \t\n  ",
                ['', ''],
            ],
            'duplicate tag names are preserved as duplicates' => [
                'red red blue',
                ['red', 'red', 'blue'],
            ],
            'leading/trailing whitespace around a quoted phrase yields surrounding empty tokens' => [
                "  'red'  ",
                ['', "'red'", '', ''],
            ],
            'a stray empty quote pair becomes its own empty-ish token' => [
                "red '' green",
                ['red', "''", '', 'green'],
            ],
        ];
    }

    /**
     * @dataProvider cleanTagsDataProvider
     */
    public function testCleanTags(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->helper->cleanTags($input));
    }

    /**
     * cleanTags() trims surrounding whitespace/quotes per entry and drops entries that become
     * empty. Note: matching the legacy implementation (which uses unset() rather than
     * array_values()), the keys of surviving entries are NOT re-indexed/compacted - gaps left
     * by dropped entries remain. This is intentional byte-for-byte parity with
     * Mage_Tag_Helper_Data::cleanTags().
     *
     * @return array
     */
    public static function cleanTagsDataProvider(): array
    {
        return [
            'plain tokens are left untouched' => [
                ['red', 'blue', 'green'],
                ['red', 'blue', 'green'],
            ],
            'surrounding whitespace is trimmed per entry' => [
                ['  red', 'blue  ', "\tgreen\n"],
                ['red', 'blue', 'green'],
            ],
            'surrounding single quotes are trimmed per entry' => [
                ["'red'", "'dark blue'", "'green"],
                ['red', 'dark blue', 'green'],
            ],
            'empty string input array yields empty array' => [
                [''],
                [],
            ],
            'all-whitespace tokens yield empty array' => [
                ['', ''],
                [],
            ],
            'duplicate tag names are preserved (no de-duplication)' => [
                ['red', 'red', 'blue'],
                ['red', 'red', 'blue'],
            ],
            'tokens that become empty after trimming are dropped without re-indexing survivors' => [
                ['red', "''", '', 'green'],
                [0 => 'red', 3 => 'green'],
            ],
            'leading/trailing empty tokens around a quoted phrase are dropped, surviving key kept' => [
                ['', "'red'", '', ''],
                [1 => 'red'],
            ],
            'a lone quoted-whitespace token is fully dropped' => [
                ["'  '", ''],
                [],
            ],
        ];
    }

    /**
     * @dataProvider extractThenCleanTagsDataProvider
     */
    public function testExtractTagsThenCleanTags(string $input, array $expected): void
    {
        $this->assertSame($expected, $this->helper->cleanTags($this->helper->extractTags($input)));
    }

    /**
     * End-to-end coverage mirroring real callers, which always invoke
     * cleanTags(extractTags($string)) together (see Mage_Tag_Model_Api::addTag()).
     *
     * @return array
     */
    public static function extractThenCleanTagsDataProvider(): array
    {
        return [
            'plain whitespace-separated tags' => [
                'red blue green',
                ['red', 'blue', 'green'],
            ],
            'single-quoted multi-word phrase preserved as one token' => [
                "red 'dark blue' green",
                [0 => 'red', 1 => 'dark blue', 3 => 'green'],
            ],
            'tabs, multiple spaces and newlines are all collapsed' => [
                "red\t\tblue   green\ngold",
                ['red', 'blue', 'green', 'gold'],
            ],
            'empty string input produces an empty array' => [
                '',
                [],
            ],
            'whitespace-only input produces an empty array' => [
                "   \t\n  ",
                [],
            ],
            'duplicate tag names are preserved as duplicates' => [
                'red red blue',
                ['red', 'red', 'blue'],
            ],
            'leading/trailing quotes and whitespace are trimmed per entry' => [
                "  'red'  ",
                [1 => 'red'],
            ],
            'a stray empty quote pair is dropped entirely' => [
                "red '' green",
                [0 => 'red', 3 => 'green'],
            ],
            'input consisting solely of a quoted blank is fully dropped' => [
                "'  '",
                [],
            ],
            'a lone quoted phrase survives as a single clean tag' => [
                "'only quoted'",
                ['only quoted'],
            ],
        ];
    }

    public function testGetStatusesArray(): void
    {
        $expected = [
            Tag::STATUS_DISABLED => __('Disabled'),
            Tag::STATUS_PENDING => __('Pending'),
            Tag::STATUS_APPROVED => __('Approved'),
        ];

        $this->assertEquals($expected, $this->helper->getStatusesArray());
    }

    public function testGetStatusesOptionsArray(): void
    {
        $expected = [
            [
                'label' => __('Disabled'),
                'value' => Tag::STATUS_DISABLED,
            ],
            [
                'label' => __('Pending'),
                'value' => Tag::STATUS_PENDING,
            ],
            [
                'label' => __('Approved'),
                'value' => Tag::STATUS_APPROVED,
            ],
        ];

        $this->assertEquals($expected, $this->helper->getStatusesOptionsArray());
    }
}
