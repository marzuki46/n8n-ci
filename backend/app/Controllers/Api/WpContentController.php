<?php

namespace App\Controllers\Api;

use App\Services\WpContentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Paket 3 — endpoint Content AI untuk plugin WordPress.
 * Berada di grup /api/v1 (auth API key, tanpa CSRF, rate-limit longgar).
 */
class WpContentController extends BaseApiController
{
    private WpContentService $service;

    public function __construct()
    {
        $this->service = new WpContentService();
    }

    /**
     * GET api/v1/wp/status → {valid, expires_at, workspace_name, ai_credential_ready}
     */
    public function status(): ResponseInterface
    {
        $wsId   = $this->currentWorkspaceId();
        $status = $this->service->status($wsId);

        // expires_at dari API key yang sedang dipakai (bila via API key).
        $apiKeyId = (int) (session()->get('api_key_id') ?? 0);
        $expiresAt = null;
        if ($apiKeyId > 0) {
            $keyRow = \Config\Database::connect()
                ->table('api_keys')->select('expires_at')->where('id', $apiKeyId)->get()->getRowArray();
            $expiresAt = $keyRow['expires_at'] ?? null;
        }

        return $this->success(array_merge($status, [
            'expires_at' => $expiresAt,
        ]));
    }

    /**
     * POST api/v1/wp/generate
     * Body: {topic, content_type?, language?, min_words?, company_profile?,
     *        instructions?, model?}
     */
    public function generate(): ResponseInterface
    {
        $input = $this->input();

        $credential = $this->requireAiCredential();
        if ($credential instanceof ResponseInterface) {
            return $credential;
        }

        try {
            $result = $this->service->generate($credential['data'] ?? [], [
                'topic'           => $input['topic'] ?? '',
                'content_type'    => $input['content_type'] ?? 'post',
                'language'        => $this->normalizeLanguage($input),
                'min_words'       => (int) ($input['min_words'] ?? 600),
                'company_profile' => $input['company_profile'] ?? '',
                'instructions'    => $input['instructions'] ?? '',
                'model'           => $input['model'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            log_message('error', '[WpContent] generate: ' . $e->getMessage());

            return $this->fail('Gagal generate konten: ' . $e->getMessage(), 500);
        }

        return $this->success($result, 'Konten berhasil dibuat');
    }

    /**
     * POST api/v1/wp/continue
     * Body: {existing_content, existing_title?, language?, company_profile?,
     *        instructions?, action? (rewrite|expand|polish), model?}
     */
    public function continueContent(): ResponseInterface
    {
        $input = $this->input();

        $credential = $this->requireAiCredential();
        if ($credential instanceof ResponseInterface) {
            return $credential;
        }

        try {
            $result = $this->service->continueContent($credential['data'] ?? [], [
                'existing_content' => $input['existing_content'] ?? '',
                'existing_title'   => $input['existing_title'] ?? '',
                'language'         => $this->normalizeLanguage($input),
                'company_profile'  => $input['company_profile'] ?? '',
                'instructions'     => $input['instructions'] ?? '',
                'action'           => $input['action'] ?? 'expand',
                'model'            => $input['model'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            log_message('error', '[WpContent] continue: ' . $e->getMessage());

            return $this->fail('Gagal melanjutkan konten: ' . $e->getMessage(), 500);
        }

        return $this->success($result, 'Konten berhasil diperbarui');
    }

    /**
     * Credential AI default wajib ada; kalau tidak → respons error siap-pakai.
     *
     * @return array|ResponseInterface
     */
    private function requireAiCredential()
    {
        $credential = $this->service->findAiCredential($this->currentWorkspaceId());
        if (! $credential || empty($credential['data']['api_key'])) {
            return $this->fail(
                'Belum ada AI credential default di proyek ini. Tandai satu credential OpenAI/9Router sebagai Default.',
                500
            );
        }

        return $credential;
    }

    /**
     * Bahasa: auto (dari locale WP) atau manual ('id'|'en').
     */
    private function normalizeLanguage(array $input): string
    {
        $lang = strtolower((string) ($input['language'] ?? 'id'));
        if (in_array($lang, ['auto', '', 'id_id', 'id'], true)) {
            return 'id';
        }

        return str_starts_with($lang, 'en') ? 'en' : 'id';
    }
}
