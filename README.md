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

The polyfill keeps a stack of `run()` scopes per fiber (a `WeakMap` keyed by the fiber) plus one for `{main}`. `{main}` outside any `run()` sees the root scope. A fiber with no `run()` of its own sees the scope most recently made current, by `run()` entry or exit or by `fiber_interop\scope\activate()`; so a fiber created and driven inside a request's `run()` keeps the request's scope without anyone's cooperation. It cannot add `Fiber::getScope()` or `Generator::getScope()`, and has two gaps the extension closes:

- A raw fiber created in scope A and resumed from scope B, without `$scope->run()` inside it, sees B (the engine says A). Libraries close this by capturing `Scope::getCurrent()` when they create a fiber and calling `$scope->run(...)` inside it.
- A generator sees the scope of the code iterating it (the consumer), not the scope current when it was created.

## Execution key provider (polyfill only)

By default the polyfill keys its stacks on `Fiber::getCurrent()` (`null` = `{main}`). Runtimes whose execution unit is not a Fiber (Swoole coroutines, for instance, all see `Fiber::getCurrent() === null`) would otherwise share the `{main}` stack. For them:

```php
function fiber_interop\scope\set_execution_key_provider(?callable $provider): void
```

`$provider()` returns `?object`, the object identifying the current execution unit; `null` means `{main}` (falls back to `Fiber::getCurrent()`). The polyfill uses `$provider() ?? Fiber::getCurrent()` as the `WeakMap` key, so a unit's stack is dropped when its object is released (Swoole: `Swoole\Coroutine::getContext()`, which dies with the coroutine). `null` restores the default.

It is a function, not a `Scope` method, because the polyfill class has exactly the native class's public API. Under the native extension it is a no-op that records nothing: the extension cannot see coroutine switches that are not fiber switches, so Swoole users should use the polyfill.

## Per-scope superglobals (polyfill)

`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST` and `$_SERVER` are per scope:

- The root scope's are the SAPI's (whatever they hold at first use).
- A new scope starts with copies of its creator's six arrays (the scope current at `new Scope()`; copy-on-write, so this is free until written).
- Assignments inside a scope affect only that scope. Writing `$_GET = [...]` needs no call: it writes the live superglobal, which is captured into the scope's record at the next switch away.
- `$_SERVER` and `$_REQUEST` are, like the other four, auto-globals created lazily on first reference; the first scope creation reads all six, which forces them into existence then, mirroring the native extension.

The live superglobals belong to the *active* scope. Switching is done by one function:

```php
function fiber_interop\scope\activate(): void
```

It compares the scope it last activated with `Scope::getCurrent()`. If they are the same it returns: one comparison, no work. Otherwise it stores the six live values into the previously active scope's record and loads the current scope's. `Scope::run()` calls it on entry and on exit, so `run()` restores the previous scope's globals when it returns. Records live in a `WeakMap` keyed by the `Scope` object and die with it; the root's record is held strongly.

The invariant: **the active scope equals the current scope whenever user code runs.** `run()` keeps it within one execution unit; the event loop keeps it across switches. Adapters call `activate()` whenever the loop hands control to user code: at task start, after a resume, before a queued callback, and after `run()` returns to `{main}`. Calling it on every transfer is cheap, since a swap only happens on a real change of scope. Coverage therefore depends on the adapter; see the scope-state column of the [conformance table](https://fiber-interop.github.io/conformance.html).

Under the native extension `activate()` exists and does nothing: per-scope superglobals are engine-level in the extension (RFC secondary vote A).

## Per-scope default timezone and locale (polyfill)

Secondary vote B: `date_default_timezone_get()/set()` and, when ext/intl is loaded, `Locale::getDefault()/setDefault()` follow the scope. The root has today's values, a new scope inherits its creator's at creation, and a change inside a scope affects only that scope.

**Polyfill limitation.** Userland cannot intercept `date_default_timezone_set()` or `Locale::setDefault()` (the extension does, at engine level). The polyfill reconciles at switch points instead: when a scope is left, the live timezone and locale are read into that scope's record; when a scope is entered (`run()` entry/exit, or `activate()` when it really swaps) its values are applied, but only if they differ from the live ones. A change made and read within one scope with no switch in between is naturally correct, and other scopes see their own values after the next switch. Code that changes the timezone from a fiber switch the loop does not report through `activate()` can leak the value to a sibling until the next switch. A scope whose timezone was never set inherits the creator's *effective* value (the ini fallback resolved at creation), not "unset". `Locale::getDefault()` is ignored unless ext/intl is loaded before the first `Scope` is created.

Cost: one `date_default_timezone_get()` (plus `Locale::getDefault()` with intl) and a string comparison per real switch, roughly 0.1-0.3 µs.

## For runtime authors: `generation()` (polyfill only, `@internal`)

```php
function fiber_interop\scope\generation(): int
```

A counter that strictly increases whenever the polyfill's active scope may have changed: on every `Scope::run()` entry and exit (including exit by exception, and including a `run()` that returns to the scope it left) and whenever `activate()` swaps scopes. It exists because a userland loop must know each coroutine's current scope at every suspend: the coroutine can change it with `$scope->run()`. Loops read `generation()` at resume and at suspend, and call `Scope::getCurrent()` only when it moved. It is not part of the Scope RFC and is declared only when `IS_POLYFILL` is `true`: under ext-scope the engine tracks the scope per fiber and never saves or restores it, so loops read `Fiber::getScope()` there instead.

## Polyfill rules

- It declares nothing if a `Scope` class already exists.
- It has exactly the extension's names and signatures and no extra methods; new methods go into the RFC and the extension first.
- It does not `provide` `ext-scope`.
- Whether it is in use is exposed through the `fiber_interop\scope\IS_POLYFILL` constant, never on the class.

A project that declares its own global `Scope` class cannot install this package.

## Release 0.3.0

0.3.0 is the "Scope spec level": the RFC draft plus secondary votes A (per-scope superglobals) and B (per-scope default timezone and locale, above). Version 0.3 adds vote B to the polyfill.

## Tests

```sh
composer install
vendor/bin/phpunit                                        # polyfill
php -d extension=../ext-scope/modules/scope.so vendor/bin/phpunit   # native
```

## License

MIT
