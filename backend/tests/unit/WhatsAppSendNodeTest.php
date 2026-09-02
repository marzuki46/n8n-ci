<?php

use App\Nodes\WhatsAppSendNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Stub node: tangkap payload Fonnte tanpa jaringan.
 */
final class WaStubNode extends WhatsAppSendNode
{
    public array $calls = [];
    public bool $failApi = false;

    protected function sendFonnte(string $token, string $target, string $message): string
    {
        $this->calls[] = ['token' => $token, 'target' => $target, 'message' => $message];

        if ($this->failApi) {
            return json_encode(['status' => false, 'reason' => 'device disconnected']);
        }

        return json_encode(['status' => true, 'id' => 'msg-' . count($this->calls)]);
    }
}

/**
 * Node WhatsApp Send (gateway Fonnte).
 *
 * @internal
 */
final class WhatsAppSendNodeTest extends CIUnitTestCase
{
    private WorkflowContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = new WorkflowContext(['id' => 1, 'workspace_id' => 1]);
        $this->ctx->parameters['credential'] = ['token' => 'FNT-TOKEN-123'];
    }

    public function testSendsWithResolvedExpression(): void
    {
        $node = new WaStubNode();
        $out  = $node->execute(
            [['json' => ['orderId' => 999, 'total' => 'Rp1.500.000']]],
            [
                'provider' => 'fonnte',
                'target'   => '6281234567890',
                'message'  => 'Order #{{$json.orderId}} total {{$json.total}}',
            ],
            $this->ctx
        );

        $this->assertCount(1, $node->calls);
        $call = $node->calls[0];
        $this->assertSame('FNT-TOKEN-123', $call['token']);
        $this->assertSame('6281234567890', $call['target']);
        $this->assertSame('Order #999 total Rp1.500.000', $call['message']);

        $d = $out['main'][0]['json'];
        $this->assertTrue((bool) ($d['wa_sent'] ?? false));
        $this->assertSame(999, $d['orderId']); // field input terbawa
    }

    public function testPerItemSending(): void
    {
        $node = new WaStubNode();
        $out  = $node->execute(
            [
                ['json' => ['orderId' => 1]],
                ['json' => ['orderId' => 2]],
            ],
            [
                'target'  => '628xxx',
                'message' => 'Order {{$json.orderId}} selesai',
            ],
            $this->ctx
        );

        $this->assertCount(2, $node->calls);
        $this->assertSame('Order 1 selesai', $node->calls[0]['message']);
        $this->assertSame('Order 2 selesai', $node->calls[1]['message']);
    }

    public function testApiFailureThrowsWithReason(): void
    {
        $node = new WaStubNode();
        $node->failApi = true;

        try {
            $node->execute(
                [['json' => []]],
                ['target' => '628xxx', 'message' => 'halo'],
                $this->ctx
            );
            $this->fail('Harusnya throw.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('disconnected', $e->getMessage());
        }
    }

    public function testMissingCredentialThrows(): void
    {
        $ctx = new WorkflowContext(['id' => 1]); // tanpa credential
        $node = new WaStubNode();

        try {
            $node->execute([['json' => []]], ['target' => 'x', 'message' => 'y'], $ctx);
            $this->fail('Harusnya throw tanpa credential.');
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('fonnte', $e->getMessage());
        }
    }
}
