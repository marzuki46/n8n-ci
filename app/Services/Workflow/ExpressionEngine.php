<?php

namespace App\Services\Workflow;

class ExpressionEngine
{
    /**
     * Resolve ekspresi {{...}} dalam template.
     *
     * @param mixed $template
     * @param array $context ['json' => array, 'nodes' => [name => array], 'variables' => array, 'workflow' => array]
     * @return mixed
     */
    public function resolve($template, array $context)
    {
        if (is_string($template) && strpos($template, '{{') !== false) {
            $trimmed = trim($template);
            if (preg_match('/^\{\{(.+?)\}\}$/s', $trimmed)) {
                $resolved = $this->evalExpression(trim(substr($trimmed, 2, -2)), $context);
                if (is_array($resolved)) {
                    return $resolved;
                }
            }

            return preg_replace_callback('/\{\{(.+?)\}\}/s', function ($m) use ($context) {
                $resolved = $this->evalExpression(trim($m[1]), $context);
                return is_array($resolved) ? json_encode($resolved, JSON_UNESCAPED_UNICODE) : (string) $resolved;
            }, $template);
        }

        return $template;
    }

    /**
     * Resolve ekspresi pada value yang mungkin berupa array/object rekursif.
     */
    public function resolveDeep($value, array $context)
    {
        if (is_string($value)) {
            return $this->resolve($value, $context);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolveDeep($v, $context);
            }

            return $out;
        }

        return $value;
    }

    protected function evalExpression(string $expr, array $context)
    {
        $json     = $context['json'] ?? [];
        $nodes    = $context['nodes'] ?? [];
        $vars     = $context['variables'] ?? [];
        $workflow = $context['workflow'] ?? [];

        if ($expr === '' || $expr === 'null') {
            return null;
        }

        if ($expr === 'now') {
            return date('Y-m-d H:i:s');
        }

        // Builtin function: upper(...), length(...), jsonParse(...), dst.
        if (preg_match('/^(\w[\w]*)\s*\((.*)\)(\..+)?$/s', $expr, $m)) {
            $args = $this->splitArgs($m[2]);
            $result = $this->callBuiltin($m[1], $args, $context);
            if ($result !== '__NO_BUILTIN__') {
                if (isset($m[3]) && $m[3] !== '') {
                    return $this->dataGet($result, ltrim($m[3], '.'));
                }
                return $result;
            }
        }

        // Perbandingan: a > b, a == b, dst.
        if (preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/s', $expr, $m)) {
            $a = $this->evalExpression(trim($m[1]), $context);
            $b = $this->evalExpression(trim($m[3]), $context);
            switch ($m[2]) {
                case '===':
                case '==':
                    return $a == $b;
                case '!==':
                case '!=':
                    return $a != $b;
                case '>':
                    return $a > $b;
                case '<':
                    return $a < $b;
                case '>=':
                    return $a >= $b;
                case '<=':
                    return $a <= $b;
            }
        }

        if ($expr === '$json') {
            return $json;
        }

        if ($expr === '$nodes') {
            return $nodes;
        }

        if (preg_match('/^\$json(.*)$/s', $expr, $m)) {
            return $this->dataGet($json, ltrim($m[1], '.'));
        }

        if (preg_match('/^\$node\["(.+?)"\]\.json(.*)$/s', $expr, $m)) {
            $nodeData = $nodes[$m[1]] ?? [];
            return $this->dataGet($nodeData, ltrim($m[2], '.'));
        }

        if (preg_match('/^\$node\["(.+?)"\](.*)$/s', $expr, $m)) {
            $nodeData = $nodes[$m[1]] ?? [];
            if (is_array($nodeData) && array_key_exists('outputData', $nodeData)) {
                $nodeData = $nodeData['outputData'];
            }
            return $this->dataGet($nodeData, ltrim($m[2], '.'));
        }

        if (preg_match('/^\$var(.*)$/s', $expr, $m)) {
            return $this->dataGet($vars, ltrim($m[1], '.'));
        }

        if (preg_match('/^\$workflow(.*)$/s', $expr, $m)) {
            return $this->dataGet($workflow, ltrim($m[1], '.'));
        }

        // Literal string / angka / boolean
        $trimmed = trim($expr);
        if (strlen($trimmed) >= 2 && (($trimmed[0] === "'" && substr($trimmed, -1) === "'") || ($trimmed[0] === '"' && substr($trimmed, -1) === '"'))) {
            return substr($trimmed, 1, -1);
        }
        if (is_numeric($trimmed)) {
            return $trimmed + 0;
        }
        if ($trimmed === 'true' || $trimmed === 'false') {
            return $trimmed === 'true';
        }

        // Nilai yang tidak bisa di-resolve dikembalikan apa adanya
        return '{{' . $expr . '}}';
    }

    /**
     * Pecah argumen fungsi pada level terluar (hargai tanda kutip, kurung, kurung kotak).
     */
    protected function splitArgs(string $args): array
    {
        if (trim($args) === '') {
            return [];
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $len = strlen($args);

        for ($i = 0; $i < $len; $i++) {
            $c = $args[$i];

            if ($quote !== null) {
                $current .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $current .= $c;
                continue;
            }

            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            }

            if ($c === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $c;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    protected function callBuiltin(string $name, array $args, array $context)
    {
        $resolve = fn ($arg) => $this->evalExpression(trim((string) $arg), $context);

        switch (strtolower($name)) {
            case 'upper':
                return strtoupper((string) $resolve($args[0] ?? ''));
            case 'lower':
                return strtolower((string) $resolve($args[0] ?? ''));
            case 'trim':
                return trim((string) $resolve($args[0] ?? ''));
            case 'length':
                $v = $resolve($args[0] ?? '');
                return is_array($v) ? count($v) : mb_strlen((string) $v, 'UTF-8');
            case 'replace':
                return str_replace((string) $resolve($args[1] ?? ''), (string) $resolve($args[2] ?? ''), (string) $resolve($args[0] ?? ''));
            case 'split':
                return explode((string) $resolve($args[1] ?? ','), (string) $resolve($args[0] ?? ''));
            case 'join':
                $v = $resolve($args[0] ?? '');
                return implode((string) $resolve($args[1] ?? ','), is_array($v) ? array_map('strval', $v) : []);
            case 'jsonparse':
                $decoded = json_decode((string) $resolve($args[0] ?? '{}'), true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            case 'jsonstringify':
                return json_encode($resolve($args[0] ?? null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'abs':
                return abs((float) $resolve($args[0] ?? 0));
            case 'round':
                return round((float) $resolve($args[0] ?? 0), (int) $resolve($args[1] ?? 0));
            case 'max':
                $nums = array_map(fn ($a) => (float) $resolve($a), $args);
                return $nums === [] ? 0 : max($nums);
            case 'min':
                $nums = array_map(fn ($a) => (float) $resolve($a), $args);
                return $nums === [] ? 0 : min($nums);
            case 'now':
                return date('Y-m-d H:i:s');
            case 'substring':
                $s = (string) $resolve($args[0] ?? '');
                $start = (int) $resolve($args[1] ?? 0);
                $len = isset($args[2]) ? (int) $resolve($args[2]) : null;
                return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
            case 'concat':
                return implode('', array_map(fn ($a) => (string) $resolve($a), $args));
            case 'contains':
                return strpos((string) $resolve($args[0] ?? ''), (string) $resolve($args[1] ?? '')) !== false;
            case 'startswith':
                return strpos((string) $resolve($args[0] ?? ''), (string) $resolve($args[1] ?? '')) === 0;
            case 'endswith':
                return substr((string) $resolve($args[0] ?? ''), -mb_strlen((string) $resolve($args[1] ?? ''))) === (string) $resolve($args[1] ?? '');
            case 'if':
                return $resolve($args[0] ?? false) ? $resolve($args[1] ?? null) : $resolve($args[2] ?? null);
            case 'not':
                return ! $resolve($args[0] ?? false);
            case 'and':
                foreach ($args as $a) {
                    if (! $resolve($a)) {
                        return false;
                    }
                }
                return true;
            case 'or':
                foreach ($args as $a) {
                    if ($resolve($a)) {
                        return true;
                    }
                }
                return false;
            case 'arrayindex':
                $arr = $resolve($args[0] ?? []);
                return is_array($arr) ? ($arr[(int) $resolve($args[1] ?? 0)] ?? null) : null;
        }

        return '__NO_BUILTIN__';
    }

    /**
     * Ambil data dengan path titik + indeks array, misal:
     * "user.name", "items[0].title", "response.data[2].id"
     */
    protected function dataGet($data, string $path)
    {
        if ($path === '' || $path === null) {
            return $data;
        }

        $segments = preg_split('/[.\[]/', $path);

        foreach ($segments as $segment) {
            $segment = rtrim($segment, ']');
            if ($segment === '') {
                continue;
            }

            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_object($data) && property_exists($data, $segment)) {
                $data = $data->{$segment};
            } else {
                return null;
            }
        }

        return $data;
    }
}
