<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Log;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface as DirectoryRead;
use Magento\Framework\Filesystem\File\ReadInterface as FileRead;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory var/ and var/log for the log tool tests.
 *
 * Paths are relative to var/, the way a Magento directory reader sees them:
 * "log/system.log", "report/123". The log directory is the same files seen
 * from one level down.
 */
trait FakeDirectory
{
    /**
     * @param array<string, string> $files Path relative to var/ => contents.
     * @return Filesystem
     */
    private function filesystemHolding(array $files): Filesystem
    {
        /** @var TestCase $this */
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnCallback(
            fn (string $code): DirectoryRead => $this->directory($files, $code === 'log' ? 'log/' : '')
        );

        return $filesystem;
    }

    /**
     * @param array<string, string> $files
     * @param string $prefix
     * @return DirectoryRead
     */
    private function directory(array $files, string $prefix): DirectoryRead
    {
        $full = static fn (?string $path): string => $prefix . ltrim((string) $path, '/');
        $isFile = static fn (string $path): bool => array_key_exists($full($path), $files);
        $isDir = static function (?string $path) use ($files, $full): bool {
            $dir = rtrim($full($path), '/');
            foreach (array_keys($files) as $file) {
                if ($dir === '' || str_starts_with($file, $dir . '/')) {
                    return true;
                }
            }

            return false;
        };

        $directory = $this->createMock(DirectoryRead::class);
        $directory->method('isExist')->willReturnCallback(
            static fn ($path = null): bool => $isFile((string) $path) || $isDir($path)
        );
        $directory->method('isFile')->willReturnCallback($isFile);
        $directory->method('stat')->willReturnCallback(
            static fn (string $path): array => ['size' => strlen($files[$full($path)] ?? ''), 'mtime' => 1759744800]
        );
        $directory->method('read')->willReturnCallback(
            static function ($path = null) use ($files, $full, $prefix): array {
                $dir = rtrim($full($path), '/');
                $dir = $dir === '' ? '' : $dir . '/';
                $children = [];
                foreach (array_keys($files) as $file) {
                    if ($dir !== '' && !str_starts_with($file, $dir)) {
                        continue;
                    }
                    $rest = substr($file, strlen($dir));
                    $child = $dir . explode('/', $rest)[0];
                    $children[substr($child, strlen($prefix))] = true;
                }

                return array_keys($children);
            }
        );
        $directory->method('openFile')->willReturnCallback(
            fn (string $path): FileRead => $this->file($files[$full($path)])
        );

        return $directory;
    }

    /**
     * @param string $contents
     * @return FileRead
     */
    private function file(string $contents): FileRead
    {
        $offset = 0;
        $file = $this->createMock(FileRead::class);
        $file->method('eof')->willReturnCallback(
            static function () use ($contents, &$offset): bool {
                return $offset >= strlen($contents);
            }
        );
        $file->method('read')->willReturnCallback(
            static function (int $length) use ($contents, &$offset): string {
                $chunk = substr($contents, $offset, $length);
                $offset += strlen($chunk);

                return $chunk;
            }
        );
        $file->method('seek')->willReturnCallback(
            static function (int $to) use (&$offset): int {
                $offset = $to;

                return 0;
            }
        );
        $file->method('readLine')->willReturnCallback(
            static function (int $length) use ($contents, &$offset): string {
                $end = strpos($contents, "\n", $offset);
                $line = substr($contents, $offset, $end === false ? null : $end - $offset + 1);
                $offset += strlen($line);

                return $line;
            }
        );

        return $file;
    }
}
