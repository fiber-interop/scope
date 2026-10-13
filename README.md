# fiber-interop/scope

`Scope` identifies the logical operation (an HTTP request, a queued job, a transaction) that code belongs to, and keeps that identity across fibers and generators. It is the class from the [PHP RFC: Scope](https://wiki.php.net/rfc/scope). This package gives you that class on PHP 8.1+: the native extension [`fiber-interop/ext-scope`](../ext-scope) when available, otherwise a userland polyfill. See the RFC and the extension for the API, the rules and the cost.

```php
$scope = new Scope();
$scope->run(function () {
    Scope::getCurrent();          // $scope
});
Scope::getCurrent();              // Scope::getRootScope()
```

## Install

```sh
composer require fiber-interop/scope
```

## Native or polyfill

The loader (`src/loader.php`, autoloaded by Composer) picks one at load time:

1. A `Scope` class already exists (ext-scope loaded via php.ini or `-d extension=`, or a future PHP with `Scope` in core): it is used, and `fiber_interop\scope\IS_POLYFILL` is `false`.
2. Opted in, on the CLI, with `pcntl_exec` available and a bundled binary for the running PHP build in `ext/` (`scope-<8.x>-<nts|zts>-<arch>-<glibc|musl|darwin>.so`): PHP re-executes itself with `-d extension=<binary>`. Opt in with the environment variable `FIBER_INTEROP_SCOPE_EXT=1`, or `"extra": {"fiber-interop/scope": {"ext": true}}` in the root `composer.json`. `FIBER_INTEROP_SCOPE_SO` points at a binary elsewhere. `FIBER_INTEROP_SCOPE_REEXEC=1` is set in the child so it never re-executes twice; if the binary fails to load, the child falls back to the polyfill.
3. Otherwise the polyfill in `src/Scope.php` is declared and `IS_POLYFILL` is `true`.

## Polyfill gaps

The polyfill keeps a stack of `run()` scopes per fiber (a `WeakMap` keyed by the fiber) plus one for `{main}`. `{main}` outside any `run()` sees the root scope. A fiber with no `run()` of its own sees the scope most recently made current, by `run()` entry or exit; so a fiber created and driven inside a request's `run()` keeps the request's scope without anyone's cooperation. It cannot add `Fiber::getScope()` or `Generator::getScope()`, and has two gaps the extension closes:

- A raw fiber created in scope A and resumed from scope B, without `$scope->run()` inside it, sees B (the engine says A). Libraries close this by capturing `Scope::getCurrent()` when they create a fiber and calling `$scope->run(...)` inside it.
- A generator sees the scope of the code iterating it (the consumer), not the scope current when it was created.

## Execution key provider (polyfill only)

By default the polyfill keys its stacks on `Fiber::getCurrent()` (`null` = `{main}`). Runtimes whose execution unit is not a Fiber (Swoole coroutines, for instance, all see `Fiber::getCurrent() === null`) would otherwise share the `{main}` stack. For them:

```php
function fiber_interop\scope\set_execution_key_provider(?callable $provider): void
```

`$provider()` returns `?object`, the object identifying the current execution unit; `null` means `{main}` (falls back to `Fiber::getCurrent()`). The polyfill uses `$provider() ?? Fiber::getCurrent()` as the `WeakMap` key, so a unit's stack is dropped when its object is released (Swoole: `Swoole\Coroutine::getContext()`, which dies with the coroutine). `null` restores the default.

It is a function, not a `Scope` method, because the polyfill class has exactly the native class's public API. Under the native extension it is a no-op that records nothing: the extension cannot see coroutine switches that are not fiber switches, so Swoole users should use the polyfill.

## Polyfill rules

- It declares nothing if a `Scope` class already exists.
- It has exactly the extension's names and signatures and no extra methods; new methods go into the RFC and the extension first.
- It does not `provide` `ext-scope`.
- Whether it is in use is exposed through the `fiber_interop\scope\IS_POLYFILL` constant, never on the class.

A project that declares its own global `Scope` class cannot install this package.

## Tests

```sh
composer install
vendor/bin/phpunit                                        # polyfill
php -d extension=../ext-scope/modules/scope.so vendor/bin/phpunit   # native
```

## License

MIT
