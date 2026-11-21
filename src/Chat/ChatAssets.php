<?php

namespace Packstub\Agents\Chat;

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The chat's built files (resources/dist): the stylesheet, the script and
 * the chart library it loads on demand. A plain app gets them from a package
 * route, with the file's time in the URL so a new release is fetched once;
 * Filament Agents registers the same files with the panel's assets.
 */
class ChatAssets
{
    /** @var array<string, string> file => content type */
    public const array FILES = [
        'agents-chat.css' => 'text/css; charset=utf-8',
        'agents-chat.js' => 'text/javascript; charset=utf-8',
        'agents-chart.js' => 'text/javascript; charset=utf-8',
    ];

    public static function path(string $file): string
    {
        return __DIR__.'/../../resources/dist/'.$file;
    }

    public static function url(string $file): string
    {
        if (! Route::has('packstub-agents.asset')) {
            return '';
        }

        return route('packstub-agents.asset', ['file' => $file, 'v' => substr(md5((string) @filemtime(self::path($file))), 0, 8)]);
    }

    public static function serve(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]) && is_file(self::path($file)), 404);

        return response()->file(self::path($file), [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
