<?php
/*
 * fiber-interop/scope loader (composer "files" autoload).
 *
 * 1. A Scope class already exists (ext-scope loaded through php.ini or -d, or a
 *    future PHP with Scope in core): use it.
 * 2. Opted in, on the CLI, with a bundled binary for this PHP build in ext/:
 *    re-exec PHP with `-d extension=<binary>` (needs pcntl_exec). Opt in with
 *    FIBER_INTEROP_SCOPE_EXT=1 or extra.fiber-interop/scope.ext=true in the root
 *    composer.json; FIBER_INTEROP_SCOPE_SO overrides the binary's path.
 * 3. Otherwise: the userland polyfill in src/Scope.php.
 *
 * fiber_interop\scope\IS_POLYFILL is true when the polyfill was declared.
 */

namespace fiber_interop\scope;

if (\defined('fiber_interop\scope\IS_POLYFILL')) {
    return;
}

if (!\class_exists('Scope', false) && (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg')
    && \getenv('FIBER_INTEROP_SCOPE_REEXEC') !== '1' && \function_exists('pcntl_exec')) {
    $optIn = \getenv('FIBER_INTEROP_SCOPE_EXT') === '1';
    if (!$optIn && \class_exists(\Composer\InstalledVersions::class)) {
        $json = \Composer\InstalledVersions::getRootPackage()['install_path'] . '/composer.json';
        $data = \is_readable($json) ? \json_decode((string) \file_get_contents($json), true) : null;
        $optIn = ($data['extra']['fiber-interop/scope']['ext'] ?? null) === true;
    }
    $so = \getenv('FIBER_INTEROP_SCOPE_SO') ?: \dirname(__DIR__) . '/ext/scope-' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION
        . '-' . (\PHP_ZTS ? 'zts' : 'nts') . '-' . \php_uname('m')
        . '-' . ('Darwin' === \PHP_OS_FAMILY ? 'darwin' : (\glob('/lib/ld-musl-*.so.1') ? 'musl' : 'glibc')) . '.so';
    if ($optIn && \is_file($so)) {
        $raw = @\file_get_contents('/proc/self/cmdline');
        $args = $raw ? \array_slice(\explode("\0", \rtrim($raw, "\0")), 1) : ($_SERVER['argv'] ?? []);
        \putenv('FIBER_INTEROP_SCOPE_REEXEC=1');   // the child takes the polyfill if the binary doesn't load
        \pcntl_exec(\PHP_BINARY, \array_merge(['-d', 'extension=' . $so], $args));
        // pcntl_exec() returned: it failed; fall through to the polyfill.
    }
}

if (\class_exists('Scope', false)) {
    \define('fiber_interop\scope\IS_POLYFILL', false);
} else {
    require __DIR__ . '/Scope.php';
    \define('fiber_interop\scope\IS_POLYFILL', true);
}

/**
 * Polyfill only: makes the polyfill key its scope stacks on the object $provider returns instead of
 * Fiber::getCurrent(), for runtimes whose execution unit is not a Fiber (e.g. Swoole coroutines).
 *
 * $provider() returns the object identifying the current execution unit, or null for {main}/"no
 * unit"; null falls back to Fiber::getCurrent(). The object is a WeakMap key, so a unit's stack is
 * dropped when the object is released. Pass null to restore the default (Fiber::getCurrent()).
 *
 * When the native extension is loaded this is a no-op that records nothing: the extension cannot see
 * coroutine switches that are not fiber switches, so such runtimes should use the polyfill.
 */
function set_execution_key_provider(?callable $provider): void
{
    if (!IS_POLYFILL) {
        return;
    }
    $closure = $provider === null ? null : \Closure::fromCallable($provider);
    \Closure::bind(static function () use ($closure): void { \Scope::$keyProvider = $closure; }, null, \Scope::class)();
}

/**
 * Polyfill only: makes the live $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST and $_SERVER, the default
 * timezone and locale, and $_SESSION while no session is active, those of Scope::getCurrent().
 *
 * If the current scope is the one last activated, it returns (one comparison). Otherwise it saves the
 * live values into the previously active scope and loads the current scope's. Scope::run() calls it on
 * entry and exit; event loops call it whenever they hand control to user code (task start, after a
 * resume, before a queued callback, after run() returns to {main}).
 *
 * A no-op when the native extension is loaded: per-scope superglobals are engine-level in the RFC's
 * secondary vote.
 */
function activate(): void
{
    static $fn = null;
    if (!IS_POLYFILL) {
        return;
    }
    ($fn ??= \Closure::bind(static function (): void {
        $current = \Scope::getCurrent();
        if ($current !== (\Scope::$active ?? \Scope::$root)) {
            \Scope::switchTo($current);
        }
    }, null, \Scope::class))();
}

if (IS_POLYFILL) {
    /**
     * @internal For runtime authors (event loops and coroutine adapters); not part of the Scope RFC.
     *
     * A counter that strictly increases whenever the polyfill's active scope may have changed: on every
     * Scope::run() entry and exit (also when run() leaves by an exception, and also when the scope it
     * returns to is the one it left) and whenever activate() swaps scopes. It never goes backwards.
     *
     * A userland loop must know each coroutine's current scope at every suspend, because the coroutine
     * can change it with $scope->run(). Reading this at resume and at suspend and calling
     * Scope::getCurrent() only when it moved avoids that call on most switches.
     *
     * Declared only when the polyfill is in use: the extension keeps each fiber's scope across its
     * suspensions itself, so loops need not track it there.
     */
    function generation(): int
    {
        static $box = null;
        if ($box === null) {
            $box = [];
            $box[0] = &\Closure::bind(static function &(): int { return \Scope::$generation; }, null, \Scope::class)();
        }
        return $box[0];
    }
}
