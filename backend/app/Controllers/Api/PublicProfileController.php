<?php

namespace App\Controllers\Api;

use App\Services\InquiryService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Endpoint PUBLIK untuk landing page (profil + inquiry).
 * Tidak butuh login; dilindungi honeypot + captcha/reCAPTCHA + rate-limit.
 *
 * Catatan: tidak mewarisi BaseApiController agar bebas session/auth.
 */
class PublicProfileController extends \CodeIgniter\Controller
{
    private function respondJson(array $data, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * GET api/public/profile → profil + status captcha (tanpa secret).
     */
    public function profile(): ResponseInterface
    {
        $svc = new InquiryService();
        $pub = $svc->getPublicProfile();

        return $this->respondJson([
            'success' => true,
            'data'    => $pub,
        ]);
    }

    /**
     * GET api/public/captcha → soal matematika + token bertanda tangan.
     */
    public function captcha(): ResponseInterface
    {
        $svc = new InquiryService();
        if ($svc->recaptchaEnabled()) {
            return $this->respondJson(['success' => true, 'data' => ['mode' => 'recaptcha']]);
        }

        $captcha = $svc->issueCaptcha();

        return $this->respondJson([
            'success' => true,
            'data'    => array_merge(['mode' => 'math'], $captcha),
        ]);
    }

    /**
     * POST api/public/inquiry → simpan pesan (honeypot + captcha + rate-limit).
     */
    public function submit(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?: ($this->request->getPost() ?: []);

        try {
            $id = (new InquiryService())->submitInquiry(
                is_array($input) ? $input : [],
                $this->request->getIPAddress(),
                (string) $this->request->getUserAgent()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->respondJson([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            log_message('error', '[Inquiry] submit: ' . $e->getMessage());

            return $this->respondJson([
                'success' => false,
                'message' => 'Terjadi kesalahan. Coba lagi nanti.',
            ], 500);
        }

        return $this->respondJson([
            'success' => true,
            'message' => 'Pesan terkirim. Terima kasih!',
            'data'    => ['id' => $id],
        ], 201);
    }
}
