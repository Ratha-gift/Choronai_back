<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Images;
use App\Support\Settings;
use Illuminate\Http\Request;

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
        // The logo goes to R2 (business-images/) when it is configured.
        $path = Images::store($request->file('logo'), 'business-images');
        Settings::put('system', ['logo' => Images::url($path)]);

        return ['path' => $path, 'url' => Images::url($path)];
    }
}
