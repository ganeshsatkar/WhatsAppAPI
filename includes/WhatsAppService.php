<?php
// ============================================================
// WhatsFlow Pro - WhatsApp Cloud API Service
// ============================================================

class WhatsAppService {
    private string $phoneNumberId;
    private string $accessToken;
    private string $baseUrl;

    public function __construct(string $phoneNumberId, string $accessToken) {
        $this->phoneNumberId = $phoneNumberId;
        $this->accessToken   = $accessToken;
        $this->baseUrl       = WA_API_BASE . '/' . $phoneNumberId . '/messages';
    }

    // ---- Factory: load settings for a user ----

    public static function forUser(int $userId): ?self {
        $settings = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id = ? AND is_connected = 1', [$userId]);
        if (!$settings) return null;
        $token = decryptSecret($settings['access_token']);
        if (!$token || empty($settings['phone_number_id'])) return null;
        return new self($settings['phone_number_id'], $token);
    }

    // ---- Send text message ----

    public function sendText(string $to, string $message, ?string $replyToWamid = null): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $message],
        ];
        if ($replyToWamid) $payload['context'] = ['message_id' => $replyToWamid];   // shows as a quoted reply in the customer's WhatsApp
        return $this->post($payload);
    }

    // ---- Send template message ----

    public function sendTemplate(string $to, string $templateName, string $language = 'en', array $components = []): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ];
        return $this->post($payload);
    }

    // ---- Send interactive list message ----

    public function sendList(string $to, string $bodyText, string $buttonText, array $sections): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'body'   => ['text' => $bodyText],
                'action' => ['button' => $buttonText, 'sections' => $sections],
            ],
        ];
        return $this->post($payload);
    }

    // ---- Send interactive buttons ----

    public function sendButtons(string $to, string $bodyText, array $buttons): array {
        $btns = array_map(fn($b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => $b['title']]], $buttons);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $bodyText],
                'action' => ['buttons' => $btns],
            ],
        ];
        return $this->post($payload);
    }

    // ---- Send media ----

    public function sendMedia(string $to, string $type, string $mediaUrl, string $caption = ''): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => $type,
            $type               => ['link' => $mediaUrl, 'caption' => $caption],
        ];
        return $this->post($payload);
    }

    // ---- Mark message as read ----

    public function markRead(string $messageId): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
        ];
        return $this->post($payload);
    }

    // ---- Submit template for approval ----

    public function submitTemplate(string $businessAccountId, array $template): array {
        $url = WA_API_BASE . '/' . $businessAccountId . '/message_templates';
        return $this->postToUrl($url, $template);
    }

    // ---- Get templates from Meta ----

    // Follows Meta's pagination (a single call only returns the first page), so accounts with many
    // templates sync completely. On an API error the Meta error object is returned untouched.
    public function getTemplates(string $businessAccountId): array {
        $url   = WA_API_BASE . '/' . $businessAccountId . '/message_templates?limit=100&fields=name,status,category,language,components,rejected_reason';
        $all   = [];
        $pages = 0;
        while ($url && $pages++ < 20) {
            $r = $this->get($url);
            if (isset($r['error'])) return $r;
            $all = array_merge($all, $r['data'] ?? []);
            $url = $r['paging']['next'] ?? null;
        }
        return ['data' => $all];
    }

    // ---- Delete a template at Meta ----
    // Meta deletes by NAME, which removes every language version of that template.

    public function deleteTemplate(string $businessAccountId, string $name): array {
        $url = WA_API_BASE . '/' . $businessAccountId . '/message_templates?name=' . rawurlencode($name);
        return $this->deleteUrl($url);
    }

    private function deleteUrl(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) return ['success' => false, 'error' => $curlError, 'code' => 0];
        $data = json_decode((string)$response, true) ?: [];
        if ($httpCode >= 200 && $httpCode < 300 && ($data['success'] ?? true)) {
            return ['success' => true, 'data' => $data];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? "Meta returned HTTP $httpCode", 'code' => $httpCode];
    }

    // ---- Internal HTTP helpers ----

    private function post(array $payload): array {
        return $this->postToUrl($this->baseUrl, $payload);
    }

    private function postToUrl(string $url, array $payload): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $data, 'message_id' => $data['messages'][0]['id'] ?? null];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown error', 'code' => $httpCode,
                'error_code' => $data['error']['code'] ?? null,                       // Meta's own error code (131026, 131049 ...)
                'error_details' => $data['error']['error_data']['details'] ?? null];
    }

    private function get(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?? [];
    }

    // ---- Generic Graph call used by Embedded Signup (token exchange, subscribe, phone details) ----
    public static function graph(string $method, string $path, array $query = [], ?array $body = null, ?string $token = null): array {
        $url = WA_API_BASE . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);
        $hdr = $token ? ['Authorization: Bearer ' . $token] : [];
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($body); $hdr[] = 'Content-Type: application/json'; }
        $opt[CURLOPT_HTTPHEADER] = $hdr;
        curl_setopt_array($ch, $opt);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) return ['error' => ['message' => $cerr]];
        $data = json_decode((string)$res, true);
        if (!is_array($data)) return ['error' => ['message' => "Unexpected response (HTTP $code)"]];
        return $data;
    }

    // ---- Webhook signature verification ----

    public static function verifyWebhook(string $appSecret, string $signature, string $payload): bool {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($expected, $signature);
    }

    // ── Get media URL from WhatsApp media ID ──────────────
    public function getMediaUrl(string $mediaId): array {
        $url = WA_API_BASE . '/' . $mediaId;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res  = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return ['url' => $res['url'] ?? null, 'mime_type' => $res['mime_type'] ?? null];
    }

    // ── Upload media to WhatsApp ─────────────────────────
    public function uploadMedia(string $filePath, string $mimeType): array {
        $url = WA_API_BASE . '/' . $this->phoneNumberId . '/media';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'file'              => new CURLFile($filePath, $mimeType, basename($filePath)),
                'type'              => $mimeType,
            ],
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_TIMEOUT    => 60,
        ]);
        $res  = json_decode(curl_exec($ch), true);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['success' => $code === 200, 'media_id' => $res['id'] ?? null, 'error' => $res['error']['message'] ?? null];
    }

    // ── Send image/video/document by URL ─────────────────
    public function sendMediaUrl(string $to, string $type, string $url, string $caption = '', string $filename = ''): array {
        $mediaObj = ['link' => $url];
        if ($caption)  $mediaObj['caption']  = $caption;
        if ($filename) $mediaObj['filename']  = $filename;
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $mediaObj,
        ];
        return $this->post($payload);
    }

    // ── Send media by uploaded media_id ──────────────────
    public function sendMediaId(string $to, string $type, string $mediaId, string $caption = ''): array {
        $mediaObj = ['id' => $mediaId];
        if ($caption) $mediaObj['caption'] = $caption;
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $mediaObj,
        ];
        return $this->post($payload);
    }
}
