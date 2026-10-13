<?php

/**
 * Userland polyfill of the Scope class from the PHP RFC "Scope", declared by
 * src/loader.php only when no Scope class exists (the extension is not loaded).
 *
 * The current scope is kept per execution unit: a WeakMap from the unit's object
 * (a Fiber, or whatever fiber_interop\scope\set_execution_key_provider()'s provider
 * returns) to a stack of the scopes it entered with run(), plus one stack for {main}. A unit other
 * than {main} with no stack entry sees the active scope: the one most recently made current by run()
 * entry or exit.
 * Known gaps, closed by the extension: a raw fiber created in scope A and resumed from scope B without
 * $scope->run() sees B, and a generator sees the scope of whoever is iterating it.
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

    /** The scope most recently made current by run() entry or exit; null = the root. */
    private static ?Scope $active = null;

    public function __construct() {}

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
        self::$active = $this;
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
            self::$active = $prev;
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
        return self::$root ??= new Scope();
    }
}
