<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * House rule: no em-dash in anything a person can read. Every PHP string
 * literal in the application, its configuration and its seeders is checked,
 * and the text of every Blade view outside its comments. Comments in code
 * may keep theirs; nobody sees them.
 */
class NoEmDashTest extends TestCase
{
    private const DASH = "\u{2014}";

    public function test_no_string_a_person_reads_contains_an_em_dash(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (['app', 'config', 'database/seeders', 'routes', 'lang'] as $folder) {
            foreach ($this->files("{$root}/{$folder}", '.php') as $file) {
                if (str_ends_with($file, '.blade.php')) {
                    continue;
                }
                foreach (token_get_all((string) file_get_contents($file)) as $token) {
                    if (is_array($token)
                        && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
                        && str_contains($token[1], self::DASH)) {
                        $found[] = substr($file, strlen($root) + 1).':'.$token[2];
                    }
                }
            }
        }

        foreach ($this->files("{$root}/resources/views", '.blade.php') as $file) {
            $text = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', (string) file_get_contents($file));
            if (str_contains((string) $text, self::DASH)) {
                $found[] = substr($file, strlen($root) + 1);
            }
        }

        $this->assertSame([], $found, 'Em-dash found in user-facing text: '.implode(', ', $found));
    }

    /** @return list<string> */
    private function files(string $directory, string $suffix): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
