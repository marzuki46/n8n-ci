<?php

namespace App\Nodes;

/**
 * Kontrak untuk setiap node workflow.
 * Semua konfigurasi node ada di parameters_json (tabel workflow_nodes).
 */
interface NodeInterface
{
    /** Jenis node, contoh: http_request, if, 9router */
    public function getType(): string;

    /** Nama tampilan, contoh: HTTP Request */
    public function getName(): string;

    /** Kategori, contoh: HTTP, AI, Flow, Trigger */
    public function getCategory(): string;

    /** Warna kategori */
    public function getColor(): string;

    /** Ikon (nama class / path svg) */
    public function getIcon(): string;

    public function getDescription(): string;

    /**
     * Schema parameter untuk panel settings editor.
     * Contoh item: ['key', 'label', 'type' (text|number|select|textarea|code|json|password|credentials), 'options', 'default', 'required', 'placeholder']
     */
    public function getParameters(): array;

    /** Daftar output, contoh: ['main'] atau ['true', 'false'] */
    public function getOutputs(): array;

    /** Apakah node ini trigger */
    public function isTrigger(): bool;

    /**
     * Contoh penggunaan node untuk mode ramah pemula.
     * Format:
     * [
     *   'title'  => 'Contoh: ...',
     *   'input'  => ['field' => 'nilai'],   // sample input item
     *   'params' => ['model' => '...'],      // contoh isian parameter
     * ]
     */
    public function getExamples(): array;

    /**
     * Contoh bentuk output (untuk preview, tanpa memanggil API).
     */
    public function getExampleOutput(): array;

    /**
     * Eksekusi node.
     *
     * @param array $inputItems array item data yang masuk (item = associative array)
     * @param array $params     parameter hasil resolve ekspresi
     * @param WorkflowContext $context konteks eksekusi
     * @return array key = nama output, value = array item
     */
    public function execute(array $inputItems, array $params, WorkflowContext $context): array;
}
