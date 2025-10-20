<?php

if (! defined('ABSPATH')) {
    exit;
}

use Money\Money;
use Money\Currency;
use Univapay\Enums\ChargeStatus;
use Univapay\Resources\Authentication\AppJWT;
use Univapay\Resources\Charge;
use Univapay\UnivapayClient;
use Univapay\UnivapayClientOptions;

class WC_Univapay_Gateway extends WC_Payment_Gateway
{
    /**
    * @var string
    */
    protected $widget;

    /**
    * @var string
    */
    protected $api;

    /**
    * @var string
    */
    protected $token;

    /**
    * @var string
    */
    protected $secret;

    /**
    * @var string (yes|no)
    */
    protected $capture;

    /**
    * @var string
    */
    protected $status;

    /**
    * @var string
    */
    protected $formurl;

    /**
     * @var AppJWT
     */
    protected $app_jwt;

    /**
     * @var UnivapayClient
     */
    protected $univapay_client;

    /**
     * @var UnivapayClientOptions
     */
    protected $univapay_client_options;

    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        throw new Exception("Property $name does not exist");
    }

    public function __set($name, $value)
    {
        if (property_exists($this, $name)) {
            $this->$name = $value;
            return;
        }
        throw new Exception("Property $name does not exist");
    }

    /**
    * Class constructor
    */
    public function __construct()
    {
        $this->id = 'upfw';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'Univapay Gateway';
        $this->method_description = __('UnivaPayで様々な決済手段を提供します', 'upfw');

        $this->init_form_fields();
        $this->init_settings();

        $this->title   = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->widget  = $this->get_option('widget');
        $this->api     = $this->get_option('api');
        $this->token   = $this->get_option('token');
        $this->secret  = $this->get_option('secret');
        $this->capture = $this->get_option('capture');
        $this->status  = $this->get_option('status');
        $this->formurl = $this->get_option('formurl');

        $this->app_jwt = null;
        $this->univapay_client = null;
        $this->univapay_client_options = new UnivapayClientOptions($this->api);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('template_redirect', array($this, 'process_redirect_payment'));

        // Webhook 受け口（?wc-api=upfw）
        add_action('woocommerce_api_upfw', array($this, 'webhook'));

        // 内部リトライ用フック（Action Scheduler があればそれ経由、無くても wp-cron で動く）
        add_action('upfw_retry_webhook', array($this, 'retry_webhook_handler'), 10, 1);

        // 管理画面：課金ID表示
        add_action('woocommerce_admin_order_data_after_order_details', function ($order) {
            $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
            $univapay_charge_id = get_post_meta($order_id, 'univapay_charge_id', true);
            if ($univapay_charge_id) {
                echo '<div class="form-field form-field-wide">';
                echo '<p><strong>' . __('課金ID') . ':</strong> ' . esc_html($univapay_charge_id) . '</p>';
                echo '</div>';
            }
        });
    }

    /**
    * Plugin options
    */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('有効/無効', 'upfw'),
                'label'       => 'UnivaPay Gatewayを有効にする',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('タイトル', 'upfw'),
                'type'        => 'text',
                'description' => __('これは、ユーザーがチェックアウト時に表示するタイトルを制御します。', 'upfw'),
                'default'     => __('UnivaPay', 'upfw'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('説明', 'upfw'),
                'type'        => 'textarea',
                'description' => __('これは、チェックアウト時にユーザーが見る説明を制御します。', 'upfw'),
                'default'     => __('この支払はUnivaPayを介して行われます。', 'upfw'),
            ),
            'widget' => array(
                'title'       => __('ウィジェット URL', 'upfw'),
                'type'        => 'text',
                'default'     => 'https://widget.univapay.com'
            ),
            'api' => array(
                'title'       => __('API URL', 'upfw'),
                'type'        => 'text',
                'default'     => 'https://api.univapay.com'
            ),
            'token' => array(
                'title'       => __('トークン', 'upfw'),
                'type'        => 'text'
            ),
            'secret' => array(
                'title'       => __('シークレット', 'upfw'),
                'type'        => 'password'
            ),
            'capture' => array(
                'title'       => __('有効/無効', 'upfw'),
                'label'       => '常時Captureを取る',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'status' => array(
                'title'       => __('オーソリ時のステータス', 'upfw'),
                'label'       => 'オーソリ完了後作成される注文データのステータス',
                'type'        => 'select',
                'description' => '',
                'default'     => 'on-hold',
                'options'     => array(
                    'on-hold' => '保留',
                    'processing' => '処理中',
                    'pending-payment' => '支払待ち'
                )
            ),
            'formurl' => array(
                'title'       => __('フォームURL', 'upfw'),
                'label'       => 'カード決済以外のフォーム用URL',
                'type'        => 'text',
                'description' => '?appIdより前のURLを入力してください。',
                'default'     => ''
            ),
        );
    }

    public function payment_scripts()
    {
        if (! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order'])) {
            return;
        }
        if ('no' === $this->enabled) {
            return;
        }
        if (empty($this->token)) {
            return;
        }

        $univapay_asset_file = include(plugin_dir_path(__DIR__) . 'dist/univapay.bundle.asset.php');

        wp_enqueue_script('univapay_checkout', $this->widget . '/client/checkout.js', array(), null, true);
        wp_enqueue_script(
            'univapay_woocommerce',
            plugin_dir_url(__DIR__) . 'dist/univapay.bundle.js',
            array('jquery', 'univapay_checkout'),
            $univapay_asset_file['version'],
            true
        );

        if (isset($_GET['order-pay'])) {
            $order = wc_get_order( get_query_var( 'order-pay' ) );
            wp_localize_script('univapay_woocommerce', 'univapay_params', array(
                'app_id' => $this->token,
                'formurl' => $this->formurl,
                'total' =>  $order->get_total(),
                'capture' => ($this->capture === 'yes') ? 'true' : 'false',
                'currency' => strtolower(get_woocommerce_currency()),
                'order_id' => $order->get_id(),
            ));
        } else {
            wp_localize_script('univapay_woocommerce', 'univapay_params', array(
                'app_id' => $this->token,
                'formurl' => $this->formurl,
                'capture' => ($this->capture === 'yes') ? 'true' : 'false',
                'currency' => strtolower(get_woocommerce_currency()),
            ));
        }
    }

    public function process_payment($order_id)
    {
        if (isset($_POST['validation_only'])) {
            return array(
                'result' => 'success',
            );
        }

        $order = wc_get_order($order_id);
        $capture = $this->capture === 'yes';
        $money = new Money($order->get_data()["total"], new Currency($order->get_data()["currency"]));

        // Optional redirect flow
        if (isset($_POST['univapay_optional']) && $_POST['univapay_optional'] === 'true') {
            return array(
                'result' => 'success',
                'redirect' => $this->formurl .
                    '?appId=' . $this->token .
                    '&emailAddress=' . $order->get_data()["billing"]["email"] .
                    '&name=' . $order->get_data()["billing"]["first_name"] . ' ' . $order->get_data()["billing"]["last_name"] .
                    '&phoneNumber=' . $order->get_data()["billing"]["phone"] .
                    '&auth=' . ($capture ? 'false' : 'true') .
                    '&amount=' . $money->getAmount() .
                    '&currency=' . $money->getCurrency() .
                    '&order_id=' . $order_id .
                    '&successRedirectUrl=' . urlencode($this->get_return_url($order)) .
                    '&failureRedirectUrl=' . urlencode($this->get_return_url($order)) .
                    '&pendingRedirectUrl=' . urlencode($this->get_return_url($order))
            );
        }

        // Univapay ウィジェットからの POST（hidden）
        if (!isset($_POST["univapayChargeId"]) && !isset($_POST["univapay_charge_id"])) {
            wc_add_notice(__('決済エラーサイト管理者にお問い合わせください。', 'upfw'), 'error');
            return;
        }
        $chargeId = isset($_POST["univapayChargeId"]) ? wc_clean(wp_unslash($_POST["univapayChargeId"])) : wc_clean(wp_unslash($_POST["univapay_charge_id"]));

        // ThankYou 到達前でも突合できるように一次保存
        update_post_meta($order_id, 'univapay_charge_id', $chargeId);
        update_post_meta($order_id, '_upfw_charge_id', $chargeId);

        return array(
            'result'  => 'success',
            'redirect'=> add_query_arg('univapayChargeId', $chargeId, $this->get_return_url($order))
        );
    }

    /**
     * Charge バリデーション（Enum equals無し → getValue()で比較）
     */
    public function is_charge_valid($charge, $order)
    {
        if (!empty($charge->error)) {
            return false;
        }

        $status_val = is_object($charge->status) && method_exists($charge->status, 'getValue')
            ? $charge->status->getValue()
            : (string) $charge->status;

        $ok_statuses = [
            ChargeStatus::SUCCESSFUL()->getValue(),
            ChargeStatus::AUTHORIZED()->getValue(),
        ];

        if (!in_array($status_val, $ok_statuses, true)) {
            error_log('Invalid order ID: ' . $order->get_id() . ' with charge status: ' . $status_val);
            return false;
        }

        return true;
    }

    /**
     * ThankYou での最終確定処理
     */
    public function process_redirect_payment()
    {
        if (getenv('WP_ENV') !== 'test') {
            if (! is_order_received_page() || empty($_GET['univapayChargeId'])) {
                return;
            }
        } else {
            if (empty($_GET['univapayChargeId'])) {
                return;
            }
        }

        try {
            global $wp;
            $order_id = absint($wp->query_vars['order-received']);
            $order = wc_get_order($order_id);

            $token = $this->app_jwt ? $this->app_jwt::createToken($this->token, $this->secret) : AppJWT::createToken($this->token, $this->secret);
            if ($this->univapay_client === null) {
                $this->univapay_client = new UnivapayClient($token, $this->univapay_client_options);
            }
            $charge = $this->univapay_client->getCharge($token->storeId, $_GET['univapayChargeId']);

            if ( ! $this->is_charge_valid($charge, $order) ) {
                wc_add_notice(__('決済エラー入力内容を確認してください', 'upfw'), 'error');
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }

            $paymentTypeObj = $this->univapay_client->getTransactionToken($charge->transactionTokenId)->paymentType;
            $paymentType = is_object($paymentTypeObj) && is_callable([$paymentTypeObj, 'getValue'])
                ? (string) $paymentTypeObj->getValue()
                : (string) $paymentTypeObj;
            $paymentType = strtolower(trim($paymentType));

            $capture = ($this->capture === 'yes');

            if ($capture || !in_array($paymentType, ['card', 'paidy'], true)) {
                $order->payment_complete();
                $order->add_order_note(__('UnivaPayでの支払が完了いたしました。', 'upfw'), true);
            } else {
                $order->update_status($this->status, __('キャプチャ待ちです', 'upfw'));
                $order->add_order_note(__('UnivaPayでのオーソリが完了いたしました。', 'upfw'), true);
            }

            update_post_meta($order_id, 'univapay_charge_id', $charge->id);
        } catch (\Exception $e) {
            error_log(print_r($e, true));
            wc_add_notice(__('決済エラーサイト管理者にお問合せください', 'upfw'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // Charge に Woo 側参照情報をベストエフォートでパッチ
        try {
            $payload = array(
                'metadata' => array_merge(
                    is_array($charge->metadata ?? null) ? $charge->metadata : array(),
                    array('order_id' => (string) $order->get_id())
                ),
                'merchant_transaction_id' => (string) $order->get_order_key(),
            );

            if (is_object($charge) && method_exists($charge, 'patch')) {
                $charge->patch($payload);
            }
        } catch (\Throwable $e) {
            error_log('[UnivaPay] charge patch skipped: ' . $e->getMessage());
        }
    }

    /**
     * Webhook 受信
     * - 見つからない場合は 200 を返し、内部で 30秒後→60秒後 の最大2回だけリトライ
     */
    public function webhook()
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST ? array_map('wc_clean', wp_unslash($_POST)) : [];
        }

        $event  = isset($payload['event']) ? (string)$payload['event'] : '';
        $data   = isset($payload['data'])  ? (array)$payload['data']  : [];
        $cid    = isset($data['id']) ? (string)$data['id'] : '';
        $status = isset($data['status']) ? strtolower((string)$data['status']) : '';

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info('UPFW webhook hit', [
                'source' => 'upfw',
                'event'  => $event,
                'status' => $status,
                'cid'    => $cid,
            ]);
        }

        if (!$cid) {
            status_header(204);
            exit;
        }

        $order = $this->find_order_by_charge_id($cid);
        if (!$order) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('UPFW webhook: order not found for charge (defer internal retry)', [
                    'source'=>'upfw', 'cid'=>$cid, 'event'=>$event, 'status'=>$status
                ]);
            }
            $this->store_pending_webhook($cid, $payload);
            $this->schedule_retry($cid, 30); // 30秒後に1回目
            status_header(200);
            echo 'DEFERRED';
            exit;
        }

        $this->apply_webhook_to_order($order, $event, $status, $cid);
        status_header(200);
        echo 'OK';
        exit;
    }

    /* ====== 内部リトライ関連 ====== */

    private function store_pending_webhook($cid, array $payload)
    {
        update_option('upfw_pending_'.$cid, wp_json_encode($payload), false);
        update_option('upfw_retry_attempts_'.$cid, 0, false);
    }

    private function clear_pending_webhook($cid)
    {
        delete_option('upfw_pending_'.$cid);
        delete_option('upfw_retry_attempts_'.$cid);
    }

    private function schedule_retry($cid, $delay)
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + (int)$delay, 'upfw_retry_webhook', [$cid], 'upfw');
        } else {
            wp_schedule_single_event(time() + (int)$delay, 'upfw_retry_webhook', [$cid]);
        }
    }

    /**
     * 30秒後 → 60秒後 の最大2回のみ
     */
    public function retry_webhook_handler($cid)
    {
        $payload_json = get_option('upfw_pending_'.$cid);
        if (!$payload_json) {
            return;
        }
        $payload = json_decode($payload_json, true) ?: [];
        $attempts = (int) get_option('upfw_retry_attempts_'.$cid, 0);

        $event  = isset($payload['event']) ? (string)$payload['event'] : '';
        $data   = isset($payload['data'])  ? (array)$payload['data']  : [];
        $status = isset($data['status']) ? strtolower((string)$data['status']) : '';

        $order = $this->find_order_by_charge_id($cid);
        if ($order) {
            $this->apply_webhook_to_order($order, $event, $status, $cid);
            $this->clear_pending_webhook($cid);
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info('UPFW retry resolved', ['source'=>'upfw','cid'=>$cid,'attempts'=>$attempts]);
            }
            return;
        }

        if ($attempts === 0) {
            update_option('upfw_retry_attempts_'.$cid, 1, false);
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('UPFW retry re-scheduled (final in 60s)', ['source'=>'upfw','cid'=>$cid]);
            }
            $this->schedule_retry($cid, 60);
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error('UPFW retry gave up (order not found after 2 attempts)', ['source'=>'upfw','cid'=>$cid]);
        }
        $this->clear_pending_webhook($cid);
    }

    /**
     * 課金IDから注文を探す（meta_query → 直接SQL の順）
     */
    private function find_order_by_charge_id($cid)
    {
        $orders = wc_get_orders([
            'limit'      => 1,
            'return'     => 'objects',
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => 'univapay_charge_id',
                    'value'   => $cid,
                    'compare' => '=',
                ],
                [
                    'key'     => '_upfw_charge_id',
                    'value'   => $cid,
                    'compare' => '=',
                ],
            ],
        ]);
        if ($orders && $orders[0]) {
            return $orders[0];
        }

        global $wpdb;
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('univapay_charge_id','_upfw_charge_id')
               AND meta_value = %s
             ORDER BY post_id DESC LIMIT 1",
            $cid
        ));
        if ($order_id) {
            return wc_get_order((int)$order_id);
        }
        return null;
    }

    /**
     * Webhookの内容を注文へ適用
     */
    private function apply_webhook_to_order($order, $event, $status, $cid)
    {
        if (in_array($order->get_status(), ['processing','completed','on-hold'], true)) {
            return;
        }

        if ($event === 'charge_finished') {
            if ($status === 'successful' || $status === 'authorized') {
                $order->payment_complete($cid);
                $order->add_order_note(__('UnivaPay決済が完了しました (自動案内)。', 'upfw'), true);
                $order->save();
                return;
            }

            if (in_array($status, ['failed','canceled','cancelled','void'], true)) {
                $order->update_status('failed', __('UnivaPay決済が失敗/取消 (自動案内)。', 'upfw'), true);
                $order->save();
                return;
            }
        }
    }
}
