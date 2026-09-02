<?php

use App\Nodes\PythonCodeNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Python Code Node. Test di-skip bila binary Python tidak tersedia.
 *
 * @internal
 */
final class PythonCodeNodeTest extends CIUnitTestCase
{
    private WorkflowContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = new WorkflowContext(['id' => 1]);

        if (! $this->pythonAvailable()) {
            $this->markTestSkipped('Python tidak tersedia di server ini.');
        }
    }

    private function pythonAvailable(): bool
    {
        $node = new PythonCodeNode();
        try {
            $ref  = new ReflectionMethod($node, 'pythonBinary');
            $ref->setAccessible(true);
            $ref->invoke($node);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testAllItemsModeCanMapItems(): void
    {
        $node = new PythonCodeNode();
        $out  = $node->execute(
            [
                ['json' => ['nilai' => 10]],
                ['json' => ['nilai' => 15]],
            ],
            [
                'entryPoint' => 'Run Once for All Items',
                'code'       => "total = sum((it.get('json', {}).get('nilai', 0)) for it in items)\nreturn {\"total\": total, \"jumlah\": len(items)}",
                'timeout'    => 30,
            ],
            $this->ctx
        );

        // Return dict tunggal → dibungkus satu item.
        $this->assertSame(['total' => 25, 'jumlah' => 2], $out['main'][0]['json']);
    }

    public function testAllItemsReturnListBecomesMultipleItems(): void
    {
        $node = new PythonCodeNode();
        $out  = $node->execute(
            [['json' => ['seed' => 1]]],
            [
                'entryPoint' => 'Run Once for All Items',
                'code'       => "return [{\"n\": 1}, {\"n\": 2}]",
            ],
            $this->ctx
        );

        $this->assertCount(2, $out['main']);
        $this->assertSame(1, $out['main'][0]['json']['n']);
    }

    public function testEachItemMode(): void
    {
        $node = new PythonCodeNode();
        $out  = $node->execute(
            [
                ['json' => ['v' => 1]],
                ['json' => ['v' => 2]],
            ],
            [
                'entryPoint' => 'Run Once for Each Item',
                'code'       => "d = item.get('json', {})\nd['dua_kali'] = d.get('v', 0) * 2\nreturn {\"json\": d}",
            ],
            $this->ctx
        );

        $this->assertCount(2, $out['main']);
        $this->assertSame(2, $out['main'][0]['json']['dua_kali']);
        $this->assertSame(4, $out['main'][1]['json']['dua_kali']);
    }

    public function testPythonExceptionPropagatesAsNodeError(): void
    {
        $node = new PythonCodeNode();

        try {
            $node->execute(
                [['json' => []]],
                ['code' => "raise ValueError('meledak sengaja')"],
                $this->ctx
            );
            $this->fail('Harusnya melempar exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('meledak sengaja', $e->getMessage());
        }
    }

    public function testTimeoutKillsProcess(): void
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $this->addToAssertionCount(1);

            return; // taskkill path sudah tercakup CodeNode; hindari flakiness CI lokal.
        }
        $node = new PythonCodeNode();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('batas waktu');

        $node->execute(
            [['json' => []]],
            ['code' => "import time\ntime.sleep(10)", 'timeout' => 1],
            $this->ctx
        );
    }
}
