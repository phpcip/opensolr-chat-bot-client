<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

use Opensolr\ChatBot\Http\Request;
use Opensolr\ChatBot\Http\Response;

/**
 * The site's logo shown by the widget: one image in the data folder, its type read from its content.
 */
final class Logo
{
    public const MAX_BYTES = 1048576;
    private const MAX_SIDE = 4096;
    private const TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @return array{type: string, hash: string}|null
     */
    public function current(): ?array
    {
        $meta = json_decode((string) $this->store->meta('logo'), true);
        if (!is_array($meta) || !in_array($meta['type'] ?? null, self::TYPES, true) || !is_string($meta['hash'] ?? null)
            || !is_file($this->store->path('logo'))) {
            return null;
        }
        return ['type' => $meta['type'], 'hash' => $meta['hash']];
    }

    /**
     * The URL the widget loads the logo from, '' without one.
     */
    public function url(string $prefix): string
    {
        $logo = $this->current();
        return $logo === null ? '' : $prefix . '/logo?v=' . $logo['hash'];
    }

    /**
     * Keeps an uploaded image: null when saved, else what is wrong with it.
     */
    public function save(string $tmpFile, int $size): ?string
    {
        if ($size <= 0 || $size > self::MAX_BYTES || !is_uploaded_file($tmpFile)) {
            return I18n::t('The logo must be an image of at most {size}.', ['size' => '1 MB']);
        }
        $bytes = (string) file_get_contents($tmpFile, false, null, 0, self::MAX_BYTES + 1);
        set_error_handler(static fn (): bool => true);
        $info = getimagesizefromstring($bytes);
        restore_error_handler();
        if (!is_array($info) || !isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            return I18n::t('The logo must be a PNG, JPEG, GIF or WebP image, at most {side} pixels on each side.', ['side' => self::MAX_SIDE]);
        }
        $file = $this->store->path('logo');
        $tmp = $file . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $file)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            return I18n::t('The logo could not be saved in the data folder.');
        }
        $this->store->setMeta('logo', (string) json_encode(['type' => self::TYPES[$info[2]], 'hash' => substr(sha1($bytes), 0, 16)]));
        return null;
    }

    public function remove(): void
    {
        $this->store->deleteMeta('logo');
        $file = $this->store->path('logo');
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function serve(Request $request): void
    {
        $logo = $this->current();
        if ($logo === null) {
            Response::json(404, ['error' => 'not_found']);
            return;
        }
        $file = $this->store->path('logo');
        Response::send(200, '', [
            'Content-Type' => $logo['type'],
            'Content-Length' => (string) filesize($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], false);
        if (!$request->isHead()) {
            readfile($file);
        }
    }
}
