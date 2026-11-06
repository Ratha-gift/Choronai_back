<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Images;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImageController extends Controller
{
    /** POST /uploads/images  (multipart: image, folder) → { path, url } */
    public function store(Request $request)
    {
        $data = $request->validate([
            'folder' => ['required', Rule::in(Images::FOLDERS)],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $permission = [
            'products' => ['products.add', 'products.edit'],
            'business-images' => ['settings.system', 'settings.edit'],
            'business-branch-images' => ['settings.system', 'settings.edit'],
            'user-profile' => null, // everyone may change their own photo
        ][$data['folder']];
        $user = $request->user();
        abort_unless(
            $permission === null || collect($permission)->contains(fn ($key) => $user->hasPermission($key)),
            403,
        );

        $path = Images::store($request->file('image'), $data['folder']);
        Audit::log('upload', 'images', null, null, ['path' => $path]);

        return response()->json(['path' => $path, 'url' => Images::url($path)], 201);
    }

    /**
     * GET /images/{path}: streams an image from R2 (the bucket is private).
     * Public like any website image; paths are random so they cannot be guessed.
     */
    public function show(string $path)
    {
        abort_unless(Images::isPath($path) && Images::disk()->exists($path), 404);
        $disk = Images::disk();

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** DELETE /uploads/images/{path} */
    public function destroy(Request $request, string $path)
    {
        abort_unless(Images::isPath($path), 404);
        abort_unless(
            str_starts_with($path, 'user-profile/') || $request->user()->hasPermission('products.edit') || $request->user()->hasPermission('settings.system'),
            403,
        );
        Images::delete($path);

        return response()->noContent();
    }
}
