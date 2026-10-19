<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Controller\Index;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Model\Tag;
use Magento\Tag\Model\TagManagement;
use Psr\Log\LoggerInterface;

/**
 * Save a customer's tags against a product.
 *
 * Modern equivalent of Mage_Tag_IndexController::saveAction() (routed at tag/index/save). Tokenization,
 * dedupe and the tag/relation state machine are delegated entirely to
 * \Magento\Tag\Model\TagManagement::addTagsToProduct() - this controller only handles HTTP concerns
 * (form key / login checks, loading the product, translating the resulting counter into message-manager
 * messages, and redirecting back to the referrer).
 */
class Save extends Action implements HttpPostActionInterface
{
    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TagManagement
     */
    private $tagManagement;

    /**
     * @var Validator
     */
    private $formKeyValidator;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param TagManagement $tagManagement
     * @param Validator $formKeyValidator
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        TagManagement $tagManagement,
        Validator $formKeyValidator,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->tagManagement = $tagManagement;
        $this->formKeyValidator = $formKeyValidator;
        $this->escaper = $escaper;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Redirect unauthenticated requests to login, resuming here once the customer signs in.
     *
     * Modern equivalent of the customerSession->authenticate($this) guard at the top of
     * Mage_Tag_IndexController::saveAction() - same idiom as
     * \Magento\ProductAlert\Controller\Add::dispatch(). authenticate() stores the current URL as
     * beforeAuthUrl and redirects to the login page; execute() is skipped entirely (via the
     * no-dispatch action flag) so it never runs for an unauthenticated request.
     *
     * @param RequestInterface $request
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function dispatch(RequestInterface $request)
    {
        if (!$this->customerSession->authenticate()) {
            $this->_actionFlag->set('', 'no-dispatch', true);
            if (!$this->customerSession->getBeforeAuthUrl()) {
                $this->customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
            }
        }
        return parent::dispatch($request);
    }

    /**
     * Save tag and relation between tag, customer, product and store
     *
     * @return Redirect
     */
    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        // Authentication is enforced in dispatch() above (customerSession->authenticate()), matching
        // Mage_Tag_IndexController::saveAction()'s customerSession->authenticate($this) guard - by the
        // time execute() runs, the customer is guaranteed to be logged in. Only the form-key (CSRF)
        // check remains here.
        if (!$this->formKeyValidator->validate($this->getRequest())) {
            $resultRedirect->setUrl($this->_redirect->getRefererUrl());
            return $resultRedirect;
        }

        $tagName = (string) $this->getRequest()->getParam('productTagName');
        $productId = (int) $this->getRequest()->getParam('product');

        if (strlen($tagName) && $productId) {
            try {
                $product = $this->productRepository->getById($productId);
                $customerId = (int) $this->customerSession->getCustomerId();
                $storeId = (int) $this->storeManager->getStore()->getId();

                $counter = $this->tagManagement->addTagsToProduct($tagName, (int) $product->getId(), $customerId, $storeId);
                $this->fillMessageBox($counter);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('Unable to save tag(s).'));
            } catch (\Exception $e) {
                $this->logger->critical($e);
                $this->messageManager->addErrorMessage(__('Unable to save tag(s).'));
            }
        }

        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        return $resultRedirect;
    }

    /**
     * Translate the add-tags result counter into success/notice messages.
     *
     * Modern equivalent of Mage_Tag_IndexController::_fillMessageBox(): uses the injected message
     * manager instead of the legacy catalog-session message box, and the injected Escaper instead of
     * the core helper's escapeHtml().
     *
     * @param array $counter tag names bucketed by Tag::ADD_STATUS_* constant, as returned by
     *                       TagManagement::addTagsToProduct()
     * @return void
     */
    private function fillMessageBox(array $counter): void
    {
        if (count($counter[Tag::ADD_STATUS_NEW])) {
            $this->messageManager->addSuccessMessage(
                __('%1 tag(s) have been accepted for moderation.', count($counter[Tag::ADD_STATUS_NEW]))
            );
        }

        if (count($counter[Tag::ADD_STATUS_EXIST])) {
            foreach ($counter[Tag::ADD_STATUS_EXIST] as $tagName) {
                $this->messageManager->addNoticeMessage(
                    __('Tag "%1" has already been added to the product.', $this->escaper->escapeHtml($tagName))
                );
            }
        }

        if (count($counter[Tag::ADD_STATUS_SUCCESS])) {
            foreach ($counter[Tag::ADD_STATUS_SUCCESS] as $tagName) {
                $this->messageManager->addSuccessMessage(
                    __('Tag "%1" has been added to the product.', $this->escaper->escapeHtml($tagName))
                );
            }
        }

        if (count($counter[Tag::ADD_STATUS_REJECTED])) {
            foreach ($counter[Tag::ADD_STATUS_REJECTED] as $tagName) {
                $this->messageManager->addNoticeMessage(
                    __('Tag "%1" has been rejected by administrator.', $this->escaper->escapeHtml($tagName))
                );
            }
        }
    }
}
