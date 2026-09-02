<?php

use App\Services\Workflow\NodeRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Paket 2 — semua node terdaftar wajib punya contoh isian & contoh output
 * yang valid strukturnya (dipakai panel "Contoh & Cara Pakai" di editor).
 *
 * @internal
 */
final class NodeExampleTest extends CIUnitTestCase
{
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new NodeRegistry();
    }

    public function testEveryNodeHasAtLeastOneExample(): void
    {
        $missing = [];

        foreach ($this->registry->all() as $type => $node) {
            if (count($node->getExamples()) === 0) {
                $missing[] = $type;
            }
        }

        $this->assertSame([], $missing, 'Node tanpa contoh: ' . implode(', ', $missing));
    }

    public function testEveryExampleStructureIsValid(): void
    {
        foreach ($this->registry->all() as $type => $node) {
            foreach ($node->getExamples() as $i => $ex) {
                $this->assertArrayHasKey('title', $ex, "{$type} contoh #{$i} tanpa title");
                $this->assertArrayHasKey('params', $ex, "{$type} contoh #{$i} tanpa params");
                $this->assertIsArray($ex['params'], "{$type} contoh #{$i}: params bukan array");

                foreach ($ex['params'] as $key => $val) {
                    $this->assertIsString($key, "{$type} contoh #{$i}: key parameter harus string");
                }

                // Semua key params harus dikenal schema node (mencegah typo).
                $schemaKeys = [];
                foreach ($node->getParameters() as $p) {
                    if (isset($p['key'])) {
                        $schemaKeys[] = $p['key'];
                    }
                }
                foreach (array_keys($ex['params']) as $k) {
                    $this->assertContains(
                        $k,
                        $schemaKeys,
                        "{$type} contoh #{$i} memakai key '{$k}' yang tidak ada di schema"
                    );
                }
            }
        }
    }

    public function testEveryExampleOutputIsWrappedItems(): void
    {
        foreach ($this->registry->all() as $type => $node) {
            $out = $node->getExampleOutput();
            if ($out === []) {
                continue;
            }

            $outputs = $node->getOutputs();
            foreach ($out as $outKey => $items) {
                $this->assertContains(
                    $outKey,
                    $outputs,
                    "{$type}: output '{$outKey}' tidak dideklarasikan getOutputs()"
                );
                $this->assertIsArray($items, "{$type}: item output '{$outKey}' bukan array");
            }
        }
    }

    public function testRegistryToApiIncludesExamples(): void
    {
        $api = $this->registry->toApi();

        $this->assertNotEmpty($api);

        $first = reset($api);
        $this->assertArrayHasKey('examples', $first);
        $this->assertArrayHasKey('exampleOutput', $first);
    }
}
