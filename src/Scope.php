<?php

/**
 * Userland polyfill of the Scope class from the PHP RFC "Scope", declared by
 * src/loader.php only when no Scope class exists (the extension is not loaded).
 *
 * The current scope is kept per execution unit: a WeakMap from the unit's object
 * (a Fiber, or whatever fiber_interop\scope\set_execution_key_provider()'s provider
 * returns) to a stack of the scopes it entered with run(), plus one stack for {main}. A unit other
 * than {main} with no stack entry sees the active scope: the one most recently made current by run()
 * entry or exit or by fiber_interop\scope\activate().
 * Known gaps, closed by the extension: a raw fiber created in scope A and resumed from scope B without
 * $scope->run() sees B, and a generator sees the scope of whoever is iterating it.
 *
 * Sessions: ext/session's internal state (session id, status, save handler, open session) cannot be
 * swapped from userland, so the session follows the scope only with the extension (ext-scope or
 * ext-phasync). The polyfill swaps $_SESSION per scope only at switches where session_status() is
 * PHP_SESSION_NONE (a new scope has no $_SESSION); while a session is active $_SESSION is left alone.
 *
 * $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST and $_SERVER are per scope (a new scope's are empty): the live superglobals
 * belong to the active scope, every other scope's values are kept in $globals and swapped in by
 * switchTo(). $_SERVER and $_REQUEST are, like the other four, JIT auto-globals (created lazily on
 * first reference): the first scope creation forces them into existence by reading them below, the
 * same point at which the extension's scope_sg_live() forces its copy of the same six.
 */
final class Scope
{
    private static ?Scope $root = null;

    /** @var WeakMap<object, list<Scope>>|null */
    private static ?WeakMap $fibers = null;

    /** Set by fiber_interop\scope\set_execution_key_provider(). */
    private static ?\Closure $keyProvider = null;

    /** @var list<Scope> {main}'s stack */
    private static array $main = [];

    /** The scope whose values are in the live superglobals; null until first use (= the root). */
    private static ?Scope $active = null;

    /** @var WeakMap<Scope, array>|null the six superglobals of each scope that is not active */
    private static ?WeakMap $globals = null;

    /** The root's superglobals while it is not active (held strongly; the root never dies). */
    private static ?array $rootGlobals = null;

    /** Bumped by every switchTo() call; read through fiber_interop\scope\generation(). */
    private static int $generation = 0;

    /** Whether ext/session is available; null until first use. */
    private static ?bool $sessions = null;

    /** Whether ext/intl is loaded (Locale follows the scope); null until first use. */
    private static ?bool $intl = null;

    /** The locale in effect when the first Scope was created: the start value of a new scope when intl.default_locale is empty. */
    private static ?string $startLocale = null;

    /**
     * A new scope starts empty: the six superglobal arrays are empty, there is no $_SESSION, and the
     * default timezone and (with ext/intl) locale come from ini (date.timezone, falling back to PHP's
     * default "UTC"; intl.default_locale, falling back to the locale at first use), not from the
     * creator. A record is [_GET, _POST, _COOKIE, _FILES, _REQUEST, _SERVER, timezone, locale, _SESSION|null].
     */
    public function __construct()
    {
        self::getCurrent();
        self::$globals ??= new WeakMap();
        self::$intl ??= \class_exists(\Locale::class, false);
        // Force the JIT auto-globals into existence now, so a later-compiled read cannot re-arm the
        // engine's callback over a scope's array.
        $_GET; $_POST; $_COOKIE; $_FILES; $_REQUEST; $_SERVER;
        self::$startLocale ??= self::$intl ? \Locale::getDefault() : null;
        $locale = null;
        if (self::$intl) {
            $ini = (string) \ini_get('intl.default_locale');
            $locale = $ini !== '' ? $ini : self::$startLocale;
        }
        self::$globals[$this] = [[], [], [], [], [], [], (string) \ini_get('date.timezone') ?: 'UTC', $locale, null];
    }

    /**
     * Makes $scope the active scope: saves the live superglobals to the old one, loads $scope's.
     * Bumps $generation even when $scope is already active, so every run() entry and exit counts.
     */
    private static function switchTo(Scope $scope): void
    {
        ++self::$generation;
        $old = self::$active ?? self::getRootScope();
        if ($old === $scope) {
            return;
        }
        // date_default_timezone_set() and Locale::setDefault() cannot be intercepted: capture the live
        // values as the leaving scope's, and set the entering scope's only when they differ.
        self::$intl ??= \class_exists(\Locale::class, false);
        $tz = \date_default_timezone_get();
        $loc = self::$intl ? \Locale::getDefault() : null;
        // ext/session's state cannot be swapped from userland; $_SESSION is swapped only while no session is active.
        self::$sessions ??= \function_exists('session_status');
        $none = self::$sessions && \session_status() === \PHP_SESSION_NONE;
        $rec = [$_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER, $tz, $loc, $none ? ($_SESSION ?? null) : null];
        if ($old === self::$root) {
            self::$rootGlobals = $rec;
        } else {
            self::$globals[$old] = $rec;
        }
        $rec = $scope === self::$root ? self::$rootGlobals : self::$globals[$scope];
        [$_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER] = $rec;
        if ($none) {
            if ($rec[8] === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $rec[8];
            }
        }
        if ($rec[6] !== $tz) {
            \date_default_timezone_set($rec[6]);
        }
        if ($rec[7] !== $loc && $loc !== null) {
            \Locale::setDefault($rec[7]);
        }
        self::$active = $scope;
    }

    public function __clone(): void { throw new Error('Trying to clone an uncloneable object of class Scope'); }
    public function __serialize(): array { throw new Exception("Serialization of 'Scope' is not allowed"); }
    public function __unserialize(array $data): void { throw new Exception("Unserialization of 'Scope' is not allowed"); }

    /** Calls $callback with this scope as the current scope, and returns its result. */
    public function run(callable $callback, mixed ...$args): mixed
    {
        $prev = self::getCurrent();
        $fiber = (self::$keyProvider ?? Fiber::getCurrent(...))() ?? Fiber::getCurrent();
        if ($fiber === null) {
            self::$main[] = $this;
        } else {
            self::$fibers ??= new WeakMap();
            $stack = self::$fibers[$fiber] ?? [];
            $stack[] = $this;
            self::$fibers[$fiber] = $stack;
        }
        self::switchTo($this);
        try {
            return $callback(...$args);
        } finally {
            if ($fiber === null) {
                array_pop(self::$main);
            } else {
                $stack = self::$fibers[$fiber];
                array_pop($stack);
                if ($stack) {
                    self::$fibers[$fiber] = $stack;
                } else {
                    unset(self::$fibers[$fiber]);
                }
            }
            self::switchTo($prev);
        }
    }

    /**
     * Returns a closure that runs $closure with this scope current, whoever calls it, as run() does.
     * $closure is unchanged. Polyfill limit: the wrapper takes its arguments by value, so by-reference
     * parameters of $closure are not preserved (the extension preserves them).
     */
    public function bind(Closure $closure): Closure
    {
        $scope = $this;
        return static fn(mixed ...$args): mixed => $scope->run($closure, ...$args);
    }

    /** The current scope: the top of this unit's run() stack; else the root in {main}, the active scope in a fiber. */
    public static function getCurrent(): Scope
    {
        $fiber = (self::$keyProvider ?? Fiber::getCurrent(...))() ?? Fiber::getCurrent();
        if ($fiber === null) {
            return self::$main ? self::$main[array_key_last(self::$main)] : self::getRootScope();
        }
        $stack = self::$fibers[$fiber] ?? null;
        return $stack ? $stack[array_key_last($stack)] : (self::$active ?? self::getRootScope());
    }

    /** The root scope: one object for the whole request, current in {main} outside any run(). */
    public static function getRootScope(): Scope
    {
        // Without the constructor: there is no creator to copy from.
        return self::$root ??= (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}
