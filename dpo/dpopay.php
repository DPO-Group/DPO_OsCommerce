<?php

/**
 * This file is part of DPO Pay.
 *
 * @link `https://dpogroup.com`
 *
 */

namespace common\modules\orderPayment;

require_once __DIR__ . '/lib/dpopay/modules/payment/vendor/autoload.php';

use common\models\Currencies;
use common\models\CustomersBasket;
use common\models\Orders;
use common\models\OrdersPayment;
use common\classes\modules\ModulePayment;
use common\classes\modules\ModuleStatus;
use common\classes\modules\ModuleSortOrder;
use backend\services\OrdersService;
use Dpo\Common\Dpo;

/**
 * Class dpopay
 */
class dpopay extends ModulePayment
{
    private const SET_FUNCTION = 'tep_cfg_pull_down_order_statuses(';
    private const USE_FUNCTION = '\\common\\helpers\\Order::get_order_status_name';
    private const DPO_PAY      = 'DPO Pay';
    private const YMDHIS       = 'Y-m-d H:i:s';

    public $countries = [];
    public $publicTitle;

    protected $defaultTranslationArray = [
        'MODULE_PAYMENT_DPOPAY_TEXT_TITLE'       => self::DPO_PAY,
        'MODULE_PAYMENT_DPOPAY_TEXT_DESCRIPTION' => 'Pay using DPO Pay',
        'MODULE_PAYMENT_DPOPAY_TEXT_NOTES'       => ''
    ];
    private string $companyToken;
    private string $serviceType;
    private $paidStatus;
    private $processingStatus;
    private $failedStatus;
    private bool $debugLog;

    public function __construct()
    {
        parent::__construct();

        $this->countries   = [];
        $this->code        = 'dpopay';
        $this->title       = defined(
            'MODULE_PAYMENT_DPOPAY_TEXT_TITLE'
        ) ? MODULE_PAYMENT_DPOPAY_TEXT_TITLE : self::DPO_PAY;
        $this->description = defined(
            'MODULE_PAYMENT_DPOPAY_TEXT_DESCRIPTION'
        ) ? MODULE_PAYMENT_DPOPAY_TEXT_DESCRIPTION : 'Pay using DPO Pay';
        $this->enabled     = true;

        if (!defined('MODULE_PAYMENT_DPOPAY_STATUS')) {
            $this->enabled = false;

            return;
        }

        $this->companyToken     = defined(
            'MODULE_PAYMENT_DPOPAY_COMPANY_TOKEN'
        ) ? MODULE_PAYMENT_DPOPAY_COMPANY_TOKEN : '';
        $this->serviceType      = defined(
            'MODULE_PAYMENT_DPOPAY_SERVICE_TYPE'
        ) ? MODULE_PAYMENT_DPOPAY_SERVICE_TYPE : '';
        $this->paidStatus       = MODULE_PAYMENT_DPOPAY_ORDER_PAID_STATUS_ID;
        $this->processingStatus = MODULE_PAYMENT_DPOPAY_ORDER_PROCESS_STATUS_ID;
        $this->failedStatus     = MODULE_PAYMENT_DPOPAY_FAIL_PAID_STATUS_ID;
        $this->debugLog         = false;
        if (defined('MODULE_PAYMENT_DPOPAY_DEBUG_MODE')) {
            $this->debugLog = MODULE_PAYMENT_DPOPAY_DEBUG_MODE === 'True';
        }

        $this->ordersService = \Yii::createObject(OrdersService::class);
        $this->update();
    }

    public function updateTitle($platformId = 0)
    {
        $mode = $this->get_config_key((int)$platformId, 'MODULE_PAYMENT_DPOPAY_TEST_MODE');
        if ($mode !== false) {
            $mode  = strtolower($mode);
            $title = (defined('MODULE_PAYMENT_DPOPAY_TEXT_TITLE') ? constant(
                'MODULE_PAYMENT_DPOPAY_TEXT_TITLE'
            ) : '');
            if ($title != '') {
                $this->title = $title;
                if ($mode == 'true') {
                    $this->title .= ' [Test]';
                }
            }
            $titlePublic = (defined('MODULE_PAYMENT_DPOPAY_TEXT_TITLE') ? constant(
                'MODULE_PAYMENT_DPOPAY_TEXT_TITLE'
            ) : '');
            if ($titlePublic != '') {
                $this->publicTitle = $titlePublic;
                if ($mode == 'true') {
                    $this->publicTitle .= " [{$this->code}; Test]";
                }
            }

            return true;
        }

        return false;
    }


    public function getTitle($method = '')
    {
        return $this->publicTitle;
    }

    private function update()
    {
        if (!$this->companyToken || !$this->serviceType) {
            $this->enabled = false;
        }
    }

    public function javascript_validation()
    {
        return false;
    }

    /**
     * Creates DPO token and redirects to payment portal
     *
     * @return void
     * @throws \Exception
     */
    public function process_button(): void
    {
        $order                       = $this->manager->getOrderInstance();
        $order->info['order_status'] = $this->processingStatus;
        $order->save_order();
        $order->save_totals();
        $order->save_products(false);

        $dpo  = new Dpo(false);
        $data = [
            'serviceType'       => $this->serviceType,
            'customerPhone'     => $order->customer['telephone'],
            'customerDialCode'  => '',
            'customerZip'       => $order->customer['postcode'],
            'customerCountry'   => $order->customer['country']['iso_code_2'],
            'customerAddress'   => $order->customer['street_address'],
            'customerCity'      => $order->customer['city'],
            'customerEmail'     => $order->customer['email_address'],
            'customerFirstName' => $order->customer['firstname'],
            'customerLastName'  => $order->customer['lastname'],
            'companyToken'      => $this->companyToken,
            'paymentAmount'     => $order->info['total'],
            'paymentCurrency'   => $order->info['currency'],
            'companyRef'        => $order->order_id,
            'redirectURL'       => tep_href_link(
                'callback/webhooks.payment.' . $this->code,
                "action=redirect&orders_id=$order->order_id&reference=$order->order_id",
                'SSL'
            ),
        ];

        $token = $dpo->createToken($data);
        if (isset($token['success']) && $token['success'] && $token['result'] === '000') {
            $data['transToken'] = $token['transToken'];
            $verified           = null;
            while ($verified === null) {
                $verifyString = $dpo->verifyToken($data);
                if (str_starts_with($verifyString, '<?xml')) {
                    $verify = new \SimpleXMLElement($verifyString);

                    if ($verify->Result->__toString() === '900') {
                        $payUrl = Dpo::$livePayUrl . '?ID=' . $data['transToken'];
                        header('Location: ' . $payUrl);
                        die();
                    } else {
                        $verified = false;
                    }
                }
            }
        }
    }

    public function after_process()
    {
        $this->manager->clearAfterProcess();
    }

    /**
     * @return ModuleStatus
     */
    public function describe_status_key(): ModuleStatus
    {
        return new ModuleStatus('MODULE_PAYMENT_DPOPAY_STATUS', 'True', 'False');
    }


    public function describe_sort_key()
    {
        return new ModuleSortOrder('MODULE_PAYMENT_DPOPAY_SORT_ORDER');
    }

    public function configure_keys()
    {
        $status_id      = defined(
            'MODULE_PAYMENT_DPOPAY_ORDER_PROCESS_STATUS_ID'
        ) ?
            MODULE_PAYMENT_DPOPAY_ORDER_PROCESS_STATUS_ID :
            $this->getDefaultOrderStatusId();
        $status_id_paid = defined(
            'MODULE_PAYMENT_DPOPAY_ORDER_PAID_STATUS_ID'
        ) ? MODULE_PAYMENT_DPOPAY_ORDER_PAID_STATUS_ID : $this->getDefaultOrderStatusId();
        $status_id_fail = defined(
            'MODULE_PAYMENT_DPOPAY_FAIL_PAID_STATUS_ID'
        ) ? MODULE_PAYMENT_DPOPAY_FAIL_PAID_STATUS_ID : $this->getDefaultOrderStatusId();

        return array(
            'MODULE_PAYMENT_DPOPAY_STATUS'                  => array(
                'title'        => 'Enable DPO Pay Module',
                'value'        => 'True',
                'description'  => 'Do you want to accept payments using DPO Pay?',
                'sort_order'   => '1',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
            'MODULE_PAYMENT_DPOPAY_COMPANY_TOKEN'           => array(
                'title'       => 'Company Token',
                'value'       => '',
                'description' => 'The Company Token received from DPO Pay',
                'sort_order'  => '2',
            ),
            'MODULE_PAYMENT_DPOPAY_SERVICE_TYPE'            => array(
                'title'       => 'Default Service Type',
                'value'       => '',
                'description' => 'The Default Service Type for your account',
                'sort_order'  => '3',
            ),
            'MODULE_PAYMENT_DPOPAY_SORT_ORDER'              => array(
                'title'       => 'Sort order of display.',
                'value'       => '0',
                'description' => 'Sort order of display. Lowest is displayed first.',
                'sort_order'  => '5',
            ),
            'MODULE_PAYMENT_DPOPAY_ORDER_PROCESS_STATUS_ID' => array(
                'title'        => 'Order Processing Status',
                'value'        => $status_id,
                'description'  => 'Set the process status of orders made with this payment module to this value',
                'sort_order'   => '14',
                'set_function' => self::SET_FUNCTION,
                'use_function' => self::USE_FUNCTION,
            ),
            'MODULE_PAYMENT_DPOPAY_ORDER_PAID_STATUS_ID'    => array(
                'title'        => 'Order Paid Status',
                'value'        => $status_id_paid,
                'description'  => 'Set the paid status of orders made with this payment module to this value',
                'sort_order'   => '15',
                'set_function' => self::SET_FUNCTION,
                'use_function' => self::USE_FUNCTION,
            ),
            'MODULE_PAYMENT_DPOPAY_FAIL_PAID_STATUS_ID'     => array(
                'title'        => 'Order Fail Paid Status',
                'value'        => $status_id_fail,
                'description'  => 'Set the fail paid status of orders made with this payment module to this value',
                'sort_order'   => '15',
                'set_function' => self::SET_FUNCTION,
                'use_function' => self::USE_FUNCTION,
            ),
            'MODULE_PAYMENT_DPOPAY_DEBUG_MODE'              => array(
                'title'        => 'Debug mode',
                'value'        => 'False',
                'description'  => 'Sandbox debug mode',
                'sort_order'   => '16',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ),
        );
    }

    public function isOnline()
    {
        return true;
    }

    public function selection()
    {
        $selection = array(
            'id'     => $this->code,
            'module' => $this->title
        );
        if (
            defined(
                'MODULE_PAYMENT_DPOPAY_TEXT_NOTES'
            ) &&
            !empty(MODULE_PAYMENT_DPOPAY_TEXT_NOTES)
        ) {
            $selection['notes'][] = MODULE_PAYMENT_DPOPAY_TEXT_NOTES;
        }

        return $selection;
    }

    /**
     * The DPO Pay redirect response comes here
     *
     * @return void
     * @throws \Exception
     */
    public function call_webhooks()
    {
        if ($_GET['action'] === 'redirect') {
            $this->handleRedirect($this->sanitizeInput($_GET));
        }

        tep_redirect(
            tep_href_link(
                FILENAME_CHECKOUT_PAYMENT,
                'error_message=An error occured while processing the DPO Pay response.',
                'SSL',
                true,
                false
            )
        );
        die();
    }

    /**
     * @param array $get
     *
     * @return void
     * @throws \Exception
     */
    private function handleRedirect(array $get): void
    {
        $orderId  = (int)$get['orders_id'];
        $order    = Orders::findByVar($orderId);
        $currency = Currencies::find()
                              ->where(['code' => $order->currency])
                              ->one();
        if (!$order) {
            tep_redirect(
                tep_href_link(
                    FILENAME_CHECKOUT_PAYMENT,
                    'error_message=An error occured while processing transaction. The order could not be found',
                    'SSL',
                    true,
                    false
                )
            );
            die();
        }

        $dpo  = new Dpo(false);
        $data = [
            'companyToken' => $this->companyToken,
            'transToken'   => $get['TransactionToken'],
        ];

        $verifyString = $dpo->verifyToken($data);
        if (str_starts_with($verifyString, '<?xml')) {
            $verify            = new \SimpleXMLElement($verifyString);
            $result            = $verify->Result->__toString();
            $resultExplanation = $verify->ResultExplanation->__toString();
            if ($result === '000') {
                // Transaction paid
                $transactionAmount = (double)($verify->TransactionAmount->__toString());
                $allocationAmount  = (double)($verify->AllocationAmount->__toString());
                $orderNetAmount    = $transactionAmount - $allocationAmount;

                $order->orders_status  = $this->paidStatus;
                $order->payment_method = $this->code;
                $order->save();
                $orderPayment = OrdersPayment::find()
                                             ->where(['orders_payment_order_id' => $orderId])
                                             ->one();
                if (!$orderPayment) {
                    $orderPayment                             = new OrdersPayment();
                    $orderPayment->orders_payment_date_create = date(self::YMDHIS);
                }
                $orderPayment->orders_payment_order_id       = $orderId;
                $orderPayment->orders_payment_amount         = $orderNetAmount;
                $orderPayment->orders_payment_currency       = $verify->TransactionCurrency->__toString();
                $orderPayment->orders_payment_module         = $this->code;
                $orderPayment->orders_payment_module_name    = self::DPO_PAY;
                $orderPayment->orders_payment_date_update    = date(self::YMDHIS);
                $orderPayment->orders_payment_transaction_id = $verify->TransactionRef->__toString();
                $orderPayment->orders_payment_status         = 20;
                $orderPayment->save();
                $orderTotals = $order->getOrdersTotals()->all();
                foreach ($orderTotals as $orderTotal) {
                    if ($orderTotal->class === 'ot_paid') {
                        $orderTotal->value        = $orderNetAmount;
                        $orderTotal->text         = $currency->symbol_left . number_format(
                                $orderNetAmount,
                                $currency->decimal_places,
                                $currency->decimal_point,
                                $currency->thousands_point
                            );
                        $orderTotal->text_inc_tax = $orderTotal->text;
                        $orderTotal->text_exc_tax = $orderTotal->text;
                    } elseif ($orderTotal->class === 'ot_due') {
                        $orderTotal->value        = 0.00;
                        $orderTotal->text         = $currency->symbol_left . number_format(
                                0.00,
                                $currency->decimal_places,
                                $currency->decimal_point,
                                $currency->thousands_point
                            );
                        $orderTotal->text_inc_tax = $orderTotal->text;
                        $orderTotal->text_exc_tax = $orderTotal->text;
                    }
                    $orderTotal->save();
                }
                CustomersBasket::clearBasket($order->customers_id);
                tep_redirect(
                    tep_href_link(FILENAME_CHECKOUT_SUCCESS, 'orders_id=' . $orderId, 'SSL')
                );
            } elseif ($result === '901') {
                // Declined
                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        'error_message=Transaction has been declined',
                        'SSL',
                        true,
                        false
                    )
                );
            } elseif ($result === '904') {
                // User cancelled
                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        'error_message=User cancelled transaction',
                        'SSL',
                        true,
                        false
                    )
                );
            } else {
                // Transaction failed
                tep_redirect(
                    tep_href_link(
                        FILENAME_CHECKOUT_PAYMENT,
                        "error_message=An error occurred while verifying payment.
                         The transaction could not be verified\n
                         The result was $result and the explanation $resultExplanation",
                        'SSL',
                        true,
                        false
                    )
                );
                die();
            }
        } else {
            tep_redirect(
                tep_href_link(
                    FILENAME_CHECKOUT_PAYMENT,
                    "error_message=An error occurred while verifying payment.",
                    'SSL',
                    true,
                    false
                )
            );
            die();
        }
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $datum) {
            $sanitized[$key] = htmlspecialchars($datum);
        }

        return $sanitized;
    }

    public static function dpoLog(string $msg = '', bool $close = false)
    {
        static $fh;
        $debugMode = false;
        if (defined('MODULE_PAYMENT_DPOPAY_DEBUG_MODE')) {
            $debugMode = MODULE_PAYMENT_DPOPAY_DEBUG_MODE === 'True';
        }

        if ($debugMode) {
            if ($close) {
                fclose($fh);
            } else {
                if (!$fh) {
                    $pathInfo = pathinfo(__DIR__);
                    $path     = $pathInfo['dirname'] . '/orderPayment/lib/dpopay/dpopay.log';
                    $fh       = fopen($path, 'a+');
                }

                if ($fh) {
                    $line = date(self::YMDHIS) . ' : ' . $msg . "\n";

                    try {
                        fwrite($fh, $line);
                    } catch (\Exception $e) {
                        error_log($e, 0);
                    }
                }
            }
        }
    }
}
