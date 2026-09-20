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
            if (! is_dir($this->cacheDir) && ! mkdir($this->cacheDir, 0755, true) && ! is_dir($this->cacheDir)) {
                throw new \RuntimeException("无法创建缓存目录: {$this->cacheDir}");
            }
            file_put_contents($file, $source, LOCK_EX);
        }

        return $this->template->render(basename($file, '.tpl.php'), $data);
    }

    private function cacheFile(string $source): string
    {
        return $this->cacheDir . '/page_' . md5($source) . '.tpl.php';
    }
}
