<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting\Expenses;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Local immutable bytes. The caller authorizes and owns the company transaction before file access. */
final class AccountingExpenseAttachmentFiles
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    public const MAX_FILES = 20;

    public const MAX_ACTIVE = 10;

    /** @return array{name: string, extension: string, mime: string, size: int, sha256: string, bytes: string} */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            $this->fail('Upload failed; choose a valid supporting file.');
        }

        $name = $file->getClientOriginalName();

        if (! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 160 || ! preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} _().-]*\.(pdf|png|jpg|jpeg)\z/iu', $name)) {
            $this->fail('Use a plain PDF/JPEG/PNG filename of at most 160 characters, without paths or control characters.');
        }

        $path = $file->getRealPath();

        if ($path === false) {
            $this->fail('The upload temporary file is missing.');
        }

        $bytes = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if ($bytes === false || strlen($bytes) < 1 || strlen($bytes) > self::MAX_BYTES) {
            $this->fail('Each supporting file must contain 1 byte to 10 MiB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', default => ''
        };

        $clientExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '' || ! in_array($clientExtension, $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension], true)) {
            $this->fail('The file contents and extension must agree: PDF, JPEG or PNG only.');
        }

        if ($extension === 'pdf') {
            if (! str_starts_with($bytes, '%PDF-') || ! preg_match('/%%EOF[\r\n\t ]*\z/', substr($bytes, -1024))) {
                $this->fail('The PDF header or ending is invalid.');
            }
        } else {
            $image = @getimagesizefromstring($bytes);

            if ($image === false || $image[0] < 1 || $image[1] < 1 || $image[0] > 10000 || $image[1] > 10000 || $image[0] * $image[1] > 20000000 || $image['mime'] !== $mime) {
                $this->fail('Use a valid image of at most 20 megapixels and 10,000 pixels per side.');
            }

            if (($extension === 'png' && ! str_ends_with($bytes, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82")) || ($extension === 'jpg' && ! str_ends_with($bytes, "\xFF\xD9"))) {
                $this->fail('The image ending is invalid.');
            }
        }

        return ['name' => $name, 'extension' => $extension, 'mime' => (string) $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
    }

    public function key(int $company, int $expense, string $extension): string
    {
        return 'company/'.$company.'/expense/'.$expense.'/'.bin2hex(random_bytes(32)).'.'.$extension;
    }

    public function write(string $key, string $bytes, bool &$created): void
    {
        $disk = $this->disk();
        $this->safePath($disk, $key, false);
        $disk->makeDirectory(dirname($key));
        $path = $this->safePath($disk, $key, false);

        $stream = @fopen($path, 'xb');

        if ($stream === false) {
            throw new \RuntimeException('Private expense evidence could not be created exclusively.');
        }

        $created = true;

        try {
            @chmod($path, 0600);
            $offset = 0;
            $length = strlen($bytes);

            while ($offset < $length) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Private expense evidence write failed.');
                }
                $offset += $written;
            }

            if (! fflush($stream)) {
                throw new \RuntimeException('Private expense evidence could not be flushed.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** A stable verified copy, so bytes checked before download are the exact bytes streamed later.
     * @return resource
     */
    public function snapshot(string $key, string $sha256, int $size)
    {
        if ($size < 1 || $size > self::MAX_BYTES || ! preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            $this->fail('Stored file metadata is invalid.');
        }

        $disk = $this->disk();
        $path = $this->safePath($disk, $key, true);
        $input = @fopen($path, 'rb');
        $copy = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($input === false || $copy === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($copy)) {
                fclose($copy);
            }
            $this->fail('Private evidence is unavailable. It will not be recreated.');
        }

        try {
            $hash = hash_init('sha256');
            $count = 0;

            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    $this->fail('Private evidence could not be read.');
                }

                $count += strlen($chunk);
                if ($count > self::MAX_BYTES) {
                    $this->fail('Private evidence size no longer matches.');
                }

                hash_update($hash, $chunk);
                if (fwrite($copy, $chunk) !== strlen($chunk)) {
                    $this->fail('The verified download copy could not be prepared.');
                }
            }

            if ($count !== $size || ! hash_equals($sha256, hash_final($hash))) {
                $this->fail('Private evidence bytes no longer match their recorded size/checksum.');
            }

            rewind($copy);

            return $copy;
        } catch (\Throwable $e) {
            fclose($copy);
            throw $e;
        } finally {
            fclose($input);
        }
    }

    public function discardUncommitted(string $key): void
    {
        $disk = $this->disk();
        $path = $this->safePath($disk, $key, false);

        if (is_file($path) && ! unlink($path)) {
            throw new \RuntimeException('An uncommitted private upload needs administrator cleanup.');
        }
    }

    private function disk(): FilesystemAdapter
    {
        $root = config('accounting-expenses.private_root', storage_path('app/private-expense-evidence'));

        if (! is_string($root) || $root === '' || str_contains($root, chr(0)) || array_intersect(explode('/', str_replace('\\', '/', $root)), ['.', '..']) !== []) {
            throw new \LogicException('Configure a private local expense evidence root.');
        }

        $resolved = $this->resolved($root);

        foreach ([public_path(), storage_path('app/public')] as $public) {
            $prefix = rtrim($this->resolved($public), '/').'/';

            if ($resolved === rtrim($prefix, '/') || str_starts_with($resolved.'/', $prefix)) {
                throw new \LogicException('Expense evidence must be outside public storage.');
            }
        }

        $disk = Storage::build(['driver' => 'local', 'root' => $root, 'visibility' => 'private', 'directory_visibility' => 'private', 'serve' => false, 'throw' => true]);

        if (! $disk instanceof FilesystemAdapter) {
            throw new \LogicException('Private expense evidence requires a local filesystem adapter.');
        }

        if (is_link(rtrim($disk->path(''), '/\\'))) {
            $this->fail('Private evidence root cannot be a symlink.');
        }

        $this->denyDirectServing($disk);

        return $disk;
    }

    /** Defense for Apache/XAMPP and IIS deployments; the web document root must still be public/. */
    private function denyDirectServing(FilesystemAdapter $disk): void
    {
        $rules = ['.htaccess' => "Require all denied\n", 'web.config' => '<?xml version="1.0" encoding="utf-8"?><configuration><system.webServer><security><authorization><clear/><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>'];

        foreach ($rules as $name => $content) {
            $path = $disk->path($name);

            if (is_link($path)) {
                $this->fail('Private evidence protection cannot follow symlinks.');
            }

            if (is_file($path)) {
                if (file_get_contents($path) !== $content) {
                    throw new \LogicException('Private evidence direct-serving protection was changed.');
                }

                continue;
            }

            $handle = @fopen($path, 'xb');

            if ($handle === false) {
                if (is_file($path) && file_get_contents($path) === $content) {
                    continue;
                }
                throw new \RuntimeException('Private evidence direct-serving protection could not be created.');
            }

            try {
                @chmod($path, 0600);
                if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                    throw new \RuntimeException('Private evidence direct-serving protection could not be written.');
                }
            } finally {
                fclose($handle);
            }
        }
    }

    private function resolved(string $path): string
    {
        $current = $path;
        $suffix = [];

        while (! file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                throw new \LogicException('Private evidence root cannot be resolved.');
            }
            array_unshift($suffix, basename($current));
            $current = $parent;
        }

        $real = realpath($current);
        if ($real === false) {
            throw new \LogicException('Private evidence root cannot be resolved.');
        }

        return strtolower(str_replace('\\', '/', rtrim($real, '/\\').($suffix === [] ? '' : '/'.implode('/', $suffix))));
    }

    private function checkKey(string $key): void
    {
        if (! preg_match('/\Acompany\/[1-9][0-9]*\/expense\/[1-9][0-9]*\/[a-f0-9]{64}\.(pdf|png|jpg)\z/', $key)) {
            $this->fail('Stored private evidence identity is invalid.');
        }
    }

    private function safePath(FilesystemAdapter $disk, string $key, bool $required): string
    {
        $this->checkKey($key);
        $root = rtrim($disk->path(''), '/\\');
        $path = $root;

        if (is_link($root)) {
            $this->fail('Private evidence root cannot be a symlink.');
        }

        foreach (explode('/', $key) as $part) {
            $path .= DIRECTORY_SEPARATOR.$part;
            if (is_link($path)) {
                $this->fail('Private evidence cannot follow symlinks.');
            }
        }

        if ($required && ! is_file($path)) {
            $this->fail('Private evidence is missing. Missing bytes are not repaired.');
        }

        return $path;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attachment' => $message]);
    }
}
