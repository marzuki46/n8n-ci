<?php

namespace App\Services\Workflow;

use App\Nodes\StopWorkflowException;
use App\Nodes\WorkflowContext;
use App\Services\CredentialService;
use CodeIgniter\Database\BaseConnection;

/**
 * Mesin eksekusi workflow.
 * Mengambil node + koneksi dari DB, menjalankan dari trigger, lalu mencatat
 * status tiap node ke tabel executions / execution_nodes.
 */
class WorkflowEngine
{
    protected $db;

    protected NodeRegistry $registry;

    protected ExecutionManager $executionManager;

    protected CredentialService $credentials;

    public function __construct(?NodeRegistry $registry = null, ?BaseConnection $db = null)
    {
        $this->db               = $db ?? \Config\Database::connect();
        $this->registry         = $registry ?? new NodeRegistry();
        $this->executionManager = new ExecutionManager($db);
        $this->credentials      = new CredentialService();
    }

    /**
     * Cek control_flag di tabel executions.
     * Dipanggil antar node untuk menentukan apakah eksekusi perlu
     * di-pause, di-stop, atau dilanjutkan.
     *
     * @return 'continue'|'pause'|'stop'
     */
    protected function checkControl(int $executionId): string
    {
        $row = $this->db->table('executions')
            ->select('control_flag')
            ->where('id', $executionId)
            ->get()
            ->getRowArray();

        $flag = $row['control_flag'] ?? null;

        if ($flag === 'stop') {
            return 'stop';
        }

        if ($flag === 'pause') {
            return 'pause';
        }

        return 'continue';
    }

    /**
     * Tunggu sampai flag di-clear (resume) atau di-set ke 'stop'.
     * Timeout 5 menit supaya worker tidak gantung selamanya.
     */
    protected function waitWhilePaused(int $executionId): string
    {
        $deadline = time() + 300;

        while (time() < $deadline) {
            sleep(1);

            $flag = $this->db->table('executions')
                ->select('control_flag')
                ->where('id', $executionId)
                ->get()
                ->getRowArray()['control_flag'] ?? null;

            if ($flag === null || $flag === '' || $flag === 'resume') {
                // Clear flag dan lanjut
                $this->db->table('executions')
                    ->where('id', $executionId)
                    ->update(['control_flag' => null, 'status' => 'running']);
                return 'continue';
            }

            if ($flag === 'stop') {
                return 'stop';
            }
            // flag === 'pause' → keep waiting
        }

        // Timeout — resume otomatis
        $this->db->table('executions')
            ->where('id', $executionId)
            ->update(['control_flag' => null, 'status' => 'running']);

        return 'continue';
    }

    /**
     * Jalankan workflow.
     *
     * @param array $workflow     baris tabel workflows
     * @param array $triggerInput data awal untuk trigger node (array item)
     * @param string $triggerType manual|schedule|webhook
     * @param array $extraContext parameter tambahan (misal webhookData)
     * @param int|null $existingExecutionId pakai baris executions yang sudah dibuat
     *                 (misal dari antrian background) alih-alih membuat baru
     * @return array [executionId, status, nodeStates]
     */
    public function run(array $workflow, array $triggerInput = [], string $triggerType = 'manual', array $extraContext = [], ?int $existingExecutionId = null, ?array $options = null): array
    {
        $startTime = microtime(true);
        $executionId = $existingExecutionId ?? $this->executionManager->create($workflow, $triggerType);

        [$nodes, $connections] = $this->loadExecutionGraph((int) $workflow['id']);

        $nodeMap = [];
        foreach ($nodes as $node) {
            $nodeMap[$node['node_id']] = $node;
        }

        // Mode replay: mulai dari node tertentu dengan item tercatat,
        // melewati trigger & node hulu.
        $replayFrom  = is_array($options) ? ($options['replay_from_node'] ?? null) : null;
        $replayItems = is_array($options) ? ($options['items'] ?? null) : null;
        $isReplay    = $replayFrom !== null && isset($nodeMap[$replayFrom]);

        $context = new WorkflowContext($workflow);
        $context->parameters = array_merge($context->parameters, $extraContext);
        $context->variables  = $this->loadVariables($workflow['id']);

        if (count($nodes) === 0) {
            $this->executionManager->finish($executionId, 'error', 'Workflow tidak memiliki node.', $startTime);
            try {
                (new \App\Services\AlertService($this->db))
                    ->notifyFailure((int) $workflow['id'], $executionId, 'Workflow tidak memiliki node.');
            } catch (\Throwable $e) {
                log_message('error', '[WorkflowEngine] Gagal kirim alert: ' . $e->getMessage());
            }

            return ['execution_id' => $executionId, 'status' => 'error', 'node_states' => []];
        }

        $outputs = [];
        $states  = [];
        $done    = [];
        $runOrder = [];
        $stopped  = false;

        $queue = new \SplQueue();

        // Kumpulkan node error trigger (hanya berjalan saat ada node gagal)
        $errorTriggers = [];
        foreach ($nodes as $node) {
            $def = $this->registry->get($node['node_type']);
            if ($def && $def->isTrigger() && in_array('error', $def->getTriggerKinds(), true)) {
                $errorTriggers[$node['node_id']] = $node;
            }
        }

        foreach ($nodes as $node) {
            $definition = $this->registry->get($node['node_type']);
            if (! $definition || ! $definition->isTrigger()) {
                continue;
            }

            $kinds = $definition->getTriggerKinds();
            if ($kinds === [] || in_array($triggerType, $kinds, true)) {
                $queue->enqueue($node);
            }
        }

        if ($isReplay) {
            // Kosongkan antrian trigger; mulai hanya dari node replay.
            $queue = new \SplQueue();
            $queue->enqueue($nodeMap[$replayFrom]);
        }

        while (! $queue->isEmpty()) {
            /** @var array $node */
            $node   = $queue->dequeue();
            $nodeId = $node['node_id'];

            // Cek control_flag antar node (pause / stop)
            $control = $this->checkControl($executionId);
            if ($control === 'pause') {
                $this->db->table('executions')->where('id', $executionId)->update(['status' => 'paused']);
                $control = $this->waitWhilePaused($executionId);
            }
            if ($control === 'stop') {
                // Tandai semua node yang belum jalan sebagai skipped
                foreach ($nodes as $pending) {
                    if (! isset($done[$pending['node_id']])) {
                        $pendingExecId = $this->executionManager->addNodeExecution($executionId, $pending);
                        $this->executionManager->updateNodeExecution($pendingExecId, 'skipped');
                        $states[$pending['node_id']] = 'skipped';
                    }
                }
                $stopped = true;
                break;
            }

            if (isset($done[$nodeId])) {
                continue;
            }
            $done[$nodeId] = true;
            $runOrder[]    = $nodeId;

            $definition = $this->registry->get($node['node_type']);
            $execNodeId = $this->executionManager->addNodeExecution($executionId, $node);

            if (! $definition) {
                $states[$nodeId] = 'error';
                $msg = "Node type tidak terdaftar: {$node['node_type']}";
                $this->executionManager->updateNodeExecution($execNodeId, 'error', null, null, $msg);
                $this->executionManager->addError($executionId, $execNodeId, $nodeId, $msg);
                continue;
            }

            // Kumpulkan item dari semua koneksi masuk
            $inputItems  = [];
            $hasIncoming = false;

            foreach ($connections as $conn) {
                if ($conn['target_node'] === $nodeId) {
                    $hasIncoming = true;
                    $srcNodeId   = $conn['source_node'];
                    $srcDef      = isset($nodeMap[$srcNodeId]) ? $this->registry->get($nodeMap[$srcNodeId]['node_type']) : null;
                    $outKey      = $this->outputKeyFor($srcDef, $conn['source_output']);
                    $srcItems    = $outputs[$srcNodeId][$outKey] ?? [];
                    foreach ($srcItems as $item) {
                        $inputItems[] = $item;
                    }
                }
            }

            if ($isReplay && $nodeId === $replayFrom && $replayItems !== null) {
                // Replay: pakai item tercatat, jangan timpa dari trigger.
            } elseif ($definition->isTrigger() || ! $hasIncoming) {
                $inputItems = $triggerInput;
            } elseif ($hasIncoming && count($inputItems) === 0) {
                // Cabang tanpa data -> node di-skip
                $states[$nodeId] = 'skipped';
                $this->executionManager->updateNodeExecution($execNodeId, 'skipped');
                continue;
            }

            $this->executionManager->updateNodeExecution($execNodeId, 'running');

            try {
                $params = $this->resolveParams($node, $definition, $context, $inputItems);
                $result = $this->executeWithRetry($node, $definition, $inputItems, $params, $context);

                if (! is_array($result)) {
                    $result = ['main' => []];
                }

                $outputs[$nodeId] = $result;
                $states[$nodeId]  = 'success';

                $this->executionManager->updateNodeExecution(
                    $execNodeId,
                    'success',
                    $inputItems,
                    $this->flattenForStorage($result)
                );

                // Simpan output per node untuk ekspresi $node["name"]
                $context->nodeOutputs[$nodeId]        = $result;
                $context->nodeOutputsByName[$node['name']] = [
                    'outputData' => $this->flattenForStorage($result),
                ];

                // Lanjutkan ke node tujuan
                foreach ($connections as $conn) {
                    if ($conn['source_node'] === $nodeId && isset($nodeMap[$conn['target_node']]) && ! isset($done[$conn['target_node']])) {
                        $queue->enqueue($nodeMap[$conn['target_node']]);
                    }
                }
            } catch (StopWorkflowException $e) {
                // Hentikan alur secara normal (bukan error).
                $states[$nodeId] = 'success';
                $this->executionManager->updateNodeExecution($execNodeId, 'success', $inputItems);
                $stopped = true;
                break;
            } catch (\Throwable $e) {
                $states[$nodeId] = 'error';
                $this->executionManager->updateNodeExecution($execNodeId, 'error', $inputItems, null, $e->getMessage());
                $this->executionManager->addError($executionId, $execNodeId, $nodeId, $e->getMessage(), $e->getTraceAsString());

                // Jalankan error trigger bila ada
                if ($errorTriggers !== []) {
                    $context->parameters['errorInfo'] = [
                        'node_id'   => $nodeId,
                        'node_name' => $node['name'],
                        'node_type' => $node['node_type'],
                        'message'   => $e->getMessage(),
                        'time'      => date('Y-m-d H:i:s'),
                    ];

                    foreach ($errorTriggers as $etNodeId => $etNode) {
                        if (! isset($done[$etNodeId])) {
                            $queue->enqueue($etNode);
                        }
                    }
                }
            }
        }

        $hasError = in_array('error', $states, true);
        $finalStatus = $stopped ? 'stopped' : ($hasError ? 'error' : 'success');

        $this->executionManager->finish($executionId, $finalStatus, null, $startTime);

        // Error alert: notifikasi gagal workflow (email/log, throttle).
        if ($finalStatus === 'error' || $finalStatus === 'stopped') {
            try {
                $errorMessage = $this->buildErrorMessage($executionId, $nodeMap, $states);
                (new \App\Services\AlertService($this->db))
                    ->notifyFailure((int) $workflow['id'], $executionId, $errorMessage);
            } catch (\Throwable $e) {
                log_message('error', '[WorkflowEngine] Gagal kirim alert: ' . $e->getMessage());
            }
        }

        return [
            'execution_id' => $executionId,
            'status'       => $finalStatus,
            'node_states'  => $states,
            'outputs'      => $outputs,
            'order'        => $runOrder,
        ];
    }

    /**
     * Susun pesan error ringkas dari error terakhir per node yang gagal.
     */
    protected function buildErrorMessage(int $executionId, array $nodeMap, array $states): string
    {
        $errors = $this->db->table('execution_errors')
            ->where('execution_id', $executionId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if ($errors === []) {
            return 'Eksekusi berhenti (status: error).';
        }

        $lines = [];
        foreach (array_slice($errors, 0, 5) as $err) {
            $nodeName = '';
            if ($err['node_id'] && isset($nodeMap[$err['node_id']])) {
                $nodeName = $nodeMap[$err['node_id']]['name'] ?? '';
            }
            $lines[] = ($nodeName !== '' ? "[{$nodeName}] " : '') . $err['message'];
        }

        return implode("\n", $lines);
    }

    /**
     * Muat graf eksekusi. Bila workflow punya snapshot terpublikasi,
     * eksekusi memakai snapshot itu (draft di editor tidak langsung live).
     *
     * @return array{0: list<array>, 1: list<array>} [nodes, connections]
     */
    protected function loadExecutionGraph(int $workflowId): array
    {
        try {
            $pub = $this->db->table('workflow_publications')
                ->where('workflow_id', $workflowId)
                ->get()
                ->getRowArray();

            if ($pub && ! empty($pub['graph_json'])) {
                $graph = json_decode((string) $pub['graph_json'], true);
                if (is_array($graph) && isset($graph['nodes']) && is_array($graph['nodes'])) {
                    $nodes = array_values(array_filter(
                        $graph['nodes'],
                        fn ($n) => (int) ($n['disabled'] ?? 0) === 0
                    ));
                    $conns = is_array($graph['connections'] ?? null) ? $graph['connections'] : [];

                    return [$nodes, $conns];
                }
            }
        } catch (\Throwable $e) {
            // Tabel belum ada / rusak → fallback ke tabel live.
        }

        $nodes = $this->db->table('workflow_nodes')
            ->where('workflow_id', $workflowId)
            ->where('disabled', 0)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $connections = $this->db->table('workflow_connections')
            ->where('workflow_id', $workflowId)
            ->get()
            ->getResultArray();

        return [$nodes, $connections];
    }

    /**
     * Jalankan node dengan mekanisme retry + exponential backoff.
     * Parameter yang dibaca dari node:
     *   retryCount     => jumlah ulang bila gagal (default 0)
     *   retryBackoffMs => delay dasar antar percobaan (default 500)
     *   retryMaxDelayMs=> batas atas delay (default 30.000)
     */
    protected function executeWithRetry(array $node, $definition, array $inputItems, array $params, WorkflowContext $context): array
    {
        $retryCount = (int) ($params['retryCount'] ?? 0);
        if ($retryCount < 0) {
            $retryCount = 0;
        }
        $baseDelay = max(0, (int) ($params['retryBackoffMs'] ?? 500));
        $maxDelay  = max($baseDelay, (int) ($params['retryMaxDelayMs'] ?? 30000));

        $attempt = 0;
        while (true) {
            try {
                return $definition->execute($inputItems, $params, $context);
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > $retryCount) {
                    throw $e;
                }
                // Exponential backoff: base * 2^(attempt-1), dibatasi maxDelay.
                $delay = min($maxDelay, $baseDelay * (2 ** ($attempt - 1)));
                if ($delay > 0) {
                    usleep((int) ($delay * 1000));
                }
            }
        }
    }

    /**
     * Petakan handle koneksi (mis. "out-1", "out-true") ke key output node
     * (mis. "main", "true", "false"). Fallback ke output pertama.
     */
    protected function outputKeyFor($definition, string $sourceOutput): string
    {
        $outputs = $definition ? $definition->getOutputs() : [];
        if ($outputs === []) {
            return 'main';
        }

        if (in_array($sourceOutput, $outputs, true)) {
            return $sourceOutput;
        }

        if (preg_match('/^out-(\d+)$/', $sourceOutput, $m)) {
            $idx = ((int) $m[1]) - 1;
            if (isset($outputs[$idx])) {
                return $outputs[$idx];
            }
        }

        if (strpos($sourceOutput, 'out-') === 0) {
            $suffix = substr($sourceOutput, 4);
            if (in_array($suffix, $outputs, true)) {
                return $suffix;
            }
        }

        return $outputs[0];
    }

    protected function loadVariables(int $workflowId): array
    {
        $rows = $this->db->table('workflow_variables')
            ->where('workflow_id', $workflowId)
            ->get()
            ->getResultArray();

        $vars = [];
        foreach ($rows as $row) {
            $value = $row['value'];
            if ($row['type'] === 'json') {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            } elseif ($row['type'] === 'number') {
                $value = $value + 0;
            } elseif ($row['type'] === 'boolean') {
                $value = $value === 'true' || $value === '1';
            }
            $vars[$row['key']] = $value;
        }

        return $vars;
    }

    public function resolveParams(array $node, $definition, WorkflowContext $context, array $inputItems): array
    {
        $raw = json_decode($node['parameters_json'] ?? '{}', true);
        if (! is_array($raw)) {
            $raw = [];
        }

        // Parameter dikirim apa adanya; tiap node me-resolve template {{...}}
        // per item secara mandiri (lihat AbstractNode / ekspresi node).
        $params = $raw;

        // Credential selalu di-reset per node agar node tanpa credential terpilih
        // tidak memakai sisa credential node sebelumnya.
        $context->parameters['credential'] = null;

        // Resolve parameter bertipe credentials menjadi data credential
        foreach ($definition->getParameters() as $schema) {
            if (($schema['type'] ?? '') === 'credentials') {
                $key     = $schema['key'];
                $credId  = $params[$key] ?? null;

                if ($credId) {
                    $cred = $this->credentials->loadForNode($credId);
                    if ($cred) {
                        $context->parameters['credential'] = $cred['data'];
                    } else {
                        $context->parameters['credential'] = null;
                    }
                } else {
                    // Fallback: node tanpa credential terpilih → pakai default proyek
                    // (bila ada) agar tidak wajib pilih credential di tiap node.
                    $typeId = $schema['credentialTypeId'] ?? $this->credentialTypeIdBySlug($schema['credentialType'] ?? '');
                    $default = $typeId > 0
                        ? $this->credentials->findDefault((int) ($context->workflow['workspace_id'] ?? 0), $typeId)
                        : null;
                    if ($default) {
                        $context->parameters['credential'] = $default['data'];
                        $context->parameters['credential_default_name'] = $default['name'] ?? null;
                    }
                }
            }
        }

        return $params;
    }

    /**
     * Paket 2 — uji satu node secara terisolasi (tombol "Coba Node").
     * Tidak membuat baris execution; error ditangkap dan dikembalikan.
     *
     * @param array $node       minimal: node_type, parameters_json, name
     * @param array $sampleData sample input (assoc) → dibungkus jadi satu item json
     * @param array|null $workflow baris workflow (untuk fallback credential default)
     */
    public function testNode(array $node, array $sampleData = [], ?array $workflow = null): array
    {
        $type = (string) ($node['node_type'] ?? '');
        $definition = $this->registry->get($type);
        if (! $definition) {
            return ['ok' => false, 'error' => "Node type tidak terdaftar: {$type}"];
        }

        if ($definition->isTrigger()) {
            return ['ok' => false, 'error' => 'Node trigger tidak bisa diuji sendiri — jalankan workflow-nya.'];
        }

        $workflow = $workflow ?? ['id' => 0, 'workspace_id' => null, 'name' => '(test-node)'];
        $context  = new WorkflowContext($workflow);

        $inputItems = [];
        if ($sampleData !== []) {
            $inputItems[] = ['json' => $sampleData];
        }

        try {
            $params = $this->resolveParams($node, $definition, $context, $inputItems);
            $result = $definition->execute($inputItems, $params, $context);

            if (! is_array($result)) {
                $result = ['main' => []];
            }

            return [
                'ok'     => true,
                'output' => $this->flattenForStorage($result),
                'params' => $params,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cari id credential_types dari slug. Dipakai untuk fallback default proyek.
     */
    protected function credentialTypeIdBySlug(string $slug): int
    {
        static $cache = [];
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $row = $this->db->table('credential_types')
            ->select('id')
            ->where('slug', $slug)
            ->get()
            ->getRowArray();

        return $cache[$slug] = $row ? (int) $row['id'] : 0;
    }

    protected function flattenForStorage(array $result): array
    {
        $out = [];
        foreach ($result as $outputName => $items) {
            $out[$outputName] = is_array($items) ? $items : [$items];
        }

        return $out;
    }
}
