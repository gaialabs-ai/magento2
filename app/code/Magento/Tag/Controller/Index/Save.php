<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Tag\Controller\Index;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tag\Helper\Data as TagHelper;
use Magento\Tag\Model\Session as TagSession;
use Magento\Tag\Model\Tag as TagModel;
use Magento\Tag\Model\TagFactory;
use Psr\Log\LoggerInterface;

/**
 * Save-tag frontend action.
 *
 * Ported from Mage_Tag_IndexController::saveAction. Accepts POST or GET with productTagName
 * (or `tags` for the modern form) and product; if the current customer is unauthenticated the
 * incoming tag string is stashed on the module Session and the user is redirected to the login
 * page, matching legacy behavior.
 */
class Save implements ActionInterface, HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * Session key used to stash pending tag input across the login redirect.
     */
    public const SESSION_KEY_PENDING = 'TagsFromMassaction';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var TagSession
     */
    private $tagSession;

    /**
     * @var TagHelper
     */
    private $tagHelper;

    /**
     * @var TagFactory
     */
    private $tagFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var FormKeyValidator
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
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param CustomerSession $customerSession
     * @param TagSession $tagSession
     * @param TagHelper $tagHelper
     * @param TagFactory $tagFactory
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param MessageManager $messageManager
     * @param RedirectFactory $redirectFactory
     * @param UrlInterface $urlBuilder
     * @param FormKeyValidator $formKeyValidator
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response,
        CustomerSession $customerSession,
        TagSession $tagSession,
        TagHelper $tagHelper,
        TagFactory $tagFactory,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        MessageManager $messageManager,
        RedirectFactory $redirectFactory,
        UrlInterface $urlBuilder,
        FormKeyValidator $formKeyValidator,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->customerSession = $customerSession;
        $this->tagSession = $tagSession;
        $this->tagHelper = $tagHelper;
        $this->tagFactory = $tagFactory;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->messageManager = $messageManager;
        $this->redirectFactory = $redirectFactory;
        $this->urlBuilder = $urlBuilder;
        $this->formKeyValidator = $formKeyValidator;
        $this->escaper = $escaper;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $tagName = (string) ($this->request->getParam('tags') ?? $this->request->getParam('productTagName') ?? '');
        $productId = (int) $this->request->getParam('product');

        // Enforce form-key validation on POST to match Magento 2 frontend conventions.
        if ($this->request->isPost() && !$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Invalid form key. Please refresh the page.'));
            return $this->redirectBack();
        }

        if (!$this->customerSession->isLoggedIn()) {
            if ($tagName !== '') {
                $this->tagSession->setData(self::SESSION_KEY_PENDING, [
                    'tag' => $tagName,
                    'product' => $productId,
                ]);
            }
            $this->customerSession->setBeforeAuthUrl(
                $this->urlBuilder->getUrl('customer/account/login')
            );
            $redirect = $this->redirectFactory->create();
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        if ($tagName !== '' && $productId > 0) {
            try {
                $product = $this->productRepository->getById($productId);
            } catch (NoSuchEntityException $exception) {
                $this->messageManager->addErrorMessage(__('Unable to save tag(s).'));
                return $this->redirectBack();
            }

            try {
                $customerId = (int) $this->customerSession->getCustomerId();
                $storeId = (int) $this->storeManager->getStore()->getId();
                $counter = [
                    TagModel::ADD_STATUS_NEW => [],
                    TagModel::ADD_STATUS_EXIST => [],
                    TagModel::ADD_STATUS_SUCCESS => [],
                    TagModel::ADD_STATUS_REJECTED => [],
                ];

                $tagNames = $this->tagHelper->cleanTags($this->tagHelper->extractTags($tagName));
                foreach ($tagNames as $singleName) {
                    $tagModel = $this->tagFactory->create();
                    $tagModel->loadByName((string) $singleName);
                    if (!$tagModel->getId()) {
                        $tagModel->setName((string) $singleName)
                            ->setFirstCustomerId($customerId)
                            ->setFirstStoreId($storeId)
                            ->setStatus(TagModel::STATUS_PENDING)
                            ->save();
                    }
                    $relationStatus = $tagModel->saveRelation((int) $product->getId(), $customerId, $storeId);
                    $counter[$relationStatus][] = (string) $singleName;
                }

                $this->fillMessageBox($counter);
            } catch (\Throwable $throwable) {
                $this->logger->error((string) $throwable);
                $this->messageManager->addErrorMessage(__('Unable to save tag(s).'));
            }
        }

        return $this->redirectBack();
    }

    /**
     * Post-execute redirect — HTTP_REFERER when available, otherwise the store base URL.
     *
     * @return Redirect
     */
    private function redirectBack(): Redirect
    {
        $redirect = $this->redirectFactory->create();
        $referer = (string) $this->request->getServer('HTTP_REFERER');
        if ($referer !== '') {
            $redirect->setUrl($referer);
        } else {
            $redirect->setPath('');
        }
        return $redirect;
    }

    /**
     * Populate the session message box based on the per-status counters.
     *
     * @param array<string, string[]> $counter
     * @return void
     */
    private function fillMessageBox(array $counter): void
    {
        if (count($counter[TagModel::ADD_STATUS_NEW]) > 0) {
            $this->messageManager->addSuccessMessage(
                __('%1 tag(s) have been accepted for moderation.', count($counter[TagModel::ADD_STATUS_NEW]))
            );
        }
        foreach ($counter[TagModel::ADD_STATUS_EXIST] as $tagName) {
            $this->messageManager->addNoticeMessage(
                __('Tag "%1" has already been added to the product.', $this->escaper->escapeHtml($tagName))
            );
        }
        foreach ($counter[TagModel::ADD_STATUS_SUCCESS] as $tagName) {
            $this->messageManager->addSuccessMessage(
                __('Tag "%1" has been added to the product.', $this->escaper->escapeHtml($tagName))
            );
        }
        foreach ($counter[TagModel::ADD_STATUS_REJECTED] as $tagName) {
            $this->messageManager->addNoticeMessage(
                __('Tag "%1" has been rejected by administrator.', $this->escaper->escapeHtml($tagName))
            );
        }
    }
}
