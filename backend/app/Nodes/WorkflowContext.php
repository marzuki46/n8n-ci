<?php

namespace App\Nodes;

use App\Services\Workflow\ExpressionEngine;

/**
 * Konteks eksekusi workflow yang dibagikan antar node.
 */
class WorkflowContext
{
    public array $workflow;

    public array $variables = [];

    public ExpressionEngine $expression;

    /** @var array keyed by node_id -> array output data per node */
    public array $nodeOutputs = [];

    /** @var array keyed by node name -> output data (untuk ekspresi $node["name"]) */
    public array $nodeOutputsByName = [];

    public array $parameters = [];

    public function __construct(array $workflow = [])
    {
        $this->workflow = $workflow;
        $this->expression = new ExpressionEngine();
    }

    /**
     * Resolve template dengan data JSON tertentu.
     */
    public function resolve($template, array $json)
    {
        return $this->expression->resolve($template, $this->contextFor($json));
    }

    public function resolveDeep($value, array $json)
    {
        return $this->expression->resolveDeep($value, $this->contextFor($json));
    }

    protected function contextFor(array $json): array
    {
        // Item bisa berupa data polos ({"topic": ...}) atau terbungkus
        // n8n-style ({"json": {"topic": ...}}). $json selalu merujuk ke data.
        $data = $json;
        if (array_key_exists('json', $json) && is_array($json['json'])) {
            $data = $json['json'];
        }

        return [
            'json'      => $data,
            'nodes'     => $this->nodeOutputsByName,
            'variables' => $this->variables,
            'workflow'  => $this->workflow,
        ];
    }
}
