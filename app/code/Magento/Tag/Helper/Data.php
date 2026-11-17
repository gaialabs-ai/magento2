<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Tag\Model\Tag;

/**
 * Tag helper: status lists and tag-name parsing/cleaning.
 *
 * Legacy Mage_Tag_Helper_Data defines no configuration paths; none are introduced here.
 */
class Data extends AbstractHelper
{
    /**
     * Retrieve tag statuses as value => label map
     *
     * @return array<int, string>
     */
    public function getStatusesArray(): array
    {
        return [
            Tag::STATUS_DISABLED => (string)__('Disabled'),
            Tag::STATUS_PENDING => (string)__('Pending'),
            Tag::STATUS_APPROVED => (string)__('Approved'),
        ];
    }

    /**
     * Retrieve tag statuses as option array
     *
     * @return array<int, array<string, int|string>>
     */
    public function getStatusesOptionsArray(): array
    {
        return [
            [
                'label' => (string)__('Disabled'),
                'value' => Tag::STATUS_DISABLED,
            ],
            [
                'label' => (string)__('Pending'),
                'value' => Tag::STATUS_PENDING,
            ],
            [
                'label' => (string)__('Approved'),
                'value' => Tag::STATUS_APPROVED,
            ],
        ];
    }

    /**
     * Check tags on the correctness of symbols and split string to array of tags
     *
     * @param string $tagNamesInString
     * @return string[]
     */
    public function extractTags($tagNamesInString): array
    {
        return explode("\n", preg_replace("/(\'(.*?)\')|(\s+)/i", "$1\n", (string)$tagNamesInString));
    }

    /**
     * Clear tag from the separating characters
     *
     * @param array $tagNamesArr
     * @return array
     */
    public function cleanTags(array $tagNamesArr): array
    {
        foreach (array_keys($tagNamesArr) as $key) {
            $tagNamesArr[$key] = trim((string)$tagNamesArr[$key], "'");
            $tagNamesArr[$key] = trim($tagNamesArr[$key]);
            if ($tagNamesArr[$key] == '') {
                unset($tagNamesArr[$key]);
            }
        }

        return $tagNamesArr;
    }
}
