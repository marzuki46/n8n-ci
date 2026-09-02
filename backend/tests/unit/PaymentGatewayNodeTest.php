<?php

use App\Nodes\MidtransVerifyNode;
use App\Nodes\TripayInvoiceNode;
use App\Nodes\TripayVerifyNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Payment gateway lokal: verifikasi signature Midtrans & Tripay,
 * buat invoice Tripay (stub HTTP).
 *
 * @internal
 */
final class PaymentGatewayNodeTest extends CIUnitTestCase
{
    private WorkflowContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = new WorkflowContext(['id' => 1, 'workspace_id' => 1]);
    }

    // ============================ Midtrans ============================

    private function midtransPayload(string $serverKey, string $orderId, string $statusCode, string $amount): array
    {
        return [
            'order_id'           => $orderId,
            'status_code'        => $statusCode,
            'gross_amount'       => $amount,
            'signature_key'      => hash('sha512', $orderId . $statusCode . $amount . $serverKey),
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
        ];
    }

    public function testMidtransValidSignaturePassesAndMarksPaid(): void
    {
        $node  = new MidtransVerifyNode();
        $ctx   = new WorkflowContext(['id' => 1]);
        $ctx->parameters['credential'] = ['server_key' => 'SB-Mid-server-abc'];
        $payload = $this->midtransPayload('SB-Mid-server-abc', 'ORDER-101', '200', '150000.00');

        $out = $node->execute([['json' => $payload]], ['on_invalid' => 'fail'], $ctx);

        $d = $out['main'][0]['json'];
        $this->assertTrue($d['valid']);
        $this->assertTrue($d['paid']);
        $this->assertSame('ORDER-101', $d['order_id']);
    }

    public function testMidtransTamperedSignatureFails(): void
    {
        $node  = new MidtransVerifyNode();
        $ctx   = new WorkflowContext(['id' => 1]);
        $ctx->parameters['credential'] = ['server_key' => 'SB-Mid-server-abc'];
        $payload = $this->midtransPayload('SB-Mid-server-abc', 'ORDER-101', '200', '150000.00');
        $payload['gross_amount'] = '999999.00'; // diubah penipu, signature tidak dihitung ulang.

        try {
            $node->execute([['json' => $payload]], ['on_invalid' => 'fail'], $ctx);
            $this->fail('Signature tamper harus throw.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('tidak valid', $e->getMessage());
        }
    }

    public function testMidtransInvalidWithPassModeContinues(): void
    {
        $node  = new MidtransVerifyNode();
        $ctx   = new WorkflowContext(['id' => 1]);
        $ctx->parameters['credential'] = ['server_key' => 'key-x'];
        $payload = [
            'order_id' => 'O', 'status_code' => '200', 'gross_amount' => '100.00',
            'signature_key' => 'salah', 'transaction_status' => 'pending',
        ];

        $out = $node->execute([['json' => $payload]], ['on_invalid' => 'pass'], $ctx);
        $d = $out['main'][0]['json'];

        $this->assertFalse($d['valid']);
        $this->assertFalse($d['paid']);
    }

    public function testMidtransPendingIsNotPaid(): void
    {
        $node  = new MidtransVerifyNode();
        $ctx   = new WorkflowContext(['id' => 1]);
        $ctx->parameters['credential'] = ['server_key' => 'k'];
        $p = $this->midtransPayload('k', 'O-2', '200', '50000.00');
        $p['transaction_status'] = 'pending';
        $p['fraud_status'] = '';

        $out = $node->execute([['json' => $p]], [], $ctx);
        $this->assertTrue($out['main'][0]['json']['valid']);
        $this->assertFalse($out['main'][0]['json']['paid']);
    }

    // ============================ Tripay Verify ============================

    public function testTripayValidHmacPasses(): void
    {
        $privateKey = 'priv-key-123';
        $rawBody    = '{"event":"payment_status","merchant_ref":"INV-7","status":"PAID"}';
        $sig        = hash_hmac('sha256', $rawBody, $privateKey);
        $this->ctx->parameters['credential'] = ['private_key' => $privateKey];

        $node = new TripayVerifyNode();
        $out  = $node->execute(
            [['json' => ['raw' => $rawBody, 'signature' => $sig]]],
            ['raw_body_field' => 'raw', 'signature_field' => 'signature'],
            $this->ctx
        );

        $d = $out['main'][0]['json'];
        $this->assertTrue($d['valid']);
        $this->assertSame('INV-7', $d['merchant_ref']);
        $this->assertTrue($d['paid']);
    }

    public function testTripayBadSignatureThrows(): void
    {
        $this->ctx->parameters['credential'] = ['private_key' => 'k'];
        $node = new TripayVerifyNode();

        try {
            $node->execute(
                [['json' => ['raw' => '{"status":"PAID"}', 'signature' => 'ngasal']]],
                [],
                $this->ctx
            );
            $this->fail('Harusnya throw.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('tidak valid', $e->getMessage());
        }
    }

    // ============================ Tripay Invoice (stub HTTP) ============================

    public function testTripayInvoiceBuildsSignedPayload(): void
    {
        $stub = new class extends TripayInvoiceNode {
            public array $captured = [];
            protected function httpPostJson(string $url, array $payload, string $apiKey): string
            {
                $this->captured[] = ['url' => $url, 'payload' => $payload, 'api_key' => $apiKey];

                return json_encode([
                    'success' => true,
                    'data'    => [
                        'reference'    => 'TRX-XYZ',
                        'checkout_url' => 'https://tripay.co.id/payment/TRX-XYZ',
                        'pay_code'     => '12345678',
                        'expired_time' => '2026-08-25 10:00:00',
                    ],
                ]);
            }
        };

        $ctx = new WorkflowContext(['id' => 1]);
        $ctx->parameters['credential'] = [
            'api_key'       => 'DEV-api',
            'private_key'   => 'DEV-private',
            'merchant_code' => 'T0001',
            'mode'          => 'sandbox',
        ];

        $out = $stub->execute(
            [['json' => ['orderId' => 101, 'amount' => 150000, 'nama' => 'Budi', 'email' => 'b@mail.com']]],
            [
                'method'         => 'BRIVA',
                'amount'         => '{{$json.amount}}',
                'merchant_ref'   => 'INV-{{$json.orderId}}',
                'customer_name'  => '{{$json.nama}}',
                'customer_email' => '{{$json.email}}',
                'item_name'      => 'Order #{{$json.orderId}}',
            ],
            $ctx
        );

        $call = $stub->captured[0];
        $this->assertSame('https://tripay.co.id/api-sandbox/transaction/create', $call['url']);

        $expectedSig = hash_hmac('sha256', 'T0001INV-101150000', 'DEV-private');
        $this->assertSame($expectedSig, $call['payload']['signature']);
        $this->assertSame(150000, $call['payload']['amount']);

        $d = $out['main'][0]['json'];
        $this->assertSame('TRX-XYZ', $d['reference']);
        $this->assertSame('INV-101', $d['merchant_ref']);
        $this->assertSame('https://tripay.co.id/payment/TRX-XYZ', $d['checkout_url']);
    }

    public function testTripayInvoiceRejectsZeroAmount(): void
    {
        $this->ctx->parameters['credential'] = [
            'api_key' => 'k', 'private_key' => 'p', 'merchant_code' => 'T1', 'mode' => 'sandbox',
        ];
        $stub = new class extends TripayInvoiceNode {
            protected function httpPostJson(string $url, array $payload, string $apiKey): string
            {
                return '{"success":true,"data":{}}';
            }
        };

        try {
            $stub->execute(
                [['json' => []]],
                ['method' => 'QRIS', 'amount' => '0'],
                $this->ctx
            );
            $this->fail('Nominal 0 harus ditolak.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('nominal', $e->getMessage());
        }
    }
}
