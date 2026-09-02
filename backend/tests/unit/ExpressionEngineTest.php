<?php

use App\Services\Workflow\ExpressionEngine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExpressionEngineTest extends CIUnitTestCase
{
    private ExpressionEngine $engine;

    private array $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new ExpressionEngine();

        $this->context = [
            'json' => [
                'name'    => 'Budi',
                'age'     => 30,
                'score'   => 90,
                'active'  => true,
                'items'   => ['a', 'b', 'c'],
                'payload' => '{"user":{"id":5,"role":"admin"}}',
                'first'   => ['name' => 'Ana'],
            ],
            'nodes' => [
                'HTTP' => [
                    'outputData' => [
                        'main' => [
                            ['json' => ['status' => 200, 'name' => 'req']],
                        ],
                    ],
                ],
            ],
            'variables' => [
                'apiUrl' => 'https://api.example.com',
                'retry'  => 3,
            ],
            'workflow' => [
                'id'   => 7,
                'name' => 'wf test',
            ],
        ];
    }

    public function testPlainTextPassThrough(): void
    {
        $this->assertSame('Halo', $this->engine->resolve('Halo', $this->context));
        $this->assertSame(42, $this->engine->resolve(42, $this->context));
    }

    public function testResolveJsonField(): void
    {
        $this->assertSame('Budi', $this->engine->resolve('{{$json.name}}', $this->context));
    }

    public function testResolveWholeJsonReturnsArray(): void
    {
        $result = $this->engine->resolve('{{$json}}', $this->context);

        $this->assertIsArray($result);
        $this->assertSame('Budi', $result['name']);
    }

    public function testResolveInterpolatedText(): void
    {
        $this->assertSame(
            'Halo Budi, umur 30.',
            $this->engine->resolve('Halo {{$json.name}}, umur {{$json.age}}.', $this->context)
        );
    }

    public function testResolveNestedArrayIndex(): void
    {
        $this->assertSame('Ana', $this->engine->resolve('{{$json.first.name}}', $this->context));
        $this->assertSame('b', $this->engine->resolve('{{$json.items[1]}}', $this->context));
    }

    public function testResolveNodeOutput(): void
    {
        $expr = '{{$node["HTTP"].main[0].json.status}}';

        $this->assertEquals(200, $this->engine->resolve($expr, $this->context));
    }

    public function testResolveVariable(): void
    {
        $this->assertSame('https://api.example.com', $this->engine->resolve('{{$var.apiUrl}}', $this->context));
        $this->assertEquals(3, $this->engine->resolve('{{$var.retry}}', $this->context));
    }

    public function testResolveWorkflowField(): void
    {
        $this->assertSame('wf test', $this->engine->resolve('{{$workflow.name}}', $this->context));
        $this->assertEquals(7, $this->engine->resolve('{{$workflow.id}}', $this->context));
    }

    public function testBuiltinFunctions(): void
    {
        $this->assertSame('BUDI', $this->engine->resolve('{{upper($json.name)}}', $this->context));
        $this->assertSame('budi', $this->engine->resolve('{{lower($json.name)}}', $this->context));
        $this->assertEquals(3, $this->engine->resolve('{{length($json.items)}}', $this->context));
        $this->assertSame('a,b,c', $this->engine->resolve('{{join($json.items, ",")}}', $this->context));
        $this->assertSame('HALO', $this->engine->resolve('{{upper(trim("  halo  "))}}', $this->context));
    }

    public function testBuiltinJsonParse(): void
    {
        $this->assertEquals(5, $this->engine->resolve('{{jsonParse($json.payload).user.id}}', $this->context));
        $this->assertSame('admin', $this->engine->resolve('{{jsonParse($json.payload).user.role}}', $this->context));
    }

    public function testBuiltinIfAndComparison(): void
    {
        $this->assertSame('dewasa', $this->engine->resolve('{{if($json.age > 18, "dewasa", "anak")}}', $this->context));
        $this->assertSame('anak', $this->engine->resolve('{{if($json.age < 18, "dewasa", "anak")}}', $this->context));
    }

    public function testNowBuiltin(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $this->engine->resolve('{{now}}', $this->context)
        );
    }

    public function testMissingJsonFieldReturnsEmptyString(): void
    {
        $this->assertSame('', $this->engine->resolve('{{$json.tidakAda}}', $this->context));
    }

    public function testUnknownExpressionLeftIntact(): void
    {
        $this->assertSame('{{foo.bar}}', $this->engine->resolve('{{foo.bar}}', $this->context));
    }

    public function testResolveDeep(): void
    {
        $template = [
            'greeting' => 'Hi {{$json.name}}',
            'nested'   => ['up' => '{{upper($json.name)}}'],
            'num'      => 5,
        ];

        $resolved = $this->engine->resolveDeep($template, $this->context);

        $this->assertSame('Hi Budi', $resolved['greeting']);
        $this->assertSame('BUDI', $resolved['nested']['up']);
        $this->assertSame(5, $resolved['num']);
    }
}
