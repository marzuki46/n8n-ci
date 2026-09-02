<?php

use App\Nodes\AggregateNode;
use App\Nodes\FilterNode;
use App\Nodes\HttpRequestNode;
use App\Nodes\IfNode;
use App\Nodes\LimitNode;
use App\Nodes\LoopNode;
use App\Nodes\ManualTriggerNode;
use App\Nodes\MergeNode;
use App\Nodes\RemoveDuplicatesNode;
use App\Nodes\SetNode;
use App\Nodes\SlackNode;
use App\Nodes\SortNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class NodeUnitTest extends CIUnitTestCase
{
    private WorkflowContext $context;

    /** @var resource|null */
    private $httpServerProc = null;

    private string $httpBase = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new WorkflowContext(['id' => 1, 'name' => 'wf']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->httpServerProc)) {
            $status = proc_get_status($this->httpServerProc);
            if ($status['running']) {
                @exec('taskkill /F /T /PID ' . $status['pid'] . ' 2>NUL');
            }
            proc_close($this->httpServerProc);
            $this->httpServerProc = null;
        }

        parent::tearDown();
    }

    private function startHttpServer(): string
    {
        if ($this->httpBase !== '') {
            return $this->httpBase;
        }

        $port     = 8877;
        $router   = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'http_router.php';
        $php      = PHP_BINARY;
        if (basename($php) === 'php.bat') {
            $php = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
        }
        $cmd = escapeshellarg($php) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);

        $proc = proc_open($cmd, [], $pipes);
        $this->assertIsResource($proc, 'Gagal memulai server HTTP untuk test.');
        $this->httpServerProc = $proc;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            $resp = @file_get_contents('http://127.0.0.1:' . $port . '/ping', false, $ctx);
            if ($resp !== false) {
                break;
            }
            usleep(100000);
        }

        $this->httpBase = 'http://127.0.0.1:' . $port;

        return $this->httpBase;
    }

    private function wrapped(array $data): array
    {
        return ['json' => $data];
    }

    public function testSetNodeAssignsAndRemovesFields(): void
    {
        $node  = new SetNode();
        $input = [$this->wrapped(['name' => 'Budi', 'internal' => 'x'])];

        $params = [
            'assignments'  => '[{"field":"greeting","value":"Halo {{$json.name}}"}]',
            'removeFields' => '["internal"]',
        ];

        $out = $node->execute($input, $params, $this->context);

        $this->assertArrayHasKey('main', $out);
        $this->assertSame(['name' => 'Budi', 'greeting' => 'Halo Budi'], $out['main'][0]['json']);
    }

    public function testIfNodeBranchesByCondition(): void
    {
        $node  = new IfNode();
        $input = [
            $this->wrapped(['score' => 90]),
            $this->wrapped(['score' => 70]),
            $this->wrapped(['score' => 55]),
        ];

        $params = [
            'conditions' => '[{"left":"{{$json.score}}","operator":">","right":"70"}]',
        ];

        $out = $node->execute($input, $params, $this->context);

        $this->assertSame(['true', 'false'], $node->getOutputs());
        $this->assertCount(1, $out['true']);
        $this->assertCount(2, $out['false']);
        $this->assertSame(90, $out['true'][0]['json']['score']);
    }

    public function testFilterNodeMatchAll(): void
    {
        $node  = new FilterNode();
        $input = [
            $this->wrapped(['status' => 'ok-123']),
            $this->wrapped(['status' => 'bad-1']),
            $this->wrapped(['status' => 'ok-456']),
        ];

        $params = [
            'matchType'  => 'all',
            'conditions' => '[{"left":"{{$json.status}}","operator":"contains","right":"ok"}]',
        ];

        $out = $node->execute($input, $params, $this->context);

        $this->assertCount(2, $out['main']);
        $this->assertSame('ok-123', $out['main'][0]['json']['status']);
        $this->assertSame('ok-456', $out['main'][1]['json']['status']);
    }

    public function testFilterNodeMatchAny(): void
    {
        $node  = new FilterNode();
        $input = [
            $this->wrapped(['status' => 'ok-123']),
            $this->wrapped(['status' => 'down']),
        ];

        $params = [
            'matchType' => 'any',
            'conditions' => [
                ['left' => '{{$json.status}}', 'operator' => 'contains', 'right' => 'zzz'],
                ['left' => '{{$json.status}}', 'operator' => 'startsWith', 'right' => 'ok'],
            ],
        ];

        $out = $node->execute($input, $params, $this->context);

        $this->assertCount(1, $out['main']);
        $this->assertSame('ok-123', $out['main'][0]['json']['status']);
    }

    public function testLimitNodeSkipsAndLimits(): void
    {
        $node  = new LimitNode();
        $input = [];
        for ($i = 0; $i < 5; $i++) {
            $input[] = $this->wrapped(['i' => $i]);
        }

        $params = ['maxItems' => 2, 'skipItems' => 1];

        $out = $node->execute($input, $params, $this->context);

        $this->assertCount(2, $out['main']);
        $this->assertSame(1, $out['main'][0]['json']['i']);
        $this->assertSame(2, $out['main'][1]['json']['i']);
    }

    public function testSortNodeAscendingAndDescending(): void
    {
        $node  = new SortNode();
        $input = [
            ['price' => 30],
            ['price' => 10],
            ['price' => 20],
        ];

        $asc = $node->execute($input, ['sortBy' => 'price', 'order' => 'asc', 'type' => 'number'], $this->context);
        $this->assertSame([10, 20, 30], array_column($asc['main'], 'price'));

        $desc = $node->execute($input, ['sortBy' => 'price', 'order' => 'desc', 'type' => 'number'], $this->context);
        $this->assertSame([30, 20, 10], array_column($desc['main'], 'price'));
    }

    public function testRemoveDuplicatesNodeKeepsFirst(): void
    {
        $node  = new RemoveDuplicatesNode();
        $input = [
            ['email' => 'a@x.com'],
            ['email' => 'b@x.com'],
            ['email' => 'a@x.com'],
            ['email' => 'c@x.com'],
        ];

        $out = $node->execute($input, ['keyField' => 'email'], $this->context);

        $this->assertCount(3, $out['main']);
        $this->assertSame(['a@x.com', 'b@x.com', 'c@x.com'], array_column($out['main'], 'email'));
    }

    public function testSortNodeWorksOnWrappedItems(): void
    {
        $node  = new SortNode();
        $input = [
            $this->wrapped(['name' => 'b', 'price' => 30]),
            $this->wrapped(['name' => 'a', 'price' => 10]),
            $this->wrapped(['name' => 'c', 'price' => 20]),
        ];

        $asc = $node->execute($input, ['sortBy' => 'name', 'order' => 'asc'], $this->context);
        $this->assertSame(['a', 'b', 'c'], array_column(array_column($asc['main'], 'json'), 'name'));

        $desc = $node->execute($input, ['sortBy' => 'price', 'order' => 'desc', 'type' => 'number'], $this->context);
        $this->assertSame([30, 20, 10], array_column(array_column($desc['main'], 'json'), 'price'));
    }

    public function testRemoveDuplicatesNodeWorksOnWrappedItems(): void
    {
        $node  = new RemoveDuplicatesNode();
        $input = [
            $this->wrapped(['email' => 'a@x.com']),
            $this->wrapped(['email' => 'b@x.com']),
            $this->wrapped(['email' => 'a@x.com']),
        ];

        $out = $node->execute($input, ['keyField' => 'email'], $this->context);

        $this->assertCount(2, $out['main']);
        $this->assertSame('a@x.com', $out['main'][0]['json']['email']);
        $this->assertSame('b@x.com', $out['main'][1]['json']['email']);
    }

    public function testMergeNodeCombineWrappedItems(): void
    {
        $node  = new MergeNode();
        $input = [
            $this->wrapped(['a' => 1, 'shared' => 'x']),
            $this->wrapped(['b' => 2, 'shared' => 'y']),
        ];

        $out = $node->execute($input, ['mode' => 'combine'], $this->context);

        $this->assertSame(['a' => 1, 'shared' => 'y', 'b' => 2], $out['main'][0]);
    }

    public function testAggregateNodeSingleJsonWrappedItems(): void
    {
        $node  = new AggregateNode();
        $input = [
            $this->wrapped(['a' => 1]),
            $this->wrapped(['b' => 2]),
        ];

        $out = $node->execute($input, ['outputFormat' => 'single_json'], $this->context);

        $this->assertSame(['a' => 1, 'b' => 2], $out['main'][0]);
    }

    public function testMergeNodeAppend(): void
    {
        $node  = new MergeNode();
        $input = [$this->wrapped(['a' => 1]), $this->wrapped(['b' => 2])];

        $out = $node->execute($input, ['mode' => 'append'], $this->context);

        $this->assertSame($input, $out['main']);
    }

    public function testMergeNodeCombine(): void
    {
        $node  = new MergeNode();
        $input = [['a' => 1], ['b' => 2]];

        $out = $node->execute($input, ['mode' => 'combine'], $this->context);

        $this->assertSame(['a' => 1, 'b' => 2], $out['main'][0]);
    }

    public function testAggregateNodeArrayMode(): void
    {
        $node  = new AggregateNode();
        $input = [$this->wrapped(['x' => 1]), $this->wrapped(['x' => 2])];

        $out = $node->execute($input, ['outputFormat' => 'array', 'destinationField' => 'rows'], $this->context);

        $this->assertCount(1, $out['main']);
        $this->assertSame(2, $out['main'][0]['count']);
        $this->assertSame($input, $out['main'][0]['rows']);
    }

    public function testAggregateNodeSingleJsonMode(): void
    {
        $node  = new AggregateNode();
        $input = [['a' => 1], ['b' => 2]];

        $out = $node->execute($input, ['outputFormat' => 'single_json'], $this->context);

        $this->assertSame(['a' => 1, 'b' => 2], $out['main'][0]);
    }

    public function testManualTriggerNodeMeta(): void
    {
        $node = new ManualTriggerNode();

        $this->assertTrue($node->isTrigger());
        $this->assertSame(['manual', 'subworkflow'], $node->getTriggerKinds());
        $this->assertSame(['main'], $node->getOutputs());
    }

    public function testManualTriggerNodeWithPayload(): void
    {
        $node = new ManualTriggerNode();

        $out = $node->execute([], ['payload' => '{"topic":"test"}'], $this->context);

        $this->assertSame(['topic' => 'test'], $out['main'][0]);
    }

    public function testManualTriggerNodePassesInputThrough(): void
    {
        $node  = new ManualTriggerNode();
        $input = [$this->wrapped(['a' => 1])];

        $out = $node->execute($input, [], $this->context);

        $this->assertSame($input, $out['main']);
    }

    public function testLoopNodeEmitsLoopAndDone(): void
    {
        $node  = new LoopNode();
        $input = [$this->wrapped(['items' => ['a', 'b']])];

        $out = $node->execute($input, [
            'mode'       => 'source',
            'loopSource' => '{{$json.items}}',
            'itemName'   => 'item',
        ], $this->context);

        $this->assertSame(['loop', 'done'], $node->getOutputs());
        $this->assertCount(2, $out['loop']);
        $this->assertSame('a', $out['loop'][0]['json']['item']);
        $this->assertSame(0, $out['loop'][0]['json']['index']);
        $this->assertSame(2, $out['loop'][0]['json']['total']);
        $this->assertFalse($out['loop'][0]['json']['done']);

        $this->assertCount(1, $out['done']);
        $this->assertSame('b', $out['done'][0]['json']['item']);
        $this->assertSame(1, $out['done'][0]['json']['index']);
        $this->assertTrue($out['done'][0]['json']['done']);
    }

    public function testHttpRequestNodeMergeKeepsInput(): void
    {
        $base = $this->startHttpServer();
        $node = new HttpRequestNode();

        $out = $node->execute(
            [$this->wrapped(['a' => 1])],
            [
                'url'        => $base . '/merge',
                'method'     => 'POST',
                'body'       => '{"x": 5}',
                'outputMode' => 'merge',
                'onError'    => 'fail',
                'timeout'    => 5,
            ],
            $this->context
        );

        $json = $out['main'][0]['json'];
        $this->assertSame(1, $json['a']);
        $this->assertTrue($json['ok']);
        $this->assertSame('POST', $json['method']);
        $this->assertSame(200, $out['main'][0]['status']);
    }

    public function testHttpRequestNodeReplaceDropsInput(): void
    {
        $base = $this->startHttpServer();
        $node = new HttpRequestNode();

        $out = $node->execute(
            [$this->wrapped(['a' => 1])],
            [
                'url'        => $base . '/replace',
                'method'     => 'GET',
                'outputMode' => 'replace',
                'onError'    => 'fail',
                'timeout'    => 5,
            ],
            $this->context
        );

        $json = $out['main'][0]['json'];
        $this->assertArrayNotHasKey('a', $json);
        $this->assertTrue($json['ok']);
    }

    public function testHttpRequestNodeFailsOnHttpError(): void
    {
        $base = $this->startHttpServer();
        $node = new HttpRequestNode();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $node->execute(
            [$this->wrapped([])],
            [
                'url'     => $base . '/err?code=500',
                'method'  => 'GET',
                'onError' => 'fail',
                'timeout' => 5,
            ],
            $this->context
        );
    }

    public function testHttpRequestNodeContinueOnHttpError(): void
    {
        $base = $this->startHttpServer();
        $node = new HttpRequestNode();

        $out = $node->execute(
            [$this->wrapped([])],
            [
                'url'     => $base . '/err?code=500',
                'method'  => 'GET',
                'onError' => 'continue',
                'timeout' => 5,
            ],
            $this->context
        );

        $this->assertCount(1, $out['main']);
        $this->assertSame(500, $out['main'][0]['status']);
        $this->assertNull($out['main'][0]['error']);
    }

    public function testHttpRequestNodeFailsOnConnectionError(): void
    {
        $node = new HttpRequestNode();

        $this->expectException(\Exception::class);

        $node->execute(
            [$this->wrapped([])],
            [
                'url'     => 'http://127.0.0.1:9/unreachable',
                'method'  => 'GET',
                'onError' => 'fail',
                'timeout' => 2,
            ],
            $this->context
        );
    }

    public function testSlackNodeThrowsOnOkFalseResponse(): void
    {
        $base = $this->startHttpServer();
        $node = new SlackNode();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/invalid_payload/');

        $node->execute(
            [$this->wrapped([])],
            [
                'webhookUrl' => $base . '/slack?ok=false',
                'text'       => 'Halo',
            ],
            $this->context
        );
    }

    public function testSlackNodeSucceedsOnOkTrueResponse(): void
    {
        $base = $this->startHttpServer();
        $node = new SlackNode();

        $out = $node->execute(
            [$this->wrapped([])],
            [
                'webhookUrl' => $base . '/slack',
                'text'       => 'Halo',
            ],
            $this->context
        );

        $this->assertCount(1, $out['main']);
        $this->assertTrue($out['main'][0]['json']['ok']);
    }
}
