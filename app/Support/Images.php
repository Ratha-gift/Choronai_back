<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image files (product photos, logos, branch photos, user profiles).
 * They go to Cloudflare R2 when it is configured, otherwise to the local
 * 'public' disk. The database only keeps the path, e.g. "products/9f…c1.jpg".
 */
class Images
{
    /** Folders an image may be uploaded to (the same as in the R2 bucket). */
    public const FOLDERS = ['products', 'business-images', 'business-branch-images', 'user-profile'];

    public static function diskName(): string
    {
        return config('filesystems.disks.r2.bucket') && config('filesystems.disks.r2.key')
            ? 'r2'
            : 'public';
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /** Saves the file under a random name and returns its path. */
    public static function store(UploadedFile $file, string $folder): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');
        $path = $folder.'/'.Str::uuid().'.'.$extension;
        self::disk()->put($path, $file->getContent(), [
            'ContentType' => $file->getMimeType(),
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return $path;
    }

    /**
     * Address the browser loads the image from: the bucket's public URL when
     * it has one, otherwise this API ("images/<path>", relative to /api/v1).
     */
    public static function url(string $path): string
    {
        $public = config('filesystems.disks.r2.url');
        if (self::diskName() === 'r2' && $public) {
            return rtrim($public, '/').'/'.$path;
        }

        return 'images/'.$path;
    }

    /** True for a path this class created ("products/<uuid>.jpg"). */
    public static function isPath(string $path): bool
    {
        return (bool) preg_match(
            '#^('.implode('|', self::FOLDERS).')/[0-9a-f-]{36}\.(jpe?g|png|webp|gif|svg)$#',
            $path,
        );
    }

    /**
     * Image paths inside saved values, which may be a path, an API address
     * ("/api/backend/images/products/….jpg") or a public R2 URL.
     */
    public static function pathsIn(array $values): array
    {
        $pattern = '#(?:^|/)(('.implode('|', self::FOLDERS).')/[0-9a-f-]{36}\.(?:jpe?g|png|webp|gif|svg))$#';
        $paths = [];
        foreach ($values as $value) {
            if (is_string($value) && preg_match($pattern, $value, $match)) {
                $paths[] = $match[1];
            }
        }

        return array_values(array_unique($paths));
    }

    public static function delete(?string $path): void
    {
        if ($path && self::isPath($path)) {
            self::disk()->delete($path);
        }
    }
}
