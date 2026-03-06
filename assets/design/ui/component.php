<?php
/**
 * UI-only component helper.
 *
 * Usage:
 *   require_once __DIR__ . '/../assets/design/ui/component.php';
 *   ems_component('ui/card', [
 *     'title' => 'Judul',
 *     'body' => function () { echo 'Isi'; },
 *   ]);
 */

function ems_component(string $name, array $props = []): void
{
    $base = __DIR__ . '/../components/';
    $path = $base . str_replace(['..', '\\'], ['', '/'], $name) . '.php';

    if (!is_file($path)) {
        // Fail-soft: keep pages rendering even if component is missing.
        echo "<!-- component not found: " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . " -->";
        return;
    }

    // Expose props as local variables inside the component file.
    extract($props, EXTR_SKIP);
    include $path;
}

