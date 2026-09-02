<?php

use App\Nodes\CodeNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test C2: Sandbox CodeNode — kode user tidak boleh akses FS, network,
 * env/process, atau escape via eval/Function constructor.
 *
 * @internal
 */
final class CodeNodeSandboxTest extends CIUnitTestCase
{
    private CodeNode $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->node = new CodeNode();
    }

    private function executeCode(string $code, array $items, array $params = []): array
    {
        $context = new WorkflowContext(['id' => 1, 'name' => 'sandbox']);

        return $this->node->execute($items, array_merge(['code' => $code], $params), $context);
    }

    public function testNormalTransformAllItems(): void
    {
        $out = $this->executeCode('return items.map(it => ({ x: it.n * 2 }))', [['n' => 2], ['n' => 3]]);

        $this->assertSame([['x' => 4], ['x' => 6]], $out['main']);
    }

    public function testNormalTransformEachItem(): void
    {
        $out = $this->executeCode('return { y: item.n + 1 }', [['n' => 2], ['n' => 3]], ['entryPoint' => 'Run Once for Each Item']);

        $this->assertSame([['y' => 3], ['y' => 4]], $out['main']);
    }

    public function testRequireIsBlocked(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/require|fs/i');

        $this->executeCode("return require('fs').readFileSync('/etc/passwd', 'utf8')", [[]]);
    }

    public function testProcessIsBlocked(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/process|not defined/i');

        $this->executeCode('return process.env', [[]]);
    }

    public function testFunctionConstructorEscapeIsBlocked(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Code generation|disallowed/i');

        $this->executeCode("return (() => {}).constructor('return process')()", [[]]);
    }

    public function testConstructorChainEscapeIsBlocked(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Code generation|disallowed/i');

        $this->executeCode("return this.constructor.constructor('return process')()", [[]]);
    }

    public function testFetchIsBlocked(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/fetch|not defined/i');

        $this->executeCode("return fetch('http://127.0.0.1')", [[]]);
    }

    public function testTimeoutStillWorks(): void
    {
        $start = microtime(true);
        try {
            $this->executeCode('while (true) {}', [[]], ['timeout' => 2]);
            $this->fail('Harusnya timeout.');
        } catch (\Exception $e) {
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThanOrEqual(2, $elapsed);
            $this->assertLessThan(5, $elapsed);
            $this->assertStringContainsString('waktu', $e->getMessage());
        }
    }

    public function testErrorPropagatesWithMessage(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/gagal|Error/i');

        $this->executeCode('throw new Error("boom")', [[]]);
    }
}
