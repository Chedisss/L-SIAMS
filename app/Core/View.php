<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * PHP-native template renderer.
 *
 * Templates receive data as extracted local variables and are expected to call
 * `e()` on every interpolation — the helper is defined in Helpers/functions.php
 * and wraps htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE. Nothing here
 * echoes raw model data on the template's behalf.
 */
final class View
{
    /** @var array<string,mixed> */
    private static array $shared = [];
    /** @var array<string,string> */
    private array $sections = [];
    private ?string $currentSection = null;
    private ?string $layout = null;
    /** @var array<string,mixed> */
    private array $layoutData = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        return (new self())->renderTemplate($template, $data);
    }

    /** @param array<string,mixed> $data */
    public static function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(self::render($template, $data), $status);
    }

    /** @param array<string,mixed> $data */
    private function renderTemplate(string $template, array $data): string
    {
        $path = $this->resolvePath($template);
        $content = $this->capture($path, array_merge(self::$shared, $data));

        if ($this->layout !== null) {
            $layout       = $this->layout;
            $layoutData   = array_merge(self::$shared, $data, $this->layoutData);
            $this->layout = null;

            $layoutData['__sections'] = $this->sections;
            $layoutData['content']    = $content;

            $renderer            = new self();
            $renderer->sections  = $this->sections;
            $renderer->sections['content'] ??= $content;

            return $renderer->renderTemplate($layout, $layoutData);
        }

        return $content;
    }

    private function resolvePath(string $template): string
    {
        $base = (string) Config::get('app.paths.views', BASE_PATH . '/app/Views');
        // Templates are named by developers, never by request input, but this
        // still refuses traversal so a future dynamic caller cannot escape.
        $clean = str_replace(['..', "\0"], '', $template);
        $path  = $base . '/' . str_replace('.', '/', trim($clean, '/')) . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        return $path;
    }

    /** @param array<string,mixed> $data */
    private function capture(string $path, array $data): string
    {
        $__view = $this;
        extract($data, EXTR_SKIP);
        ob_start();

        try {
            include $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    // --------------------------------------------------------- template API --

    /** @param array<string,mixed> $data */
    public function extend(string $layout, array $data = []): void
    {
        $this->layout     = $layout;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        $this->currentSection = $section;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->currentSection === null) {
            return;
        }

        $this->sections[$this->currentSection] = (string) ob_get_clean();
        $this->currentSection                  = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function has(string $section): bool
    {
        return isset($this->sections[$section]) && trim($this->sections[$section]) !== '';
    }

    /** @param array<string,mixed> $data */
    public function include(string $template, array $data = []): void
    {
        echo (new self())->renderTemplate($template, array_merge(self::$shared, $data));
    }

    /** @param array<string,mixed> $sections */
    public function setSections(array $sections): void
    {
        $this->sections = $sections;
    }
}
