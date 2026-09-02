<?php

use App\Nodes\CsvNode;
use App\Nodes\GoogleSheetsNode;
use App\Nodes\WorkflowContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Node integrasi spreadsheet: CSV + Google Sheets (parse CSV lokal).
 *
 * @internal
 */
final class SpreadsheetNodeTest extends CIUnitTestCase
{
    private WorkflowContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = new WorkflowContext(['id' => 1]);
    }

    // ============================ CSV ============================

    public function testCsvParseWithHeader(): void
    {
        $node = new CsvNode();
        $out  = $node->execute(
            [['json' => ['csv' => "produk,harga\nLaptop Pro,15000000\nMouse,250000"]]],
            ['operation' => 'parse', 'csvField' => 'csv', 'delimiter' => ',', 'hasHeader' => true],
            $this->ctx
        );

        $this->assertCount(2, $out['main']);
        $this->assertSame(['produk' => 'Laptop Pro', 'harga' => '15000000'], $out['main'][0]['json']);
    }

    public function testCsvParseWithoutHeader(): void
    {
        $node = new CsvNode();
        $out  = $node->execute(
            [['json' => ['csv' => "a,b\n1,2"]]],
            ['operation' => 'parse', 'csvField' => 'csv', 'delimiter' => ',', 'hasHeader' => false],
            $this->ctx
        );

        $this->assertSame(['col_0' => 'a', 'col_1' => 'b'], $out['main'][0]['json']);
        $this->assertSame(['col_0' => '1', 'col_1' => '2'], $out['main'][1]['json']);
    }

    public function testCsvStringify(): void
    {
        $node = new CsvNode();
        $out  = $node->execute(
            [
                ['json' => ['produk' => 'Laptop', 'stok' => 5]],
                ['json' => ['produk' => 'Mouse "Pro"', 'stok' => 40]],
            ],
            ['operation' => 'stringify', 'hasHeader' => true],
            $this->ctx
        );

        $csv = $out['main'][0]['json']['csv'];
        $this->assertStringContainsString('"produk","stok"', $csv);
        $this->assertStringContainsString('"Mouse ""Pro""","40"', $csv);
        $this->assertSame(2, $out['main'][0]['json']['count']);
    }

    public function testCsvParseEmptyFieldYieldsNoItems(): void
    {
        $node = new CsvNode();
        $out  = $node->execute(
            [['json' => ['csv' => '']]],
            ['operation' => 'parse', 'csvField' => 'csv'],
            $this->ctx
        );

        $this->assertSame([], $out['main']);
    }

    // ====================== Google Sheets ======================

    /**
     * Stub: bypass HTTP dengan menyuntik isi CSV.
     */
    private function sheetsNode(string $csv): GoogleSheetsNode
    {
        return new class ($csv) extends GoogleSheetsNode {
            private string $csvBody;

            public function __construct(string $csv)
            {
                $this->csvBody = $csv;
            }

            protected function fetchUrl(string $url): string
            {
                return $this->csvBody;
            }
        };
    }

    public function testSheetsParsesPublishedCsvAndCastsNumbers(): void
    {
        $node = $this->sheetsNode("produk,harga,stok\nLaptop Pro,15000000,5\nMouse,250000,");

        $out = $node->execute(
            [],
            ['url' => 'https://docs.google.com/spreadsheets/d/e/ABC/pub?output=csv'],
            $this->ctx
        );

        $this->assertCount(2, $out['main']);
        $this->assertSame('Laptop Pro', $out['main'][0]['json']['produk']);
        $this->assertSame(15000000, $out['main'][0]['json']['harga']);
        $this->assertNull($out['main'][1]['json']['stok']);
    }

    public function testSheetsLimitParameter(): void
    {
        $node = $this->sheetsNode("a\n1\n2\n3");

        $out = $node->execute([], ['url' => 'https://x/ok.csv', 'limit' => 2], $this->ctx);

        $this->assertCount(2, $out['main']);
    }

    public function testSheetsRequiresValidUrl(): void
    {
        $node = $this->sheetsNode('x,y');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('URL spreadsheet');

        $node->execute([], ['url' => 'bukan-url'], $this->ctx);
    }

    public function testSheetsConvertsEditUrlToExport(): void
    {
        $captured = '';
        $node = new class ('a,b') extends GoogleSheetsNode {
            public string $calledUrl = '';

            protected function fetchUrl(string $url): string
            {
                $this->calledUrl = $url;

                return "a,b\n1,2";
            }
        };

        $node->execute(
            [],
            ['url' => 'https://docs.google.com/spreadsheets/d/SHEETID123/edit#gid=7'],
            $this->ctx
        );

        $this->assertStringContainsString('/export?format=csv&gid=7', $node->calledUrl);
    }
}
