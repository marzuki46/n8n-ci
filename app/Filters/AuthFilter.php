<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session()->get('user_id')) {
            $accept = (string) ($request->getServer('HTTP_ACCEPT') ?? '');

            if ($request->isAJAX() || stripos($accept, 'json') !== false) {
                return service('response')
                    ->setStatusCode(401)
                    ->setContentType('application/json')
                    ->setBody(json_encode([
                        'success' => false,
                        'message' => 'Tidak terautentikasi. Silakan login.',
                    ], JSON_UNESCAPED_UNICODE));
            }

            return redirect()->to('/login');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
