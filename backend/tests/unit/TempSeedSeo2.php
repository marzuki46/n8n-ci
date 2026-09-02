<?php

use CodeIgniter\Test\CIUnitTestCase;

final class TempSeedSeo2 extends CIUnitTestCase
{
    public function testSeed(): void
    {
        $db = \Config\Database::connect('default');
        ob_start();
        require WRITEPATH . 'seed_seo_workflows_2.php';
        $out = ob_get_clean();
        fwrite(STDERR, "\nCOUNT=" . count($workflows) . " OUT=[" . trim($out) . "]\n");
        $this->assertTrue(true);
    }
}
