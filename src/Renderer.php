<?php

declare(strict_types=1);

namespace MiGears\Pages;

use MiGears\Template\Template;

/**
 * One-step facade from the array DSL straight to HTML.
 *
 * Skips the manual compile → write → render dance: the declaration is
 * compiled, cached under cacheDir (content-addressed — the same source
 * always maps to the same file, so nothing is rewritten until the
 * declaration changes) and handed to the template engine, which performs
 * the second compilation and executes the page. Layouts and components
 * resolve through the same paths the Template already knows.
 *
 * The derived pages are only ever added to, never pruned; clearCache()
 * drops the ones this renderer wrote when you want a cold start.
 */
class Renderer
{
    private string $cacheDir;

    public function __construct(
        private Template $template,
        private Compiler $compiler,
        string $cacheDir,
    ) {
        $this->cacheDir = rtrim($cacheDir, '/\\');
        // A NUL byte is not a path at all: the mkdir() below throws a ValueError on
        // one, and the caller cannot tell that apart from a bug inside this class.
        // The compiler refuses NUL in a template name for the same reason.
        if (str_contains($this->cacheDir, "\0")) {
            throw new \InvalidArgumentException(
                'cacheDir must be a directory path; this one contains a NUL byte, which no filesystem call accepts'
            );
        }
        // An empty value or "/" reduces to nothing once the trailing separators
        // are trimmed, and the failure then surfaced as a directory error with no
        // name in it — much later, and nowhere near the call that caused it.
        if ($this->cacheDir === '') {
            throw new \InvalidArgumentException(
                'cacheDir must be a directory path; "' . $cacheDir . '" has nothing left once trailing separators are trimmed'
            );
        }
        // The compiled pages live here; register it so render() can find them.
        $this->template->addPath($this->cacheDir);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $data
     */
    public function render(array $page, array $data = []): string
    {
        $source = $this->compiler->compile($page);
        $file = $this->cacheFile($source);

        if (! is_file($file)) {
            if (! is_dir($this->cacheDir) && ! @mkdir($this->cacheDir, 0755, true) && ! is_dir($this->cacheDir)) {
                throw new \RuntimeException("cannot create cache directory: {$this->cacheDir}");
            }
            // The result used to be dropped, so an unwritable cache directory was
            // reported later as a template the engine could not find.
            if (@file_put_contents($file, $source, LOCK_EX) === false) {
                throw new \RuntimeException("cannot write compiled page: {$file}");
            }
        }

        return $this->template->render(basename($file, '.tpl.php'), $data);
    }

    /**
     * Remove the derived page templates this renderer wrote.
     *
     * Only files matching the content-addressed name produced by render()
     * are removed, so a cache directory shared with hand-written templates
     * (or with the template compiler's own artifacts) keeps them intact.
     * Pages are simply compiled again on the next render() call.
     *
     * @return int number of files removed
     */
    public function clearCache(): int
    {
        $removed = 0;

        foreach (glob($this->cacheDir . '/page_*.tpl.php') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function cacheFile(string $source): string
    {
        return $this->cacheDir . '/page_' . md5($source) . '.tpl.php';
    }
}
