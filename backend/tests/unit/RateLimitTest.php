<?php

use App\Filters\RateLimitFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockResponse;

/**
 * Test rate-limit per-IP untuk endpoint publik (webhook / API v1).
 *
 * @internal
 */
final class RateLimitTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    protected function makeRequest(string $path = 'webhook/x', string $ip = '203.0.113.7'): IncomingRequest
    {
        service('superglobals')->setServer('REMOTE_ADDR', $ip);
        $_SERVER['REMOTE_ADDR'] = $ip;
        $config  = config('App');
        $request = new IncomingRequest(
            $config,
            new URI('http://localhost/' . $path),
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );

        return $request;
    }

    private function runFilter(int $times, string $path, array $args): array
    {
        $results = [];
        $filter  = new RateLimitFilter();
        $response = new MockResponse(new \Config\App());

        Services::injectMock('response', $response);

        for ($i = 0; $i < $times; $i++) {
            $request = $this->makeRequest($path);
            $result  = $filter->before($request, $args[0]);
            $results[] = $result ? $result->getStatusCode() : null;
        }

        return $results;
    }

    public function testAllowsWithinLimit(): void
    {
        $results = $this->runFilter(3, 'webhook/a', ['10:60']);

        $this->assertSame([null, null, null], $results);
    }

    public function testBlocksOverLimit(): void
    {
        $results = $this->runFilter(5, 'webhook/a', ['3:60']);

        $this->assertSame([null, null, null, 429, 429], $results);
    }

    public function testSeparatePathsHaveSeparateCounters(): void
    {
        $filter = new RateLimitFilter();
        $response = new MockResponse(new \Config\App());
        Services::injectMock('response', $response);

        // 3 request di path A (batas 3) — semua lolos
        for ($i = 0; $i < 3; $i++) {
            $result = $filter->before($this->makeRequest('webhook/a'), '3:60');
            $this->assertNull($result);
        }

        // Path B belum dihitung — harus lolos
        $result = $filter->before($this->makeRequest('webhook/b'), '3:60');
        $this->assertNull($result);

        // Path A sekarang 429
        $result = $filter->before($this->makeRequest('webhook/a'), '3:60');
        $this->assertSame(429, $result->getStatusCode());
    }

    public function testDifferentIpsAreIndependent(): void
    {
        $filter = new RateLimitFilter();
        $response = new MockResponse(new \Config\App());
        Services::injectMock('response', $response);

        for ($i = 0; $i < 3; $i++) {
            $result = $filter->before($this->makeRequest('webhook/a', '203.0.113.1'), '3:60');
            $this->assertNull($result);
        }

        $result = $filter->before($this->makeRequest('webhook/a', '203.0.113.2'), '3:60');
        $this->assertNull($result);
    }
}
