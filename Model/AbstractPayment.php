<?php
/**
 * PayU_EasyPlus payment method model
 *
 * @category    PayU
 * @package     PayU_EasyPlus
 * @author      Kenneth Onah
 * @copyright   PayU South Africa (http://payu.co.za)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayU\EasyPlus\Model;

use PayU\EasyPlus\Helper\Data as FrontendHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\TransactionInterface;

/**
 * Redirect payment method model for all payment methods except Discovery Miles
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractPayment extends AbstractPayU
{
    const CODE = '';
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isGateway = false;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canOrder = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isInitializeNeeded = false;

    protected $_isOffline = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    protected $_easyPlusApi                 = false;
    protected $_dataFactory                 = false;
    protected $_requestFactory              = false;
    protected $_responseFactory             = false;
    protected $_storeManager                = false;
    protected $_checkoutSession             = false;
    protected $_session                     = false;
    protected $_response                    = null;
    protected $_paymentData                 = false;
    protected $_payuReference               = '';
    protected $_minAmount                   = null;
    protected $_maxAmount                   = null;
    protected $_redirectUrl                 = '';
    protected $_supportedCurrencyCodes      = array( 'NGN', 'ZAR', 'KES', 'TZS', 'ZMW', 'USD');

    /**
     * Fields that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataKeys = ['Safekey'];
    /**
     * Payment additional information key for payment action
     *
     * @var string
     */
    protected $_isOrderPaymentActionKey = 'is_order_action';

    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    protected $transactionRepository;

    protected $orderFactory;
    protected $quoteRepository;
    protected $orderSender;
    protected $invoiceSender;
    protected $_encryptor;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $_orderRepository;

    /**
     * @var Transaction\BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $OrderConfig;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Session\Generic $session,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \PayU\EasyPlus\Model\Api\Factory $apiFactory,
        \PayU\EasyPlus\Helper\DataFactory $dataFactory,
        \PayU\EasyPlus\Model\Request\Factory $requestFactory,
        \PayU\EasyPlus\Model\Response\Factory $responseFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Config $OrderConfig,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            null,
            null,
            $data
        );

        $this->_encryptor = $encryptor;
        $this->_dataFactory = $dataFactory;
        $this->_requestFactory = $requestFactory;
        $this->_responseFactory = $responseFactory;
        $this->_storeManager = $storeManager;
        $this->_checkoutSession = $checkoutSession;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder = $transactionBuilder;
        $this->_invoiceService = $invoiceService;
        $this->_orderRepository = $orderRepository;
        $this->_easyPlusApi = $apiFactory->create();
        $this->_session = $session;
        $this->_paymentData = $paymentData;
        $this->orderFactory = $orderFactory;
        $this->quoteRepository = $quoteRepository;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->_transaction = $transaction;
        $this->OrderConfig = $OrderConfig;

        $this->initializeApi();

        $this->_minAmount = $this->getValue('min_order_total');
        $this->_maxAmount = $this->getValue('max_order_total');
    }

    /**
     * Store setter
     *
     * @param \Magento\Store\Model\Store|int $store
     * @return $this
     */
    public function setStore($store)
    {
        $this->setData('store', $store);
        if (null === $store) {
            $store = $this->_storeManager->getStore()->getId();
            $this->setData('store', $store);
        }

        return $this;
    }

    /**
     * Get api
     *
     * @return \PayU\EasyPlus\Model\Api\Api
     */
    public function getApi()
    {
        return $this->_easyPlusApi;
    }

    /**
     * Get api
     *
     * @return \PayU\EasyPlus\Model\Response
     */
    public function getResponse()
    {
        if(null === $this->_response) {
            $this->_response = $this->_responseFactory->create();
        }

        return $this->_response;
    }

    /**
     * Fill response with data.
     *
     * @param array $postData
     * @return $this
     */
    public function setResponseData($postData)
    {
        $this->getResponse()->setData('return', $postData);

        return $this;
    }

    /**
     * Getter for specified value according to set payment method code
     *
     * @param mixed $key
     * @param null $storeId
     * @return mixed
     */
    public function getValue($key, $storeId = null)
    {
        if(in_array($key, ['safe_key', 'api_password']))
            return $this->_encryptor->decrypt($this->getConfigData($key, $storeId));

        return $this->getConfigData($key, $storeId);
    }

    /**
     * Define if debugging is enabled
     *
     * @return bool
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     * @api
     * @deprecated 100.2.0
     */
    public function getDebugFlag()
    {
        return (bool)(int)$this->getConfigData('developer/active');
    }

    /**
     * Availability for currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {

        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }

        return parent::canUseForCurrency($currencyCode);
    }

    /**
     * Check response code came from PayU.
     *
     * @return true in case of Approved response
     * @throws LocalizedException In  of Declined or Error response from Authorize.net
     */
    public function checkResponseCode()
    {

        $state = $this->getResponse()->getTransactionState();

        switch ($this->getResponse()->getTransactionState()) {
            case self::TRANS_STATE_SUCCESSFUL:
            case self::TRANS_STATE_AWAITING_PAYMENT:
                return true;
            case self::TRANS_STATE_FAILED:
            case self::TRANS_STATE_EXPIRED:
            case self::TRANS_STATE_TIMEOUT:
                throw new LocalizedException(
                    $this->_dataFactory->create('frontend')->wrapGatewayError($this->getResponse()->getResultMessage())
                );
            default:
                throw new LocalizedException(
                    __('There was a payment verification error.')
                );
        }
    }

    /**
     * Check transaction id came with response data
     *
     * @return true in case of right transaction id
     * @throws LocalizedException In case of bad transaction id.
     */
    public function checkTransId()
    {
        if (!$this->getResponse()->getTranxId()) {
            throw new LocalizedException(
                __('Payment verification error: invalid PayU reference')
            );
        }
        return true;
    }

    /**
     * Compare amount with amount from the response from PayU.
     *
     * @param float $amount
     * @return bool
     */
    protected function matchAmount($amount)
    {
        $amountPaid = $this->getResponse()->getTotalCaptured();

        return sprintf('%.2F', $amount) == sprintf('%.2F', $amountPaid);
    }

    /**
     * Initialize PayU API credentials
     */
    protected function initializeApi()
    {
        $this->_easyPlusApi->setSafeKey($this->getValue('safe_key'));
        $this->_easyPlusApi->setUsername($this->getValue('api_username'));
        $this->_easyPlusApi->setPassword($this->getValue('api_password'));
        $this->_easyPlusApi->setMethodCode(static::CODE);
    }

    /**
     * Setup transaction before redirect
     *
     * @param DataObject| InfoInterface| Payment $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    protected function _setupTransaction(InfoInterface $payment, $amount)
    {
        $response = null;
        $this->validateAmount($amount);

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $payment->setBaseAmountOrdered($order->getBaseTotalDue());
        $payment->setAmountOrdered($order->getTotalDue());
        $payment->getMethodInstance()->setIsInitializeNeeded(true);

        $helper = $this->_dataFactory->create('frontend');
        $request = $this->generateRequestFromOrder($order, $helper);

        try {

            $response = $this->_easyPlusApi->doSetTransaction($request->getData());

            $this->debugData(['request' => $request->getData()]);
            $this->debugData(['response' => $response]);


            if($response->return->successful) {
                $payUReference = $response->return->payUReference;

                // set PayU session variables
                $this->_session->setCheckoutReference($payUReference);
                $this->_session->setCheckoutOrderIncrementId($order->getIncrementId());
                $this->_easyPlusApi->setPayUReference($payUReference);
                $this->_session->setCheckoutRedirectUrl($this->_easyPlusApi->getRedirectUrl());

                // set checkout session variables
                $this->_checkoutSession->setLastQuoteId($order->getQuoteId())
                    ->setLastSuccessQuoteId($order->getQuoteId());
                $this->_checkoutSession->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());

                $message = 'Amount of %1 is pending approval. Redirecting to PayU.<br/>'
                    . 'PayU reference "%2"<br/>';
                $message = __(
                    $message,
                    $order->getBaseCurrency()->formatTxt($amount),
                    $payUReference
                );

                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                    ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $order->addStatusHistoryComment($message);

                $payment->setSkipOrderProcessing(true);

                $payment->setAdditionalInformation('payUReference', $payUReference);
            } else {
                throw new LocalizedException(__('Contacting PayU gateway, error encountered'));
            }
        } catch (\Exception $e) {
            $this->debugData(['request' => $request->getData(), 'exception' => $e->getMessage()]);
            $this->debugData(['response' => $response]);
            $this->_logger->error(__('Contacting PayU gateway, error encountered. Reason: ' . $e->getMessage()));

            throw new LocalizedException(__('Oops! Transaction processing encountered an error.'));
        }

        return $this;
    }

    /**
     * Process order cancellation
     *
     * @param $params
     * @throws LocalizedException
     * @throws \Exception
     */
    public function processCancellation($params)
    {
        $response = $this->_easyPlusApi->doGetTransaction($params, $this);

        //$this->setResponseData($response);
        //$response = $this->getResponse();

        $payUReference = $response->getTranxId();

        //operate with order
        $orderIncrementId = $response->getInvoiceNum();

        $message = 'Payment transaction of amount of %1 was canceled by user on PayU.<br/>' . 'PayU reference "%2"<br/>';

        $isError = false;
        if ($orderIncrementId) {
            /* @var $order Order */
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            //check payment method
            $payment = $order->getPayment();
            if (!$payment || $payment->getMethod() != $this->getCode()) {
                throw new LocalizedException(
                    __('This payment didn\'t work out because we can\'t find this order.')
                );
            }
            if ($order->getId()) {
                //operate with order
                $message = __(
                    $message,
                    $order->getBaseCurrency()->formatTxt($order->getBaseTotalDue()),
                    $payUReference
                );

                $order->addStatusHistoryComment($message);
                $order->cancel()->save();
            } else {
                $isError = true;
            }
        } else {
            $isError = true;
        }

        if ($isError) {





            $responseText = $this->_dataFactory->create('frontend')->wrapGatewayError( "safddgdgsf" /* $response->getResultMessage() */);
            $responseText = $responseText
                ? $responseText
                : __('This payment didn\'t work out because we can\'t find this order.');

            throw new LocalizedException($responseText);
        }
    }

    /**
     * Process PayU IPN
     *
     * @param $data
     * @param Order $order
     * @throws \Exception
     */
    public function processNotification($data, $order)
    {
        if($order->getState() == strtolower(AbstractPayU::TRANS_STATE_PROCESSING))
            return;

        $payuReference = $data['PayUReference'];
        $response = $this->_easyPlusApi->doGetTransaction($payuReference, $this);
        $resultCode = $response->getResultCode();

        $transactionNotes = "<strong>-----PAYU NOTIFICATION RECEIVED---</strong><br />";
        //Checking the response from the SOAP call to see if IPN is valid
        if(isset($resultCode) && (!in_array($resultCode, array('POO5', 'EFTPRO_003', '999', '305')))) {

            if(isset($data['TransactionState'])
                && (in_array($data['TransactionState'],  array('PROCESSING', 'SUCCESSFUL', 'AWAITING_PAYMENT', 'FAILED', 'TIMEOUT', 'EXPIRED')))
            ) {
                $amountBasket = $data['Basket']['AmountInCents'] / 100;
                $amountPaid = isset($data['PaymentMethodsUsed']['Creditcard']['AmountInCents'])
                    ? $data['PaymentMethodsUsed']['Creditcard']['AmountInCents'] / 100 : '';

                if(empty($amountPaid)) {
                    $amountPaid = isset($data['PaymentMethodsUsed']['Eft']['AmountInCents']) ?
                        $data['PaymentMethodsUsed']['Eft']['AmountInCents'] / 100 : 'N/A';
                }

                $transactionNotes .= "Order Amount: " . $amountBasket . "<br />";
                $transactionNotes .= "Amount Paid: " . $amountPaid . "<br />";
                $transactionNotes .= "Merchant Reference : " . $data['MerchantReference'] . "<br />";
                $transactionNotes .= "PayU Reference: " . $payuReference . "<br />";
                $transactionNotes .= "PayU Payment Status: ". $data["TransactionState"]."<br /><br />";

                $paymentMethod = isset($data['PaymentMethodsUsed']['Creditcard']) ?
                    $data['PaymentMethodsUsed']['Creditcard'] : '';

                if(empty($paymentMethod))
                    $paymentMethod = isset($data['PaymentMethodsUsed']['Eft']) ? $data['PaymentMethodsUsed']['Eft'] : '';

                if(!empty($paymentMethod)) {
                    if(is_array($paymentMethod)) {
                        $transactionNotes .= "<strong>Payment Method Details:</strong>";
                        foreach($paymentMethod as $key => $value) {
                            $transactionNotes .= "<br />&nbsp;&nbsp;- ".$key.":".$value." , ";
                        }
                    }
                }

                // update order state
                switch ($data['TransactionState']) {
                    // Payment completed
                    case 'SUCCESSFUL':
                        $order->addStatusHistoryComment($transactionNotes);
                        $this->invoiceAndNotifyCustomer($order);
                        break;
                    case 'FAILED':
                    case 'TIMEOUT':
                    case 'EXPIRED':
                        $order->registerCancellation($transactionNotes);
						$order->save();
                        break;
                    case 'AWAITING_PAYMENT':
                    case 'PROCESSING':
                        $order->addStatusHistoryComment($transactionNotes, true);
						$order->save();
                        break;
                    default:
                        $order->addStatusHistoryComment($transactionNotes, true);
						$order->save();
                    break;
                }

                $this->debugData(['info' => 'PayU IPN Processing complete.', 'response' => $data]);
            } else {

                $transactionNotes = '<strong>Payment unsuccessful: </strong><br />';
                $transactionNotes .= "PayU Reference: " . $response->getTranxId() . "<br />";
                $transactionNotes .= "Point Of Failure: " . $response->getPointOfFailure() . "<br />";
                $transactionNotes .= "Result Code: " . $response->getResultCode();
                $transactionNotes .= "Result Message: " . $response->getResultMessage();

                $order->registerCancellation($transactionNotes);

                $this->debugData(['info' => 'PayU payment Failed. Payment status unknown']);
            }
        } else {

            $transactionNotes = '<strong>Payment unsuccessful: </strong><br />';
            $transactionNotes .= "PayU Reference: " . $response->getTranxId() . "<br />";
            $transactionNotes .= "Point Of Failure: " . $response->getPointOfFailure() . "<br />";
            $transactionNotes .= "Result Code: " . $response->getResultCode();
            $transactionNotes .= "Result Message: " . $response->getResultMessage();

            $order->registerCancellation($transactionNotes);

            $this->debugData(['info' => 'PayU payment Failed']);
        }
    }

    /**
     * Operate with order using data from $_POST which came from PayU by Return URL.
     *
     * @param string $params PayU reference Id
     * @return void
     * @throws LocalizedException In case of validation error or order capture error
     */
    public function process($params)
    {
        //$this->_debug(['response' => $responseData]);
        $response = $this->_easyPlusApi->doGetTransaction($params, $this);
        $this->setResponseData($response->getReturn());

        $response = $this->getResponse();
        //operate with order
        $orderIncrementId = $response->getInvoiceNum();

        $isError = false;
        if ($orderIncrementId) {
            /* @var $order Order */
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            //check payment method
            $payment = $order->getPayment();
            if (!$payment || $payment->getMethod() != $this->getCode()) {
                throw new LocalizedException(
                    __('This payment didn\'t work out because we can\'t find this order.')
                );
            }
            if ($order->getId()) {
                try{
                    // Everything looks good, so capture order
                    $this->captureOrderAndPayment($order);
                } catch (LocalizedException $exception) {
                    $test = 1;
                } catch (\Exception $exception) {
                    $test = 1;
                }

            } else {
                $isError = true;
            }
        } else {
            $isError = true;
        }

        if ($isError) {
            $responseText = $this->_dataFactory->create('frontend')->wrapGatewayError($response->getResultMessage());
            $responseText = $responseText && !$response->isPaymentSuccessful()
                ? $responseText
                : __('This payment didn\'t work out because we can\'t find this order.');
            throw new LocalizedException($responseText);
        }
    }

    /**
     * Operate with order using information from PayU.
     * Capture order.
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     * @throws \Exception
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function captureOrderAndPayment(Order $order)
    {
        $response = $this->getResponse();

        try {
            $this->checkResponseCode();
            $this->checkTransId();
        } catch (\Exception $e) {
            //decline the order (in case of wrong response code) but don't return money to customer.
            $message = $e->getMessage();
            $this->declineOrder($order, $response, false, $message);
            throw $e;
        }

        //create transaction. need for void if amount will not match.
        /* @var $payment InfoInterface| Payment */
        $payment = $order->getPayment();
        $this->fillPaymentInfoByResponse($payment);
        $this->setIsInitializeNeeded(false);
        $this->setResponseData($response->getReturn());
        $this->processPaymentFraudStatus($payment);
        $this->addStatusCommentOnUpdate($payment, $response);


        // Here we must do something to check for a Pending request
        if($payment->getIsTransactionPending()) {
            $order->save();
            return;
        }



        // match amounts. should be equal for capturing order.
        // decline the order if amount does not match.
        if (!$this->matchAmount($payment->getBaseAmountOrdered())) {
            $message = __(
                'Something went wrong: the paid amount does not match the order amount.'
                . ' Please correct this and try again.'
            );
            $this->declineOrder($order, $response, true, $message);
            throw new LocalizedException($message);
        }

        $this->invoiceAndNotifyCustomer($order);
    }

    /**
     * Generate invoice and notify customer
     *
     * @param Order $order
     * @throws LocalizedException
     */
    protected function invoiceAndNotifyCustomer(Order $order)
    {

        $id = $order->getIncrementId();

        $process_id = $this->_session->getPayUProcessId(uniqid());
        $process_string = $this->_session->getPayUProcessString(self::class);

        try {
            $order->setCanSendNewEmailFlag(true);
            $this->orderSender->send($order);

            $this->debugData(['info' => " ($process_id) ($id) PayU $process_string: can_invoice (initial check): " . $order->canInvoice()]);
            $this->_logger->info(" ($process_id) ($id) PayU $process_string: can_invoice (initial check): " . $order->canInvoice());


            if($order->canInvoice()) {

                /**
                 * 2020/10/23 Double Invoice Correction
                 * Force reload order state to check status just before update,
                 * discard invoice if status changed since start of process
                 */
                $order_status_test = $this->orderFactory->create()->loadByIncrementId($order->getIncrementId());
                $this->debugData(['info' => " ($process_id) ($id) PayU $process_string: can_invoice (double check): " . $order_status_test->canInvoice()]);
                $this->_logger->info(" ($process_id) ($id) PayU $process_string: can_invoice (double check): " . $order->canInvoice());

                if(!$order_status_test->canInvoice()) {
                    // Simply just skip this section
                    goto cannot_invoice_marker;
                }

                $status = $this->OrderConfig->getStateDefaultStatus('processing');
                $order->setState("processing")->setStatus($status);
                $order->save();

                $invoice = $this->_invoiceService->prepareInvoice($order);

                $invoice->register();
                $invoice->save();
                $transactionService = $this->_transaction->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
                $transactionService->save();

                $this->_logger->info(" ($process_id) ($id) PayU $process_string: INVOICED");

                $this->invoiceSender->send($invoice);

                //send notification code
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                    ->setIsCustomerNotified(true)
                    ->save();

            } else {
                /**
                 * Double Invoice Correction
                 * 2020/10/23
                 */
                cannot_invoice_marker:
                $this->debugData(['info' => " ($id) Already invoiced, skip"]);
                $this->_logger->info(" ($process_id) ($id) PayU $process_string: Already invoiced, skip");

            }

        } catch (\Exception $e) {
            throw new LocalizedException("Error encountered while capturing your order");
        }
    }

    /**
     * Fill payment with credit card data from response from PayU.
     *
     * @param DataObject $payment
     * @return void
     */
    protected function fillPaymentInfoByResponse(DataObject $payment)
    {
        $response = $this->getResponse();
        $payment->setTransactionId($response->getTranxId())
            ->setParentTransactionId(null)
            ->setIsTransactionClosed(true)
            ->setTransactionAdditionalInfo(self::REAL_TRANSACTION_ID_KEY, $response->getTranxId());

        if ($response->isPaymentMethodCc()) {
            $payment->setGatewayReference($response->getGatewayReference())
                ->setCcLast4($payment->encrypt(substr($response->getCcNumber(), -4)));
        }

        if ($response->getTransactionState() == self::TRANS_STATE_AWAITING_PAYMENT) {
            $payment->setIsTransactionPending(true);
        }

        if($response->isFraudDetected()) {
            $payment->setIsFraudDetected(true);
        }
    }

    /**
     * Process fraud status
     *
     * @param Payment $payment
     * @return $this
     */
    protected function processPaymentFraudStatus(Payment $payment)
    {
        try {
            $fraudDetailsResponse = $payment->getMethodInstance()
                ->fetchTransactionFraudDetails($payment, $this->getResponse()->getTranxId());
            $fraudData = $fraudDetailsResponse->getData();

            if (empty($fraudData)) {
                $payment->setIsFraudDetected(false);
                return $this;
            }

            $payment->setIsFraudDetected(true);
            $payment->setAdditionalInformation('fraud_details', $fraudData);
        } catch (\Exception $e) {
            //this request is optional
        }

        return $this;
    }

    /**
     * Generate request object and fill its fields from Quote or Order object
     *
     * @param Order $order Quote or order object.
     * @param FrontendHelper $helper
     * @return Request
     */
    public function generateRequestFromOrder(Order $order, $helper)
    {
        $request = $this->_requestFactory->create()
            ->setConstantData($this, $order, $helper)
            ->setDataFromOrder($order, $this);

        //$this->_debug(['request' => $request->getData()]);

        return $request;
    }

    /**
     * Register order cancellation. Return money to customer if needed.
     *
     * @param Order $order
     * @param Response $response
     * @param bool $voidPayment
     * @param string $message
     * @return void
     */
    public function declineOrder(Order $order, Response $response, bool $voidPayment = true, string $message = '')
    {
        $payment = $order->getPayment();
        try {
            if (
                $voidPayment && $response->getTranxId()
                && strtoupper($response->getTransactionType()) == self::REQUEST_TYPE_PAYMENT
            ) {
                $this->_importToPayment($response, $payment);
                $this->addStatusCommentOnUpdate($payment, $response);
                $order->registerCancellation()->save();
            }
        } catch (\Exception $e) {
            //quiet decline
            $this->_logger->critical($e);
        }
    }

    /**
     * Fetch transaction details info
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     * @throws LocalizedException
     * @return Response
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        return $this->_easyPlusApi->fetchTransactionInfo($this, $payment, $transactionId);
    }

    /**
     * Fetch fraud details
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     * @throws LocalizedException
     * @return \Magento\Framework\DataObject
     */
    public function fetchTransactionFraudDetails(InfoInterface $payment, $transactionId)
    {
        $response = $this->fetchTransactionInfo($payment, $transactionId);
        $responseData = new DataObject();

        if (empty($response->transaction->FDSFilters->FDSFilter)) {
            return $response;
        }

        $responseData->setFdsFilterAction(
            $response->transaction->FDSFilterAction
        );
        $responseData->setAvsResponse((string)$response->transaction->AVSResponse);
        $responseData->setCardCodeResponse((string)$response->transaction->cardCodeResponse);
        $responseData->setCavvResponse((string)$response->transaction->CAVVResponse);
        $responseData->setFraudFilters($this->getFraudFilters($response->transaction->FDSFilters));

        return $responseData;
    }

    /**
     * Get fraud filters
     *
     * @param \Magento\Framework\Simplexml\Element $fraudFilters
     * @return array
     */
    protected function getFraudFilters($fraudFilters)
    {
        $result = [];

        foreach ($fraudFilters->FDSFilter as $filer) {
            $result[] = [
                'name' => (string)$filer->name,
                'action' => (string)$filer->action
            ];
        }

        return $result;
    }

    /**
     * Import payment info to payment
     *
     * @param Response $response
     * @param InfoInterface $payment
     * @return void
     */
    protected function _importToPayment($response, $payment)
    {
        $payment->setTransactionId($response->getTranxId())
            ->setIsTransactionClosed(0);

        $this->_easyPlusApi->importPaymentInfo($response, $payment);
    }

    /**
     * Add comment on order status update
     *
     * @param Payment $payment
     * @param DataObject $response
     */
    protected function addStatusCommentOnUpdate(Payment $payment, DataObject $response) {
        $transactionId = $response->getTranxId();

        if ($payment->getIsTransactionApproved()) {
            $message = __(
                'Transaction %1 has been approved. Amount %2. Transaction status is "%3"',
                $transactionId,
                $payment->getOrder()->getBaseCurrency()->formatTxt($payment->getAmountOrdered()),
                $response->getTransactionState()
            );
            $payment->getOrder()->addStatusHistoryComment($message);

            $test = 1;
        } elseif ($payment->getIsTransactionPending()) {

            $message = __(
                'Transaction %1 is pending payment. Amount %2. Transaction status is "%3"',
                $transactionId,
                $payment->getOrder()->getBaseCurrency()->formatTxt($payment->getAmountOrdered()),
                $response->getTransactionState()
            );
            $payment->getOrder()->addStatusHistoryComment($message);

        } elseif ($payment->getIsTransactionDenied()) {
            $message = __(
                'Transaction %1 has been voided/declined. Transaction status is "%2". Amount %3.',
                $transactionId,
                $response->getTransactionState(),
                $payment->getOrder()->getBaseCurrency()->formatTxt($payment->getAmountOrdered())
            );
            $payment->getOrder()->addStatusHistoryComment($message);
        }
    }

    /**
     * Is active
     *
     * @param int|null $storeId
     * @return bool
     * @deprecated 100.2.0
     */
    public function isActive($storeId = null)
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    /**
     * Check whether payment method can be used
     * @param CartInterface|Quote|null $quote
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->isMethodAvailable();
    }

    /**
     * Check whether method available for checkout or not
     *
     * @param null $methodCode
     *
     * @return bool
     */
    public function isMethodAvailable($methodCode = null)
    {
        $methodCode = $methodCode ?: $this->_code;

        return $this->isMethodActive($methodCode);
    }

    /**
     * Check whether method active in configuration and supported for merchant country or not
     *
     * @param string $methodCode method code
     * @return bool
     *
     * @todo: refactor this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isMethodActive($methodCode)
    {
        $methodCode = $methodCode ?: $this->_code;
        $isEnabled = (bool)$this->getConfigData('active');

        return $this->isMethodSupportedForCountry($methodCode) && $isEnabled;
    }

    /**
     * Check whether method supported for specified country or not
     *
     * @param string|null $method
     * @param string|null $countryCode
     * @return bool
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function isMethodSupportedForCountry($method = null, $countryCode = null)
    {
        return true;
    }

    /**
     * Validate amount is within threshold
     *
     * @param $amount float amount to validate
     * @throws LocalizedException
     */
    protected function validateAmount($amount)
    {
        if ($amount <= 0 || $amount < $this->_minAmount || $amount > $this->_maxAmount) {
            throw new LocalizedException(__('Invalid amount for checkout with this payment method.'));
        }
    }

    /**
     * PayU redirect url
     *
     * @return mixed
     */
    public function getCheckoutRedirectUrl()
    {
        return $this->_session->getCheckoutRedirectUrl();
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     * @return false | TransactionInterface
     */
    protected function getOrderTransaction($payment)
    {
        return $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_ORDER,
            $payment->getId(),
            $payment->getOrder()->getId()
        );
    }
}
