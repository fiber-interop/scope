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
 * $_GET, $_POST, $_COOKIE and $_FILES are per scope: the live superglobals belong to the active
 * scope, every other scope's values are kept in $globals and swapped in by switchTo().
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

    /** @var WeakMap<Scope, array>|null the four superglobals of each scope that is not active */
    private static ?WeakMap $globals = null;

    /** The root's superglobals while it is not active (held strongly; the root never dies). */
    private static ?array $rootGlobals = null;

    /** A new scope starts with copies of its creator's (the current scope's) superglobals. */
    public function __construct()
    {
        $creator = self::getCurrent();
        self::$globals ??= new WeakMap();
        self::$globals[$this] = $creator === (self::$active ?? self::$root)
            ? [$_GET, $_POST, $_COOKIE, $_FILES]
            : ($creator === self::$root ? self::$rootGlobals : self::$globals[$creator]);
    }

    /** Makes $scope the active scope: saves the live superglobals to the old one, loads $scope's. */
    private static function switchTo(Scope $scope): void
    {
        $old = self::$active ?? self::getRootScope();
        if ($old === $scope) {
            return;
        }
        if ($old === self::$root) {
            self::$rootGlobals = [$_GET, $_POST, $_COOKIE, $_FILES];
        } else {
            self::$globals[$old] = [$_GET, $_POST, $_COOKIE, $_FILES];
        }
        [$_GET, $_POST, $_COOKIE, $_FILES] = $scope === self::$root ? self::$rootGlobals : self::$globals[$scope];
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
        // Without the constructor: there is no creator to copy superglobals from.
        return self::$root ??= (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
}
