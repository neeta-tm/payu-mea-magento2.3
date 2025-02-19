<?php


namespace PayU\EasyPlus\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PayU\EasyPlus\Model\AbstractPayment;
use \Psr\Log\LoggerInterface;

class CheckTransactionState
{

    /** @var LoggerInterface  */
    protected $logger;

    /** @var \PayU\EasyPlus\Model\Api\Api  */
    protected $_easyPlusApi;

    /** @var \Magento\Framework\Encryption\EncryptorInterface  */
    protected $_encryptor;

    /** @var \Magento\Store\Model\StoreManagerInterface  */
    protected $_storeManager;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface  */
    protected $_scopeConfig;

    protected $orderFactory;


    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;
    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /** @var \Magento\Framework\Registry|null  */
    protected $coreRegistry = null;

    /** @var null  */
    protected $_code = null;

    /** @var null  */
    protected $_payUReference = null;

    /** @var \Magento\Framework\App\State **/
    private $state;

    /** @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory  */
    protected $_orderCollectionFactory;
    /**
     * @var Order\Email\Sender\OrderSender
     */
    private $orderSender;
    /**
     * @var Order\Email\Sender\InvoiceSender
     */
    private $invoiceSender;
    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    private $_invoiceService;
    /**
     * @var \Magento\Framework\DB\Transaction
     */
    private $_transaction;
    /**
     * @var Order\Config
     */
    private $OrderConfig;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $OrderRepository;

    /**
     * CheckTransactionState constructor.
     * @param LoggerInterface $logger
     * @param \PayU\EasyPlus\Model\Api\Factory $apiFactory
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param Order\Email\Sender\OrderSender $orderSender
     * @param Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Sales\Api\OrderRepositoryInterface $OrderRepository
     * @param Order\Config $OrderConfig
     */
    public function __construct(
        LoggerInterface $logger,
        \PayU\EasyPlus\Model\Api\Factory $apiFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\App\State $state,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Api\OrderRepositoryInterface $OrderRepository,
        \Magento\Sales\Model\Order\Config $OrderConfig,
        \Magento\Sales\Model\OrderFactory $orderFactory
    )
    {
        $this->logger = $logger;
        $this->_easyPlusApi = $apiFactory->create();
        $this->_encryptor = $encryptor;
        $this->_storeManager = $storeManager;
        $this->coreRegistry = $registry;
        $this->_scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->state = $state;
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->_invoiceService = $invoiceService;
        $this->_transaction = $transaction;
        $this->OrderRepository = $OrderRepository;
        $this->OrderConfig = $OrderConfig;
        $this->orderFactory = $orderFactory;

    }

    /**
     * @param $data
     * @param $order
     */
    public function processReturn($data, &$order) {
        $data = (array)$data;
        $data['basket'] = array($data['basket']);
        //$data['paymentMethodsUsed'] = array($data['paymentMethodsUsed']);

        $transactionNotes = "<strong>-----PAYU STATUS CHECKED ---</strong><br />";

        if(!isset($data['resultCode']) || (in_array($data['resultCode'], array('POO5', 'EFTPRO_003', '999', '305')))) {
            $this->logger->info("($this->process_id) No resultCode");
            $this->logger->info(json_encode($data));
            return;
        }

        if(!isset($data["transactionState"])
            || (!in_array($data['transactionState'],  array('PROCESSING', 'SUCCESSFUL', 'AWAITING_PAYMENT', 'FAILED', 'TIMEOUT', 'EXPIRED')))
        ) {
            $this->logger->info("($this->process_id) No transactionState");
            $this->logger->info(json_encode($data));
            return;
        }

        $transactionNotes .= "PayU Reference: " . $data["payUReference"] . "<br />";
        $transactionNotes .= "PayU Payment Status: ". $data["transactionState"]."<br /><br />";

        switch ($data['transactionState']) {
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
            default:
                $order->addStatusHistoryComment($transactionNotes, true);
                break;
        }

    }

    /**
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrderCollection()
    {
        // Not Needed for Cron
        //$this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND); // or \Magento\Framework\App\Area::AREA_ADMINHTML, depending on your needs

        $collection = $this->_orderCollectionFactory->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter('status',
                ['in' => ['pending_payment']]
            )
        ;

        return $collection;

    }







    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {


        $bypass_payu_cron = $this->getCRONConfigData('bypass_payu_cron');

        if('1' ===  $bypass_payu_cron) {
            $this->logger->info("PayU CRON DISABLED");
            return;
        }

        $process_id = uniqid();
        $this->process_id = $process_id;

        $this->logger->info("PayU CRON Started, PID: $process_id");

        $orders = $this->getOrderCollection();
        foreach ($orders->getItems() as $order) {
            $payment = $order->getPayment();
            $additional_info = $payment->getAdditionalInformation();
            $code = $payment->getData('method');

            $id = $order->getEntityId();
            $this->logger->info("($process_id) Check: $id");

            if(false === strpos($code, 'payumea')) {
                $this->logger->info("($process_id) Not PayU");
                continue;
            }

            if(isset($additional_info["fraud_details"])) {
                if(in_array($additional_info["fraud_details"]["return"]["transactionState"], ['SUCCESSFUL'])) {
                    $this->logger->info("($process_id) ($id) Already Success");
                    continue;
                }
                $payUReference = $additional_info["fraud_details"]["return"]["payUReference"];
            } else {
                if(!isset($additional_info["payUReference"])) {
                    $this->logger->info("($process_id) No Details");
                    continue;
                }
                $payUReference = $additional_info["payUReference"];
            }

            if(isset($additional_info["fraud_details"]["return"]["transactionState"])) {
                $state_test = $additional_info["fraud_details"]["return"]["transactionState"];
            } else {
                $state_test = '';
            }

            switch ($state_test) {
                case AbstractPayment::TRANS_STATE_SUCCESSFUL:
                case AbstractPayment::TRANS_STATE_FAILED:
                case AbstractPayment::TRANS_STATE_EXPIRED:
                case AbstractPayment::TRANS_STATE_TIMEOUT:
                    $this->logger->info(" ($id) Already Success Status");
                    break;
                default:

                    if(!$this->shouldDoCheck($order, $payment)) {
                        $this->logger->info("($process_id) ($id) Check not timed");
                        break;
                    }

                    $this->logger->info("($process_id) ($id) Doing Check");
                    // We will check trans state again
                    $this->_code = $code;
                    $this->_payUReference = $payUReference;
                    // We must get some config settings
                    $this->initializeApi();

                    $result = $this->_easyPlusApi->checkTransaction($this->_payUReference);

                    $return = $result->getData('return');

                    $order = $this->orderRepository->get($order->getId());

                    if($order->getState() == \Magento\Sales\Model\Order::STATE_PROCESSING) {
                        $this->logger->info("Order Completed, no need to run... order id = " . $order->getId());
                        break;
                    }

                    if($order->hasInvoices()) {
                        $this->logger->info("($process_id) Already Invoiced, no need to run... order id = " . $order->getId());
                        break;
                    }

                    try{
                        $this->processReturn($return, $order);
                    } catch (\Exception $exception) {
                        $this->logger->info($exception->getMessage());
                        $this->logger->info(json_encode($return));
                    }

                    $order->setUpdatedAt(null);
                    $order->save();
                    break;
            }
        };

        // Do your Stuff
        $this->logger->info("PayU CRON Ended, PID: $process_id");
    }

    protected function shouldDoCheck(&$order, $payment)
    {
        $created_at = strtotime($order->getCreatedAt());
        $updated_at = strtotime($order->getUpdatedAt());


        $time_now = time();

        $minutes_created = (int) ceil (($time_now - $created_at) / 60 );
        $minutes_updated = $minutes_created - (int) ceil (($time_now - $updated_at) / 60 );


        $payumea_cron_delay = $this->getCRONConfigData('payumea_cron_delay');

        if(empty($payumea_cron_delay)) {
            $payumea_cron_delay = "5";
        }

        $this->logger->info("($this->process_id) minutes_created: $minutes_created - Delay: $payumea_cron_delay");
        $this->logger->info("($this->process_id) minutes_updated: $minutes_updated - Delay: $payumea_cron_delay");


        $minutes_created = $minutes_created - $payumea_cron_delay;
        $minutes_updated = $minutes_updated - $payumea_cron_delay;




        // After X minute
    //    if($minutes_created == 1) { return true; }
    //    if($minutes_created == 2) { return true; }
     //   if($minutes_created == 3) { return true; }


        $ranges = [];
        $ranges[] = [1,4];
        $ranges[] = [5,9];
        $ranges[] = [10,19];
        $ranges[] = [20,29];
        $ranges[] = [30,59];
        $ranges[] = [(1*60),(2*60)-1];
        $ranges[] = [(2*60),(3*60)-1];
        $ranges[] = [(3*60),(6*60)-1];
        $ranges[] = [(6*60),(12*60)-1];
        $ranges[] = [(12*60),(24*60)-1];



        for ($i=1;$i<=31;$i++) {
            $ii = $i * 24;
            $ranges[] = [($ii*60),($ii*60)-1];
        }

     //   ob_start();
     //   var_dump($ranges);
     //   $this->logger->info("PayU CRON Time Ranges" . ob_get_clean());


        foreach ($ranges as $v) {
            if( (($v[0] <= $minutes_created) && ($minutes_created <= $v[1]))  && (!(($v[0]  <= $minutes_updated) && ($minutes_updated <= $v[1]))) ) {
                return true;
            }
        }

        if( ((744 <= $minutes_created) )  && (!((744<= $minutes_updated))) ) {
            return true;
        }

        $this->logger->info("($this->process_id) Check Not Needed");

        return false;

    }

    protected function initializeApi()
    {
        $this->_easyPlusApi->setSafeKey($this->getValue('safe_key'));
        $this->_easyPlusApi->setUsername($this->getValue('api_username'));
        $this->_easyPlusApi->setPassword($this->getValue('api_password'));
        $this->_easyPlusApi->setMethodCode($this->_code);
    }


    public function getValue($key, $storeId = null)
    {
        if(in_array($key, ['safe_key', 'api_password']))
            return $this->_encryptor->decrypt($this->getConfigData($key, $storeId));

        return $this->getConfigData($key, $storeId);
    }


    public function getConfigData($field, $storeId = null)
    {
        $path = 'payment/' . $this->_code . '/' . $field;
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getCRONConfigData($field, $storeId = null)
    {
        $path = 'payment/payumea_cron/' . $field;
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    protected function invoiceAndNotifyCustomer(Order $order)
    {

        $id = $order->getIncrementId();

        try {
            $order->setCanSendNewEmailFlag(true);
            $this->orderSender->send($order);

            $this->logger->info("($this->process_id) ($id) PayU CRON: can_invoice (initial check): " . $order->canInvoice());
         //   $this->debugData(['info' => $order->canInvoice()]);
            if($order->canInvoice()) {

                /**
                 * 2021/06/16 Double Invoice Correction
                 * Force reload order state to check status just before update,
                 * discard invoice if status changed since start of process
                 */
                $order_status_test = $this->orderFactory->create()->loadByIncrementId($order->getIncrementId());
                $this->logger->info('($this->process_id) ($id) PayU CRON: can_invoice (double check): ' .  $order_status_test->canInvoice());

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

                $this->logger->info(" ($this->process_id) ($id) PayU CRON: INVOICED");


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
                 * 2021/06/16
                 */
                cannot_invoice_marker:
                $this->logger->info('($this->process_id)  ($id) Already invoiced, skip');
            //    $this->debugData(['info' => 'Already invoiced, skip']);
            }

        } catch (\Exception $e) {
            throw new LocalizedException("Error encountered while capturing your order");
        }
    }



}
