<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Block\Product;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the minimal "add a tag to this product" form on the product view page.
 *
 * Modern equivalent of the in-scope portion of Mage_Tag_Block_Product_List: getProductId() and
 * getFormAction() only. Rendering of a product's existing tags (getTags()/getCount()/renderTags()/etc.)
 * is out of scope for this milestone and intentionally not ported here.
 */
class AddTag extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var UrlHelper
     */
    private $urlHelper;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param UrlHelper $urlHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        UrlHelper $urlHelper,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->urlHelper = $urlHelper;
        parent::__construct($context, $data);
    }

    /**
     * Get the id of the product currently registered in the "current_product" registry entry.
     *
     * @return int|bool
     */
    public function getProductId()
    {
        $product = $this->registry->registry('current_product');
        if ($product) {
            return $product->getId();
        }

        return false;
    }

    /**
     * Build the form action url for posting a new tag against the current product.
     *
     * @return string
     */
    public function getFormAction()
    {
        return $this->getUrl('tag/index/save', [
            'product' => $this->getProductId(),
            ActionInterface::PARAM_NAME_URL_ENCODED => $this->urlHelper->getEncodedUrl(),
            '_secure' => $this->_request->isSecure(),
        ]);
    }
}
