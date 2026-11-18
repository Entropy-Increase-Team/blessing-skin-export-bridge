<?php

namespace SkinLoomBridge\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

use function is_array;
use function is_resource;
use function is_string;
use function strlen;

class TextureReader
{
    /**
     * Resolve PNG dimensions (width, height) cleanly from the IHDR chunk header
     * using only the first 32 bytes, avoiding loading large files into memory.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parsePngDimensions(string $header): ?array
    {
        if (strlen($header) < 24) {
            return null;
        }
        if (substr($header, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        if (substr($header, 12, 4) !== 'IHDR') {
            return null;
        }
        $dims = @unpack('Nwidth/Nheight', substr($header, 16, 8));
        if (!is_array($dims) || !isset($dims['width'], $dims['height'])) {
            return null;
        }
        $w = (int) $dims['width'];
        $h = (int) $dims['height'];
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        return [$w, $h];
    }

    /**
     * Report whether the textures disk uses the local filesystem driver.
     */
    public static function isLocalDisk(): bool
    {
        return config('filesystems.disks.textures.driver', 'local') === 'local';
    }

    /**
     * Inspect metadata (size, PNG dimensions, and fast local sha256 when readable on disk)
     * for a 64-hex hash without downloading full object-storage blobs during HEAD checks.
     *
     * @return array{exists: bool, size: int|null, width: int|null, height: int|null, sha256: string|null, localPath: string|null}
     */
    public static function inspect(string $hash): array
    {
        if (!self::isValidHash($hash)) {
            return ['exists' => false, 'size' => null, 'width' => null, 'height' => null, 'sha256' => null, 'localPath' => null];
        }

        $localPath = self::localPathIfReadable($hash);
        if ($localPath !== null) {
            $size = @filesize($localPath);
            $fp = @fopen($localPath, 'rb');
            $dims = null;
            if (is_resource($fp)) {
                $head = @fread($fp, 32);
                @fclose($fp);
                if (is_string($head)) {
                    $dims = self::parsePngDimensions($head);
                }
            }
            $sha = @hash_file('sha256', $localPath);

            return [
                'exists' => true,
                'size' => $size !== false ? (int) $size : null,
                'width' => $dims[0] ?? null,
                'height' => $dims[1] ?? null,
                'sha256' => is_string($sha) ? $sha : null,
                'localPath' => $localPath,
            ];
        }

        try {
            $disk = self::disk();
            if (!$disk->exists($hash)) {
                return ['exists' => false, 'size' => null, 'width' => null, 'height' => null, 'sha256' => null, 'localPath' => null];
            }
            $size = null;
            try {
                $size = $disk->size($hash);
            } catch (\Throwable $e) {
                $size = null;
            }
            $dims = null;
            try {
                $stream = $disk->readStream($hash);
                if (is_resource($stream)) {
                    $head = @fread($stream, 32);
                    @fclose($stream);
                    if (is_string($head)) {
                        $dims = self::parsePngDimensions($head);
                    }
                }
            } catch (\Throwable $e) {
                $dims = null;
            }

            return [
                'exists' => true,
                'size' => $size !== null ? (int) $size : null,
                'width' => $dims[0] ?? null,
                'height' => $dims[1] ?? null,
                'sha256' => null,
                'localPath' => null,
            ];
        } catch (\Throwable $e) {
            return ['exists' => false, 'size' => null, 'width' => null, 'height' => null, 'sha256' => null, 'localPath' => null];
        }
    }

    /**
     * Open a readable stream for $hash (either from local filesystem or textures disk).
     *
     * @return resource|null
     */
    public static function openStream(string $hash)
    {
        if (!self::isValidHash($hash)) {
            return null;
        }

        $localPath = self::localPathIfReadable($hash);
        if ($localPath !== null) {
            $fp = @fopen($localPath, 'rb');

            return is_resource($fp) ? $fp : null;
        }

        try {
            $disk = self::disk();
            $stream = $disk->readStream($hash);

            return is_resource($stream) ? $stream : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function disk(): Filesystem
    {
        return Storage::disk('textures');
    }

    public static function isValidHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }

    private static function localPathIfReadable(string $hash): ?string
    {
        if (!self::isValidHash($hash)) {
            return null;
        }
        if (self::isLocalDisk()) {
            $root = rtrim((string) config('filesystems.disks.textures.root', storage_path('textures')), '/\\');
            $candidate = $root.DIRECTORY_SEPARATOR.$hash;
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
