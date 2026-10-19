<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Tag\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Tag\Model\Tag;

/**
 * Tag module default helper
 */
class Data extends AbstractHelper
{
    /**
     * Get tag statuses as a [status => label] map
     *
     * @return array
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
     * Get tag statuses as an options array suitable for form select sources
     *
     * @return array
     */
    public function getStatusesOptionsArray(): array
    {
        return [
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
    }

    /**
     * Check tags on the correctness of symbols and split string to array of tags
     *
     * Whitespace-separated tokens are split apart, while single-quoted phrases
     * (which may contain internal whitespace) are preserved as a single token.
     *
     * @param string $tagNamesInString
     * @return array
     */
    public function extractTags(string $tagNamesInString): array
    {
        return explode("\n", preg_replace("/(\'(.*?)\')|(\s+)/i", "$1\n", $tagNamesInString));
    }

    /**
     * Clear tag from the separating characters
     *
     * Trims surrounding whitespace and single quotes from every entry. Entries
     * that become empty after trimming are removed from the array; the keys of
     * the remaining entries are left untouched (not re-indexed), matching the
     * legacy Mage_Tag_Helper_Data behavior.
     *
     * @param array $tagNamesArr
     * @return array
     */
    public function cleanTags(array $tagNamesArr): array
    {
        foreach (array_keys($tagNamesArr) as $key) {
            $tagNamesArr[$key] = trim($tagNamesArr[$key], "'");
            $tagNamesArr[$key] = trim($tagNamesArr[$key]);
            if ($tagNamesArr[$key] == '') {
                unset($tagNamesArr[$key]);
            }
        }

        return $tagNamesArr;
    }
}
