<?php

namespace App\Filters;

use App\Services\ApiKeyService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Autentikasi API key (gaya n8n: header X-API-Key atau Authorization: Bearer).
 * Dipakai di grup /api/v1 yang tidak memakai session/CSRF. Request memakai
 * session yang sudah login akan langsung diteruskan.
 */
class ApiKeyAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session()->get('user_id')) {
            return null;
        }

        $key = (string) $request->getHeaderLine('X-API-Key');
        if ($key === '') {
            $auth = (string) $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                $key = trim($m[1]);
            }
        }

        $row = (new ApiKeyService())->verify($key !== '' ? $key : null);

        if (! $row) {
            return service('response')
                ->setStatusCode(401)
                ->setContentType('application/json')
                ->setHeader('WWW-Authenticate', 'Bearer')
                ->setBody(json_encode([
                    'success' => false,
                    'message' => 'API key tidak valid atau kedaluwarsa.',
                ], JSON_UNESCAPED_UNICODE));
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', $row['user_id'])->get()->getRowArray();
        if (! $user) {
            return service('response')
                ->setStatusCode(401)
                ->setContentType('application/json')
                ->setBody(json_encode([
                    'success' => false,
                    'message' => 'Pemilik API key tidak ditemukan.',
                ], JSON_UNESCAPED_UNICODE));
        }

        session()->regenerate();

        session()->set([
            'user_id'    => (int) $user['id'],
            'user_name'  => $user['name'],
            'user_email' => $user['email'],
            'user_role'  => $user['role'],
            'api_key_id' => (int) $row['id'],
        ]);

        $workspace = $db->table('workspace_users')
            ->where('user_id', $user['id'])
            ->orderBy('workspace_id', 'ASC')
            ->get()
            ->getRowArray();

        if ($workspace) {
            session()->set('workspace_id', (int) $workspace['workspace_id']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
