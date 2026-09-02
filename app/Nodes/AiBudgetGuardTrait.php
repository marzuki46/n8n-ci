<?php

namespace App\Nodes;

/**
 * Guardrail kuota AI: dipanggil di awal execute() setiap node yang
 * memanggil LLM. Mode "block" melempar exception; mode "warn" hanya
 * mencatat peringatan.
 */
trait AiBudgetGuardTrait
{
    protected function guardAiBudget(WorkflowContext $context): ?string
    {
        $wsId = isset($context->workflow['workspace_id'])
            ? (int) $context->workflow['workspace_id']
            : null;
        if ($wsId === null || $wsId === 0) {
            return null; // tanpa workspace (mis. unit test) → lewati.
        }

        try {
            $g = (new \App\Services\AiUsageService())->guard($wsId);

            return $g['warning'];
        } catch (\RuntimeException $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
