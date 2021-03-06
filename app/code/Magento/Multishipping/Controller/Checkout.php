<?php
/**
 * @copyright Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 */
namespace Magento\Multishipping\Controller;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Multishipping checkout controller
 */
class Checkout extends \Magento\Checkout\Controller\Action implements
    \Magento\Checkout\Controller\Express\RedirectLoginInterface
{
    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param AccountManagementInterface $accountManagement
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $accountManagement
    ) {
        parent::__construct($context, $customerSession, $customerRepository, $accountManagement);
    }

    /**
     * Retrieve checkout model
     *
     * @return \Magento\Multishipping\Model\Checkout\Type\Multishipping
     */
    protected function _getCheckout()
    {
        return $this->_objectManager->get('Magento\Multishipping\Model\Checkout\Type\Multishipping');
    }

    /**
     * Retrieve checkout state model
     *
     * @return \Magento\Multishipping\Model\Checkout\Type\Multishipping\State
     */
    protected function _getState()
    {
        return $this->_objectManager->get('Magento\Multishipping\Model\Checkout\Type\Multishipping\State');
    }

    /**
     * Retrieve checkout url helper
     *
     * @return \Magento\Multishipping\Helper\Url
     */
    protected function _getHelper()
    {
        return $this->_objectManager->get('Magento\Multishipping\Helper\Url');
    }

    /**
     * Retrieve checkout session
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckoutSession()
    {
        return $this->_objectManager->get('Magento\Checkout\Model\Session');
    }

    /**
     * Dispatch request
     *
     * @param RequestInterface $request
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function dispatch(RequestInterface $request)
    {
        $this->_request = $request;
        if ($this->_actionFlag->get('', 'redirectLogin')) {
            return parent::dispatch($request);
        }

        $action = $request->getActionName();

        $checkoutSessionQuote = $this->_getCheckoutSession()->getQuote();
        /**
         * Catch index action call to set some flags before checkout/type_multishipping model initialization
         */
        if ($action == 'index') {
            $checkoutSessionQuote->setIsMultiShipping(true);
            $this->_getCheckoutSession()->setCheckoutState(\Magento\Checkout\Model\Session::CHECKOUT_STATE_BEGIN);
        } elseif (!$checkoutSessionQuote->getIsMultiShipping() && !in_array(
            $action,
            ['login', 'register', 'success']
        )
        ) {
            $this->_redirect('*/*/index');
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            return parent::dispatch($request);
        }

        if (!in_array($action, ['login', 'register'])) {
            $customerSession = $this->_objectManager->get('Magento\Customer\Model\Session');
            if (!$customerSession->authenticate($this, $this->_getHelper()->getMSLoginUrl())) {
                $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            }

            if (!$this->_objectManager->get('Magento\Multishipping\Helper\Data')->isMultishippingCheckoutAvailable()) {
                $error = $this->_getCheckout()->getMinimumAmountError();
                $this->messageManager->addError($error);
                $this->getResponse()->setRedirect($this->_getHelper()->getCartUrl());
                $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
                return parent::dispatch($request);
            }
        }

        if (!$this->_preDispatchValidateCustomer()) {
            return $this->getResponse();
        }

        if ($this->_getCheckoutSession()->getCartWasUpdated(
            true
        ) && !in_array(
            $action,
            ['index', 'login', 'register', 'addresses', 'success']
        )
        ) {
            $this->getResponse()->setRedirect($this->_getHelper()->getCartUrl());
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
            return parent::dispatch($request);
        }

        if ($action == 'success' && $this->_getCheckout()->getCheckoutSession()->getDisplaySuccess(true)) {
            return parent::dispatch($request);
        }

        $quote = $this->_getCheckout()->getQuote();
        if (!$quote->hasItems() || $quote->getHasError() || $quote->isVirtual()) {
            $this->getResponse()->setRedirect($this->_getHelper()->getCartUrl());
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }

        return parent::dispatch($request);
    }

    /**
     * Validate minimum amount
     *
     * @return bool
     */
    protected function _validateMinimumAmount()
    {
        if (!$this->_getCheckout()->validateMinimumAmount()) {
            $error = $this->_getCheckout()->getMinimumAmountError();
            $this->messageManager->addError($error);
            $this->_forward('backToAddresses');
            return false;
        }
        return true;
    }

    /**
     * Returns before_auth_url redirect parameter for customer session
     *
     * @return string
     */
    public function getCustomerBeforeAuthUrl()
    {
        return $this->_objectManager->create('Magento\Framework\UrlInterface')->getUrl('*/*', ['_secure' => true]);
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     *
     * @return array
     */
    public function getActionFlagList()
    {
        return ['redirectLogin' => true];
    }

    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->_getHelper()->getMSLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     *
     * @return string
     */
    public function getRedirectActionName()
    {
        return 'index';
    }
}
