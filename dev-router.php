<?php
/**
 * Router for PHP's built-in web server (used by start-lan-server.bat), which
 * serves C:\xampp\htdocs so the app keeps its /RigCraft/ address.
 * Mirrors the project's .htaccess:
 *   /RigCraft/api/*                     → CodeIgniter (backend/public/index.php)
 *   /RigCraft/assets|img/…, favicon.svg → built files in frontend/dist
 *   /RigCraft/backend|frontend|database → blocked (old frontend/*.php → the app)
 *   any other /RigCraft/ path           → frontend/dist/index.html (Vue Router)
 */

$root = __DIR__;
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if ($path === '/' || $path === '') {
    header('Location: /RigCraft/', true, 302);
    return true;
}
if (! preg_match('#^/RigCraft(/.*)?$#', $path, $m)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
$rel = ltrim($m[1] ?? '/', '/');

// ---- API → CodeIgniter front controller ----
if (preg_match('#^api(/|$)#', $rel)) {
    $index = $root . '/backend/public/index.php';
    $_SERVER['SCRIPT_NAME']     = '/RigCraft/backend/public/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $index;
    $_SERVER['PHP_SELF']        = '/RigCraft/backend/public/index.php';
    chdir(dirname($index));
    require $index;
    return true;
}

// ---- Built assets of the Vue app ----
if (preg_match('#^((assets|img)/[^\0]+|favicon\.svg)$#', $rel) && ! str_contains($rel, '..')) {
    $file = $root . '/frontend/dist/' . $rel;
    if (is_file($file)) {
        $types = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
                  'png' => 'image/png', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}

// ---- Private folders and files ----
$legacyPage = preg_match('#^frontend/([a-z_]+\.php)?$#', $rel);
if (! $legacyPage && preg_match('#^(backend|frontend|database)(/|$)|\.(md|bat|sql|json|lock|php)$#i', $rel)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    return true;
}

// ---- Everything else is a page of the Vue app ----
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile($root . '/frontend/dist/index.html');
return true;
