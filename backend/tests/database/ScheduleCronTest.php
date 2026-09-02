<?php

use App\Services\CronRunner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Test jalur jadwal/cron: CronRunner tick (menjalankan schedule jatuh tempo,
 * melewati yang belum, skip workflow non-aktif) + endpoint run-now.
 *
 * @internal
 */
final class ScheduleCronTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;

    protected $refresh = false;

    /** @var list<int> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // tick() memproses SEMUA schedule aktif di DB. Bersihkan tabel agar
        // hasil hanya berasal dari data test ini (refresh=false menumpuk data).
        // Child dulu (FK), lalu induk.
        foreach (['execution_data', 'execution_nodes', 'execution_errors', 'execution_logs', 'execution_queue'] as $child) {
            $this->db->table($child)->where('1 = 1')->delete();
        }
        $this->db->table('executions')->where('1 = 1')->delete();
        $this->db->table('schedules')->where('1 = 1')->delete();
        $this->db->table('settings')->where('key', 'cron.last_tick')->delete();
        $this->db->table('settings')->where('key', 'cron.last_tick_detail')->delete();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $table => $ids) {
            if (is_int($table)) {
                continue;
            }
            if (! empty($ids)) {
                $this->db->table($table)->whereIn('id', $ids)->delete();
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $email, string $role = 'owner'): int
    {
        $this->db->table('users')->insert([
            'name'       => 'SC User',
            'email'      => $email,
            'password'   => password_hash('x123', PASSWORD_DEFAULT),
            'role'       => $role,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function makeWorkflow(int $workspaceId, bool $active = true, string $name = 'WF SC'): int
    {
        $this->db->table('workflows')->insert([
            'workspace_id' => $workspaceId,
            'name'         => $name . ' ' . uniqid(),
            'status'       => $active ? 'active' : 'draft',
            'active'       => $active ? 1 : 0,
            'version'      => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $wf = (int) $this->db->insertID();

        $this->db->table('workflow_nodes')->insert([
            'workflow_id'     => $wf,
            'node_id'         => 'n-run',
            'node_type'       => 'set',
            'name'            => 'Set',
            'position_x'      => 0,
            'position_y'      => 0,
            'parameters_json' => json_encode(['assignments' => '[{"field":"ok","value":"1"}]']),
            'disabled'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $wf;
    }

    private function addSchedule(int $workflowId, string $cron = '*/5 * * * *', ?string $nextRun = null, int $active = 1): int
    {
        $this->db->table('schedules')->insert([
            'workflow_id' => $workflowId,
            'cron'        => $cron,
            'timezone'    => 'UTC',
            'source'      => 'manual',
            'active'      => $active,
            'next_run'    => $nextRun ?? date('Y-m-d H:i:s', time() - 60),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function seedMember(int $workspaceId, int $userId, string $role): void
    {
        $this->db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $userId,
            'role'         => $role,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function login(int $userId, int $workspaceId): void
    {
        $this->withSession([
            'user_id'      => $userId,
            'user_name'    => 'SC User',
            'user_email'   => 'sc@local.dev',
            'user_role'    => 'owner',
            'workspace_id' => $workspaceId,
        ]);
    }

    private function csrfHeaders(): array
    {
        $csrf = $this->withHeaders(['X-Requested-With' => 'xmlhttprequest'])->get('api/csrf');
        $token = $this->extractToken($csrf->getBody());
        $_COOKIE['csrf_cookie_name'] = $token;

        return [
            'X-Requested-With' => 'xmlhttprequest',
            'X-CSRF-TOKEN'     => $token,
        ];
    }

    private function extractToken(string $body): string
    {
        if (preg_match('/"token":"([0-9a-f]{32})"/i', $body, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function makeWorkspace(): int
    {
        $this->db->table('workspaces')->insert([
            'name'       => 'WS SC',
            'slug'       => 'ws-' . uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    // -----------------------------------------------------------------
    // CronRunner::tick
    // -----------------------------------------------------------------

    public function testTickRunsDueSchedule(): void
    {
        $ws = $this->makeWorkspace();
        $wf = $this->makeWorkflow($ws);
        $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() - 3600));

        $runner = new CronRunner();
        $result = $runner->tick();

        $this->assertSame(1, $result['ran']);
        $this->assertSame('success', $result['details'][0]['status']);

        $schedule = $this->db->table('schedules')->where('workflow_id', $wf)->get()->getRowArray();
        $this->assertNotNull($schedule['last_run']);
        $this->assertSame('success', $schedule['last_status']);
        // next_run maju ke tick berikutnya (bukan waktu lampau)
        $this->assertGreaterThan(time() - 3600, strtotime($schedule['next_run']));

        $this->seeInDatabase('executions', ['workflow_id' => $wf, 'status' => 'success']);
    }

    public function testTickSkipsFutureSchedule(): void
    {
        $ws = $this->makeWorkspace();
        $wf = $this->makeWorkflow($ws);
        $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() + 3600));

        $runner = new CronRunner();
        $result = $runner->tick();

        $this->assertSame(0, $result['ran']);
    }

    public function testTickSkipsInactiveWorkflow(): void
    {
        $ws = $this->makeWorkspace();
        $wf = $this->makeWorkflow($ws, false);
        $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() - 3600));

        $runner = new CronRunner();
        $result = $runner->tick();

        $this->assertSame(0, $result['ran']);

        $schedule = $this->db->table('schedules')->where('workflow_id', $wf)->get()->getRowArray();
        $this->assertSame('skipped:workflow_inactive', $schedule['last_status']);
    }

    public function testTickSkipsInactiveSchedule(): void
    {
        $ws = $this->makeWorkspace();
        $wf = $this->makeWorkflow($ws);
        $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() - 3600), 0);

        $runner = new CronRunner();
        $result = $runner->tick();

        $this->assertSame(0, $result['ran']);
    }

    public function testTickWritesStatusSettings(): void
    {
        $ws = $this->makeWorkspace();
        $wf = $this->makeWorkflow($ws);
        $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() - 3600));

        $runner = new CronRunner();
        $runner->tick();

        $status = $runner->getStatus();
        $this->assertNotNull($status['last_tick']);
        $this->assertStringContainsString('"ran":1', (string) $status['last_tick_detail']);
    }

    public function testTickRunsAllDueSchedules(): void
    {
        $ws = $this->makeWorkspace();
        $wf1 = $this->makeWorkflow($ws);
        $wf2 = $this->makeWorkflow($ws);
        $this->addSchedule($wf1, '*/1 * * * *', date('Y-m-d H:i:s', time() - 60));
        $this->addSchedule($wf2, '*/1 * * * *', date('Y-m-d H:i:s', time() - 60));

        $runner = new CronRunner();
        $result = $runner->tick();

        $this->assertSame(2, $result['ran']);
    }

    // -----------------------------------------------------------------
    // API: run-now
    // -----------------------------------------------------------------

    public function testRunNowEndpointRunsSchedule(): void
    {
        $ws = $this->makeWorkspace();
        $user = $this->makeUser('runnow_' . uniqid() . '@local.dev');
        $this->seedMember($ws, $user, 'owner');
        $wf = $this->makeWorkflow($ws);
        $schedule = $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() + 3600));
        $this->login($user, $ws);

        $result = $this->withHeaders($this->csrfHeaders())
            ->post("api/schedules/{$schedule}/run-now");
        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertMatchesRegularExpression('/"status":"success"/', $result->getBody());

        $row = $this->db->table('schedules')->where('id', $schedule)->get()->getRowArray();
        $this->assertSame('success', $row['last_status']);
        $this->assertNotNull($row['last_run']);
    }

    public function testRunNowRequiresPermission(): void
    {
        $ws = $this->makeWorkspace();
        $owner = $this->makeUser('owner2_' . uniqid() . '@local.dev');
        $this->seedMember($ws, $owner, 'owner');
        $wf = $this->makeWorkflow($ws);
        $schedule = $this->addSchedule($wf, '*/5 * * * *', date('Y-m-d H:i:s', time() + 3600));

        // Member di luar workspace -> tidak bisa menjalankan
        $outsider = $this->makeUser('outsider_' . uniqid() . '@local.dev', 'member');
        $this->login($outsider, $this->makeWorkspace());

        $result = $this->withHeaders($this->csrfHeaders())
            ->post("api/schedules/{$schedule}/run-now");
        $this->assertSame(403, $result->response()->getStatusCode());
    }
}