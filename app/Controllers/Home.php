<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Home extends BaseController
{
    /**
     * Halaman root → selalu serve SPA (index.html).
     * Vue Router (hash mode) menangani autentikasi & routing di sisi klien.
     */
    public function index(): ResponseInterface|string
    {
        $spaFile = FCPATH . 'index.html';
        if (is_file($spaFile)) {
            return $this->response
                ->setContentType('text/html')
                ->setBody((string) file_get_contents($spaFile));
        }

        return view('landing');
    }
}
