<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    private const SCOPES = ['system', 'pos'];

    public function show(string $scope)
    {
        abort_unless(in_array($scope, self::SCOPES, true), 404);

        return Settings::public($scope);
    }

    /** Saves only the keys sent; others keep their value. */
    public function update(Request $request, string $scope)
    {
        abort_unless(in_array($scope, self::SCOPES, true), 404);
        abort_unless($request->user()->hasPermission("settings.{$scope}") || $request->user()->hasPermission('settings.edit'), 403);
        $values = collect($request->all())
            ->except(['has_pin', 'pin_hash', 'pin_salt'])
            ->filter(fn ($value) => is_scalar($value) || $value === null || is_array($value))
            ->all();
        Audit::log('update', "settings.{$scope}", null, null, collect($values)->except('pin_code')->all());

        return Settings::put($scope, $values);
    }

    public function logo(Request $request)
    {
        abort_unless($request->user()->hasPermission('settings.system') || $request->user()->hasPermission('settings.edit'), 403);
        $request->validate(['logo' => 'required|image|max:2048']);
        $path = $request->file('logo')->store('logos', 'public');
        Settings::put('system', ['logo' => Storage::url($path)]);

        return ['url' => Storage::url($path)];
    }

    public function image(Request $request)
    {
        abort_unless(
            $request->user()->hasPermission('products.add')
            || $request->user()->hasPermission('products.edit')
            || $request->user()->hasPermission('settings.system')
            || $request->user()->hasPermission('settings.edit'),
            403,
        );
        $request->validate(['image' => 'required|image|mimes:jpeg,png,webp|max:5120']);
        $file = $request->file('image');
        $path = $file->storeAs('images', Str::uuid().'.'.$file->extension(), 's3');

        return ['url' => Storage::disk('s3')->url($path)];
    }
}
