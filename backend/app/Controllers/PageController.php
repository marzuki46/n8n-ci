<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Halaman publik yang dihasilkan HtmlNode: /page/{slug}
 */
class PageController extends Controller
{
    public function index(string $slug): ResponseInterface
    {
        $page = \Config\Database::connect()
            ->table('workflow_pages')
            ->where('slug', $slug)
            ->orderBy('updated_at', 'DESC')
            ->get()
            ->getRowArray();

        if (! $page || $page['html'] === null) {
            return $this->response->setStatusCode(404)->setBody('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>404</title></head><body><h1>404 - Halaman tidak ditemukan</h1><p><a href="/">Kembali</a></p></body></html>');
        }

        return $this->response->setContentType('text/html; charset=UTF-8')->setBody($page['html']);
    }
}
