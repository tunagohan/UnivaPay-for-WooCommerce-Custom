<?php

namespace Univapay\WooCommerce\Tests;

use Mockery;
use Mockery\MockInterface;
use Money\Money;
use Money\Currency;
use Univapay\Resources\Authentication\AppJWT;
use Univapay\UnivapayClient;
use Univapay\Enums\ChargeStatus;

class TestPaymentProcessing extends BasePluginTest
{
    public function test_redirect_url_is_generated_correctly()
    {
        $order = $this->initiate_mock_order($this->initiate_mock_product());
        $_POST['univapay_optional'] = 'true';
        $result = $this->payment_gateways['upfw']->process_payment($order->get_id());
        $money = new Money($order->get_data()["total"], new Currency($order->get_data()["currency"]));

        $expectedRedirectUrl = $this->payment_gateways['upfw']->formurl .
            '?appId=' . $this->payment_gateways['upfw']->token .
            '&emailAddress=' . $order->get_billing_email() .
            '&name=' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() .
            '&phoneNumber=' . $order->get_billing_phone() .
            '&auth=' . ($this->payment_gateways['upfw']->capture === 'yes' ? 'false' : 'true') .
            '&amount=' . $money->getAmount() .
            '&currency=' . $money->getCurrency() .
            '&order_id=' . $order->get_id() .
            '&successRedirectUrl=' . urlencode($this->payment_gateways['upfw']->get_return_url($order)) .
            '&failureRedirectUrl=' . urlencode($this->payment_gateways['upfw']->get_return_url($order)) .
            '&pendingRedirectUrl=' . urlencode($this->payment_gateways['upfw']->get_return_url($order));

        $this->assertEquals("success", $result['result'], 'Result does not match.');
        $this->assertEquals($expectedRedirectUrl, $result['redirect'], 'Redirect URL does not match.');
    }

    /**
     * Initiates a mock charge
     * @param WC_Order $order The order to be used in the mock charge.
     * @return object The initiated mock charge.
     */
    private function initiate_mock_charge($order): object
    {
        return (object) [
            'id' => $this->faker->uuid,
            'metadata' => ['order_id' => $order->get_id()],
            'transactionTokenId' => $this->faker->uuid,
            'error' => false,
            'status' => ChargeStatus::SUCCESSFUL(),
        ];
    }

    /**
     * Initiates a mock AppJWT.
     * @return MockInterface The initiated mock AppJWT.
     */
    private function initiate_mock_app_jwt(): MockInterface
    {
        return Mockery::mock('alias:' . AppJWT::class)
            ->shouldReceive('createToken')
            ->andReturn((object) [
                'storeId' => $this->faker->uuid
            ])
            ->getMock();
    }

    /**
     * Initiates a mock client.
     * @param object $mock_charge The mock charge to be returned by the client.
     * @return MockInterface The initiated mock client.
     */
    private function initiate_mock_client($mock_charge): MockInterface
    {
        $mock_payment_type = Mockery::mock();
        $mock_payment_type->shouldReceive('getValue')->andReturn('card');

        $mock_transaction_token = Mockery::mock();
        $mock_transaction_token->paymentType = $mock_payment_type;

        $mock_client = Mockery::mock(UnivapayClient::class);
        $mock_client->shouldReceive('getCharge')->andReturn($mock_charge);
        $mock_client->shouldReceive('getTransactionToken')->andReturn($mock_transaction_token);

        return $mock_client;
    }

    public function test_process_payment_validation()
    {
        $this->payment_gateways['upfw']->capture = 'no';
        $_POST['univapay_optional'] = "false";

        $mock_order1 = $this->initiate_mock_order($this->initiate_mock_product());
        $result1 = $this->payment_gateways['upfw']->process_payment($mock_order1->get_id());

        $this->assertNull($result1);
        $error_messages = array_column(wc_get_notices('error'), 'notice');
        $this->assertContains('決済エラーサイト管理者にお問い合わせください。', $error_messages);
    }

    public function order_payment_data_provider()
    {
        // Note: WooCommerce order status list
        // pending, processing, on-hold, completed, cancelled, refunded, failed
        return [
            // Scenario 1: Capture is set to 'no', check base plugin test for the expected status
            ['no', 'pending', 'UnivaPayでのオーソリが完了いたしました。'],
            // Scenario 2: Capture is set to 'yes'
            ['yes', 'processing', 'UnivaPayでの支払が完了いたしました。'],
        ];
    }

    /**
     * @dataProvider order_payment_data_provider
     */
    public function test_process_order_payment($capture, $expected_status, $expected_note)
    {
        $this->payment_gateways['upfw']->capture = $capture;
        $_POST['univapay_optional'] = "false";
        $mock_charge_token = $this->faker->word;
        $_POST['univapay_charge_id'] = $mock_charge_token;

        $mock_order = $this->initiate_mock_order($this->initiate_mock_product());
        $result = $this->payment_gateways['upfw']->process_payment($mock_order->get_id());

        $this->assertEquals('success', $result['result'], 'Payment processing did not return success.');
        $this->assertStringContainsString('order-received=' . $mock_order->get_id(), $result['redirect'], 'Redirect URL does not contain order-received.');
        $this->assertStringContainsString('key=' . $mock_order->get_order_key(), $result['redirect'], 'Redirect URL does not contain key.');

        // simulate the order completion process
        WC()->session->set('order_awaiting_payment', $mock_order->get_id());
        $_GET['univapayChargeId'] = $mock_charge_token;
        global $wp;
        $wp->query_vars['order-received'] = $mock_order->get_id();
        $mock_charge = $this->initiate_mock_charge($mock_order);
        $mock_client = $this->initiate_mock_client($mock_charge);
        $mock_app_jwt = $this->initiate_mock_app_jwt();
        $this->payment_gateways['upfw']->app_jwt = $mock_app_jwt;
        $this->payment_gateways['upfw']->univapay_client = $mock_client;
        $this->payment_gateways['upfw']->process_redirect_payment();
        $result_order_notes = wc_get_order_notes(['order_id' => $mock_order->get_id()]);

        $result_order = wc_get_order($mock_order->get_id());
        $this->assertEquals($mock_charge->id, get_post_meta($result_order->get_id(), 'univapay_charge_id', true), 'Charge ID should be saved.');
        $this->assertEquals($expected_status, $result_order->get_status(), 'Order status does not match the expected status.');
        $this->assertContains($expected_note, array_column($result_order_notes, 'content'), 'Order note does not contain expected status change message.');
    }

    /**
     * Verify that Charge.patch is called with order_id and merchant_transaction_id included.
     * - metadata.order_id is Woo's order ID
     * - merchant_transaction_id is Woo's order key
     */
    public function test_process_redirect_payment_patches_charge_with_order_refs()
    {
        $this->payment_gateways['upfw']->capture = 'yes';
        $_POST['univapay_optional'] = "false";

        $mock_charge_token = $this->faker->uuid;
        $_POST['univapay_charge_id'] = $mock_charge_token;
        $order = $this->initiate_mock_order($this->initiate_mock_product());

        $result = $this->payment_gateways['upfw']->process_payment($order->get_id());
        $this->assertEquals('success', $result['result']);
        $this->assertStringContainsString('order-received=' . $order->get_id(), $result['redirect']);

        WC()->session->set('order_awaiting_payment', $order->get_id());
        $_GET['univapayChargeId'] = $mock_charge_token;
        global $wp;
        $wp->query_vars['order-received'] = $order->get_id();

        $expected_order_id  = $order->get_id();
        $expected_order_key = $order->get_order_key();

        $mock_charge = Mockery::mock();
        $mock_charge->id = $mock_charge_token;
        $mock_charge->transactionTokenId = $this->faker->uuid;
        $mock_charge->error = false;
        $mock_charge->status = \Univapay\Enums\ChargeStatus::SUCCESSFUL();
        $mock_charge->metadata = [];

        $mock_charge->shouldReceive('patch')
            ->once()
            ->with(Mockery::on(function ($arg) use ($expected_order_id, $expected_order_key) {
                if (!is_array($arg)) return false;
                if (!isset($arg['metadata']) || !is_array($arg['metadata'])) return false;
                if (!isset($arg['metadata']['order_id'])) return false;
                if ((int)$arg['metadata']['order_id'] !== (int)$expected_order_id) return false;

                if (!isset($arg['merchant_transaction_id'])) return false;
                if ($arg['merchant_transaction_id'] !== $expected_order_key) return false;

                return true;
            }));

        $mock_payment_type = Mockery::mock();
        $mock_payment_type->shouldReceive('getValue')->andReturn('card');
        $mock_transaction_token = Mockery::mock();
        $mock_transaction_token->paymentType = $mock_payment_type;

        $mock_client = Mockery::mock(\Univapay\UnivapayClient::class);
        $mock_client->shouldReceive('getCharge')->andReturn($mock_charge);
        $mock_client->shouldReceive('getTransactionToken')->andReturn($mock_transaction_token);

        $mock_app_jwt = Mockery::mock('alias:' . \Univapay\Resources\Authentication\AppJWT::class)
            ->shouldReceive('createToken')
            ->andReturn((object)['storeId' => $this->faker->uuid])
            ->getMock();

        $this->payment_gateways['upfw']->app_jwt = $mock_app_jwt;
        $this->payment_gateways['upfw']->univapay_client = $mock_client;

        $this->payment_gateways['upfw']->process_redirect_payment();

        $saved = wc_get_order($order->get_id());
        $this->assertEquals('processing', $saved->get_status(), 'Charge SUCCESS + capture=YES は processing になるはず');
        $this->assertEquals($mock_charge_token, get_post_meta($saved->get_id(), 'univapay_charge_id', true), 'Charge ID should be saved.');
    }
}
