<?php

/*
 * Puts each file's top-level `use` imports in the order StyleCI's
 * `ordered_imports` wants, and leaves one blank line after them. The rename to
 * Flarum\ExtensionManager changes how the imports sort, so this runs on the
 * exported copy rather than on Millwright itself.
 *
 *   php sort-imports.php [--check] <file>...
 */

$check = ($argv[1] ?? '') === '--check';
$files = array_slice($argv, $check ? 2 : 1);
$changed = [];

// Compared the way StyleCI compares them: case-insensitively, with the
// namespace separator sorting before any letter.
$key = fn (string $use) => strtolower(str_replace('\\', ' ', preg_replace('/^use\s+(function\s+|const\s+)?|;\s*$/', '', $use)));

foreach ($files as $file) {
    $php = file_get_contents($file);

    $new = preg_replace_callback('/^((?:use [^;\n]+;\n)+)(\n?)/m', function (array $m) use ($key) {
        $lines = explode("\n", rtrim($m[1], "\n"));
        usort($lines, fn ($a, $b) => strcmp($key($a), $key($b)));

        return implode("\n", $lines)."\n\n";
    }, $php, 1);

    if ($new !== $php) {
        $changed[] = $file;
        if (! $check) {
            file_put_contents($file, $new);
        }
    }
}

if ($check && $changed) {
    fwrite(STDERR, implode("\n", $changed)."\n");
    exit(1);
}
