<?php

namespace Packstub\Agents\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Throwable;

/**
 * Files a person attaches to a question — a screenshot, an invoice, a CSV —
 * stored on the attachments disk (config chat.attachments) and handed to the
 * provider with the question as laravel/ai files. A stored attachment is
 * kept with the message (the provider reads it again when the history is
 * replayed) and removed when the conversation is deleted.
 */
class AgentAttachments
{
    /** Whether the chat accepts attachments at all (a disk is set). */
    public static function enabled(): bool
    {
        return (bool) config('packstub-agents.chat.attachments.enabled', true) && self::disk() !== null;
    }

    public static function disk(): ?string
    {
        $disk = config('packstub-agents.chat.attachments.disk') ?? config('filesystems.default');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    /** @return list<string> the MIME types accepted */
    public static function mimes(): array
    {
        return array_values((array) config('packstub-agents.chat.attachments.mimes', []));
    }

    public static function maxKilobytes(): int
    {
        return max(1, (int) config('packstub-agents.chat.attachments.max_kb', 10240));
    }

    public static function maxPerQuestion(): int
    {
        return max(1, (int) config('packstub-agents.chat.attachments.max_files', 5));
    }

    /**
     * Store an upload for a question and return the laravel/ai file the turn sends (an image or a document).
     *
     * @throws InvalidArgumentException when the type or the size is not accepted
     */
    public static function store(UploadedFile $file, ?string $directory = null): File
    {
        if (! self::enabled()) {
            throw new InvalidArgumentException(__('Attachments are switched off.'));
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if (! self::accepts($mime)) {
            throw new InvalidArgumentException(__('Files of type :type cannot be attached.', ['type' => $mime]));
        }

        if ($file->getSize() > self::maxKilobytes() * 1024) {
            throw new InvalidArgumentException(__('An attachment may be at most :size.', ['size' => self::maxKilobytes() >= 1024 ? round(self::maxKilobytes() / 1024, 1).' MB' : self::maxKilobytes().' KB']));
        }

        $directory ??= trim((string) config('packstub-agents.chat.attachments.directory', 'agent-attachments'), '/');
        $name = Str::uuid7().'.'.($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $path = $file->storeAs($directory, $name, ['disk' => self::disk()]);

        return self::file((string) $path, $mime)->as($file->getClientOriginalName());
    }

    public static function accepts(string $mime): bool
    {
        foreach (self::mimes() as $accepted) {
            if ($accepted === $mime || (str_ends_with($accepted, '/*') && str_starts_with($mime, substr($accepted, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** A stored path as the laravel/ai file the provider reads: an image for image/*, a document otherwise. */
    public static function file(string $path, string $mime): File
    {
        $file = str_starts_with($mime, 'image/') ? new StoredImage($path, self::disk()) : new StoredDocument($path, self::disk());

        return $file->withMimeType($mime);
    }

    /**
     * What a chat surface shows for a stored attachment: its name, type, whether it is an image, and a URL when the
     * disk can give one (a public disk, or a temporary URL on one that signs them).
     *
     * @param  array<string, mixed>  $stored  one entry of a message's attachments JSON
     * @return array{type: string, name: ?string, mime: ?string, image: bool, url: ?string, path: ?string}
     */
    public static function describe(array $stored): array
    {
        $type = (string) ($stored['type'] ?? '');
        $path = $stored['path'] ?? null;
        $disk = $stored['disk'] ?? self::disk();
        $mime = $stored['mime'] ?? null;
        $image = str_contains($type, 'image');

        return [
            'type' => $type,
            'name' => $stored['name'] ?? ($path ? basename((string) $path) : null),
            'mime' => $mime,
            'image' => $image,
            'url' => $stored['url'] ?? ($path && $disk ? self::url((string) $disk, (string) $path) : null),
            'path' => $path,
        ];
    }

    /** A URL the browser can open for a stored file, or null when the disk gives none. */
    public static function url(string $disk, string $path): ?string
    {
        try {
            $storage = Storage::disk($disk);

            if ((bool) config('packstub-agents.chat.attachments.temporary_urls', false) && method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl($path, now()->addMinutes(30));
            }

            return $storage->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Delete the stored files of a message's attachments (when the conversation goes).
     *
     * @param  list<array<string, mixed>>  $stored
     */
    public static function delete(array $stored): void
    {
        foreach ($stored as $entry) {
            $path = $entry['path'] ?? null;
            $disk = $entry['disk'] ?? self::disk();

            if (is_string($path) && is_string($disk) && str_starts_with((string) ($entry['type'] ?? ''), 'stored-')) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable) {
                    // A missing file is already gone.
                }
            }
        }
    }

    /**
     * laravel/ai files from a stored attachments list (File::fromArray), skipping what cannot be rebuilt.
     *
     * @param  list<array<string, mixed>>|string|null  $stored
     * @return list<File>
     */
    public static function rehydrate(array|string|null $stored): array
    {
        $list = is_string($stored) ? (json_decode($stored, true) ?: []) : ($stored ?? []);
        $files = [];

        foreach ($list as $entry) {
            try {
                if (is_array($entry) && ($file = File::fromArray($entry)) !== null) {
                    $files[] = $file;
                }
            } catch (Throwable) {
                // A malformed entry is left out rather than failing the turn.
            }
        }

        return $files;
    }
}
