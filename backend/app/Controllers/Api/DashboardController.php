<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

class DashboardController extends BaseApiController
{
    public function overview(): ResponseInterface
    {
        $db = \Config\Database::connect();
        $workspaceId = $this->currentWorkspaceId();
        $userId = $this->userId();

        $totalWorkflows = (int) $db->table('workflows')->where('workspace_id', $workspaceId)->countAllResults();
        $activeWorkflows = (int) $db->table('workflows')
            ->where('workspace_id', $workspaceId)
            ->where('active', 1)
            ->countAllResults();

        $recentExecutions = $db->table('executions e')
            ->select('e.id, e.status, e.trigger_type, e.duration, e.started_at, w.name as workflow_name')
            ->join('workflows w', 'w.id = e.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->orderBy('e.id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $execStats = $db->table('executions e')
            ->select('e.status, COUNT(*) as total')
            ->join('workflows w', 'w.id = e.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->groupBy('e.status')
            ->get()
            ->getResultArray();

        $stats = ['success' => 0, 'error' => 0, 'running' => 0];
        foreach ($execStats as $row) {
            if (isset($stats[$row['status']])) {
                $stats[$row['status']] = (int) $row['total'];
            }
        }

        $projects = $db->table('workspaces w')
            ->select('w.id, w.name, COUNT(wf.id) as workflow_count')
            ->join('workspace_users wu', 'wu.workspace_id = w.id')
            ->join('workflows wf', 'wf.workspace_id = w.id', 'left')
            ->where('wu.user_id', $userId)
            ->groupBy('w.id')
            ->orderBy('w.id', 'ASC')
            ->get()
            ->getResultArray();

        $schedulesActive = (int) $db->table('schedules s')
            ->join('workflows w', 'w.id = s.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->where('s.active', 1)
            ->countAllResults();

        // ROI time-saved: total menit kerja manual yang dihemat oleh
        // eksekusi workflow (berdasarkan setting per-workflow).
        $timeSavedRows = $db->table('executions e')
            ->select('COALESCE(SUM(w.time_saved_minutes), 0) AS total_minutes, COUNT(e.id) AS runs')
            ->join('workflows w', 'w.id = e.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->get()
            ->getRowArray();

        return $this->success([
            'total_workflows'    => $totalWorkflows,
            'active_workflows'   => $activeWorkflows,
            'execution_stats'    => $stats,
            'schedules_active'   => $schedulesActive,
            'recent_executions'  => $recentExecutions,
            'projects'           => $projects,
            'time_saved_minutes' => (int) ($timeSavedRows['total_minutes'] ?? 0),
            'time_saved_runs'    => (int) ($timeSavedRows['runs'] ?? 0),
        ]);
    }
}
