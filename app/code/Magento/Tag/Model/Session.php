<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Model;

use Magento\Framework\Session\SessionManager;

/**
 * Module-scoped session — stashes unsaved tag input across the login-redirect flow.
 *
 * @api
 */
class Session extends SessionManager
{
}
