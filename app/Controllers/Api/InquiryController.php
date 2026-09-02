<?php

namespace App\Controllers\Api;

use App\Services\InquiryService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Kelola profil publik & inquiry (auth, owner only untuk perubahan).
 */
class InquiryController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        return $this->success((new InquiryService())->list());
    }

    /**
     * Tandai sudah dibaca / belum dibaca.
     * POST api/inquiries/(:num)/mark  body {status: "read"|"new"}
     */
    public function mark(int $id): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $input  = $this->input();
        $status = ($input['status'] ?? '') === 'read' ? 'read' : 'new';

        $updated = \Config\Database::connect()
            ->table('inquiries')->where('id', $id)->update(['status' => $status]);
        if (! $updated) {
            return $this->fail('Pesan tidak ditemukan.', 404);
        }

        return $this->success(['id' => $id, 'status' => $status], 'Status diperbarui');
    }

    public function delete(int $id): ResponseInterface
    {
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh menghapus pesan.', 403);
        }

        $deleted = \Config\Database::connect()
            ->table('inquiries')->where('id', $id)->delete();
        if (! $deleted) {
            return $this->fail('Pesan tidak ditemukan.', 404);
        }

        return $this->success(null, 'Pesan dihapus');
    }

    /**
     * GET api/settings/profile → seluruh field termasuk secret (owner).
     */
    public function showProfile(): ResponseInterface
    {
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh melihat pengaturan ini.', 403);
        }

        return $this->success((new InquiryService())->getProfile());
    }

    public function saveProfile(): ResponseInterface
    {
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh mengubah pengaturan ini.', 403);
        }

        $saved = (new InquiryService())->saveProfile($this->input());

        return $this->success($saved, 'Profil publik disimpan');
    }
}
