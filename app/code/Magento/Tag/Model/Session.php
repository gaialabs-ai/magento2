<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * @license Open Software License (OSL 3.0) / Academic Free License (AFL 3.0), see LICENSE.txt and LICENSE_AFL.txt
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Framework\Session\Generic;

/**
 * Tag session model (replacement of Mage_Tag_Model_Session).
 *
 * The 'tag' storage namespace is configured through the Magento\Tag\Model\Session\Storage
 * virtual type declared in etc/di.xml.
 */
class Session extends Generic
{
}
