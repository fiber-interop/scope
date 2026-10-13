<?php

/**
 * Userland polyfill of the Scope class from the PHP RFC "Scope", declared by
 * src/loader.php only when no Scope class exists (the extension is not loaded).
 *
 * The current scope is kept per execution unit: a WeakMap from the unit's object
 * (a Fiber, or whatever fiber_interop\scope\set_execution_key_provider()'s provider
 * returns) to a stack of the scopes it entered with run(), plus one stack for {main}. Known gaps, closed
 * by the extension: a fiber created with `new Fiber` by code that doesn't
 * cooperate (wrap its callable in `Scope::getCurrent()->run()`) sees the root
 * scope, and a generator sees the scope of whoever is iterating it.
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

    public function __construct() {}

    public function __clone(): void { throw new Error('Trying to clone an uncloneable object of class Scope'); }
    public function __serialize(): array { throw new Exception("Serialization of 'Scope' is not allowed"); }
    public function __unserialize(array $data): void { throw new Exception("Unserialization of 'Scope' is not allowed"); }

    /** Calls $callback with this scope as the current scope, and returns its result. */
    public function run(callable $callback, mixed ...$args): mixed
    {
        $fiber = (self::$keyProvider ?? Fiber::getCurrent(...))() ?? Fiber::getCurrent();
        if ($fiber === null) {
            self::$main[] = $this;
        } else {
            self::$fibers ??= new WeakMap();
            $stack = self::$fibers[$fiber] ?? [];
            $stack[] = $this;
            self::$fibers[$fiber] = $stack;
        }
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
        }
    }

    /** The current scope; the root scope outside any run(). */
    public static function getCurrent(): Scope
    {
        $fiber = (self::$keyProvider ?? Fiber::getCurrent(...))() ?? Fiber::getCurrent();
        $stack = $fiber === null ? self::$main : (self::$fibers[$fiber] ?? null);
        return $stack ? $stack[array_key_last($stack)] : self::getRootScope();
    }

    /** The root scope: one object for the whole request, current in {main} outside any run(). */
    public static function getRootScope(): Scope
    {
        return self::$root ??= new Scope();
    }
}
