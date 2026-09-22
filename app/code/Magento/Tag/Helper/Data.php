<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Tag\Model\Tag;

/**
 * Tag string helpers (extract, clean) plus status labels.
 *
 * Ported from Mage_Tag_Helper_Data; consumed by
 * {@see \Magento\Tag\Controller\Index\Save} and by the admin save-tag flow in M4.
 */
class Data extends AbstractHelper
{
    /**
     * Status id -> human label.
     *
     * @return array<int, \Magento\Framework\Phrase>
     */
    public function getStatusesArray(): array
    {
        return [
            Tag::STATUS_DISABLED => __('Disabled'),
            Tag::STATUS_PENDING => __('Pending'),
            Tag::STATUS_APPROVED => __('Approved'),
        ];
    }

    /**
     * Same as getStatusesArray but shaped for UI option arrays.
     *
     * @return array<int, array{label: \Magento\Framework\Phrase, value: int}>
     */
    public function getStatusesOptionsArray(): array
    {
        return [
            ['label' => __('Disabled'), 'value' => Tag::STATUS_DISABLED],
            ['label' => __('Pending'), 'value' => Tag::STATUS_PENDING],
            ['label' => __('Approved'), 'value' => Tag::STATUS_APPROVED],
        ];
    }

    /**
     * Split a raw tag string into tokens, keeping quoted phrases intact.
     *
     * @param string $tagNamesInString
     * @return string[]
     */
    public function extractTags(string $tagNamesInString): array
    {
        return explode("\n", preg_replace("/(\'(.*?)\')|(\s+)/i", "$1\n", $tagNamesInString));
    }

    /**
     * Strip surrounding quotes/whitespace and drop empties.
     *
     * @param string[] $tagNamesArr
     * @return string[]
     */
    public function cleanTags(array $tagNamesArr): array
    {
        foreach (array_keys($tagNamesArr) as $key) {
            $tagNamesArr[$key] = trim((string) $tagNamesArr[$key], "'");
            $tagNamesArr[$key] = trim($tagNamesArr[$key]);
            if ($tagNamesArr[$key] === '') {
                unset($tagNamesArr[$key]);
            }
        }
        return $tagNamesArr;
    }
}
