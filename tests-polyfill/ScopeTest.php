<?php

declare(strict_types=1);

namespace fiber_interop\scope\tests;

use PHPUnit\Framework\TestCase;

use const fiber_interop\scope\IS_POLYFILL;

/**
 * The behaviours of tests/*.phpt that userland can provide. Runs against
 * whichever Scope is loaded: the polyfill, or the extension (IS_POLYFILL false).
 */
final class ScopeTest extends TestCase
{
    public function testClassShape(): void
    {
        $c = new \ReflectionClass(\Scope::class);
        self::assertTrue($c->isFinal());
        $methods = array_map(fn($m) => $m->getName(), array_filter($c->getMethods(\ReflectionMethod::IS_PUBLIC), fn($m) => !in_array($m->getName(), ['__clone', '__serialize', '__unserialize'])));
        sort($methods);
        self::assertSame(['__construct', 'getCurrent', 'getRootScope', 'run'], $methods);
        self::assertSame('Scope', (string) $c->getMethod('getCurrent')->getReturnType());
        self::assertSame('Scope', (string) $c->getMethod('getRootScope')->getReturnType());
        self::assertSame('mixed', (string) $c->getMethod('run')->getReturnType());
        self::assertSame(\extension_loaded('scope') === false, IS_POLYFILL);
    }

    public function testRootIsIdenticalAcrossCalls(): void
    {
        self::assertSame(\Scope::getRootScope(), \Scope::getRootScope());
    }

    public function testRootIsCurrentInMain(): void
    {
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testFiberConstructedInMainSeesRoot(): void
    {
        $seen = null;
        (new \Fiber(function () use (&$seen) { $seen = \Scope::getCurrent(); }))->start();
        self::assertSame(\Scope::getRootScope(), $seen);
    }

    public function testRunNestsReturnsAndRestores(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $r = $a->run(function (int $x) use ($a, $b) {
            self::assertSame($a, \Scope::getCurrent());
            $b->run(fn() => self::assertSame($b, \Scope::getCurrent()));
            self::assertSame($a, \Scope::getCurrent());
            return $x * 2;
        }, 21);
        self::assertSame(42, $r);
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testRunRestoresAfterException(): void
    {
        try {
            (new \Scope())->run(fn() => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testRootRunInsideRequestScopeMakesRootCurrentAndRestores(): void
    {
        $req = new \Scope();
        $req->run(function () use ($req) {
            \Scope::getRootScope()->run(fn() => self::assertSame(\Scope::getRootScope(), \Scope::getCurrent()));
            self::assertSame($req, \Scope::getCurrent());
        });
    }

    public function testRootSurvivesGarbageCollection(): void
    {
        $id = spl_object_id(\Scope::getRootScope());
        $w = \WeakReference::create(\Scope::getRootScope());
        gc_collect_cycles();
        self::assertSame(\Scope::getRootScope(), $w->get());
        self::assertSame($id, spl_object_id(\Scope::getRootScope()));
    }

    public function testRunScopeIsKeptPerFiberAcrossSuspension(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $f = new \Fiber(fn() => $a->run(function () use ($a) {
            \Fiber::suspend();
            self::assertSame($a, \Scope::getCurrent());
        }));
        $f->start();
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
        $b->run(fn() => $f->resume());
        self::assertTrue($f->isTerminated());
    }

    public function testCooperatingLibraryCarriesScopeIntoFiber(): void
    {
        // What go() and the adapters do: capture at construction, run() inside.
        $spawn = function (\Closure $fn): \Fiber {
            $scope = \Scope::getCurrent();
            return new \Fiber(fn() => $scope->run($fn));
        };
        $a = new \Scope();
        $f = $a->run(fn() => $spawn(fn() => \Scope::getCurrent()));
        (new \Scope())->run(fn() => $f->start());
        self::assertSame($a, $f->getReturn());
    }

    public function testUnreferencedScopeIsFreed(): void
    {
        $s = new \Scope(); $w = \WeakReference::create($s);
        $s->run(fn() => null);
        unset($s);
        self::assertNull($w->get());
    }

    public function testFiberConstructedAndDrivenInsideRunKeepsTheScope(): void
    {
        // Polyfill: an unknown fiber resolves to the active scope (the one run() made current).
        $a = new \Scope();
        $f = $a->run(function () {
            $f = new \Fiber(fn() => \Scope::getCurrent());
            $f->start();
            return $f;
        });
        self::assertSame($a, $f->getReturn());
    }

    public function testGapRawFiberResumedFromAnotherScope(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $f = $a->run(fn() => new \Fiber(function () { \Fiber::suspend(); return \Scope::getCurrent(); }));
        $a->run(fn() => $f->start());
        $b->run(fn() => $f->resume());
        if (!IS_POLYFILL) {
            self::assertSame($a, $f->getReturn());
            return;
        }
        self::assertSame($b, $f->getReturn());
        self::markTestSkipped('Polyfill gap: a fiber with no run() of its own sees the scope most recently made current, so a raw fiber created in scope A and resumed from scope B without $scope->run() sees B. The extension closes this gap.');
    }

    public function testGapGeneratorSeesConsumerScope(): void
    {
        if (!IS_POLYFILL) {
            $a = new \Scope();
            $g = $a->run(fn() => (function () { yield \Scope::getCurrent(); })());
            self::assertSame($a, (new \Scope())->run(fn() => $g->current()));
            return;
        }
        self::markTestSkipped('Polyfill gap: a generator sees the scope of the code iterating it, not the scope current at its creation. The extension closes this gap.');
    }

    public function testExecutionKeyProviderIsolatesStacksPerKey(): void
    {
        if (!IS_POLYFILL) {
            \fiber_interop\scope\set_execution_key_provider(fn() => new \stdClass());
            self::assertTrue(true, 'no-op under the extension');
            return;
        }
        $current = null;
        \fiber_interop\scope\set_execution_key_provider(function () use (&$current) { return $current; });
        try {
            $k1 = new \stdClass();
            $k2 = new \stdClass();
            $a = new \Scope();
            $b = new \Scope();
            $current = $k1;
            $a->run(function () use (&$current, $k1, $k2, $a, $b) {
                self::assertSame($a, \Scope::getCurrent());
                $current = $k2; // unknown key: the scope most recently made current
                self::assertSame($a, \Scope::getCurrent());
                $b->run(function () use (&$current, $k1, $k2, $b) {
                    self::assertSame($b, \Scope::getCurrent());
                    $current = $k1;
                    self::assertNotSame($b, \Scope::getCurrent());
                    $current = $k2;
                });
                $current = $k1;
                self::assertSame($a, \Scope::getCurrent());
            });
            $current = null; // null key: {main}
            self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
        } finally {
            \fiber_interop\scope\set_execution_key_provider(null);
        }
    }

    public function testExecutionKeyIsNotRetainedByThePolyfill(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped('polyfill only');
        }
        $holder = [new \stdClass()];
        \fiber_interop\scope\set_execution_key_provider(function () use (&$holder) { return $holder[0] ?? null; });
        try {
            $map = new \ReflectionProperty(\Scope::class, 'fibers');
            $weak = \WeakReference::create($holder[0]);
            (new \Scope())->run(function () use ($map) {
                self::assertCount(1, $map->getValue());
            });
            self::assertCount(0, $map->getValue(), 'stack dropped when the last run() ends');
            $holder = [];
            self::assertNull($weak->get(), 'the WeakMap does not keep the key alive');
        } finally {
            \fiber_interop\scope\set_execution_key_provider(null);
        }
    }

    private const SKIP_NATIVE = "engine-level in the RFC's secondary vote; not provided by the extension yet";

    /** @var array<int, array> */
    private array $savedGlobals;

    protected function setUp(): void
    {
        $this->savedGlobals = [$_GET, $_POST, $_COOKIE, $_FILES];
    }

    protected function tearDown(): void
    {
        [$_GET, $_POST, $_COOKIE, $_FILES] = $this->savedGlobals;
    }

    public function testActivateExistsAndIsANoOpUnderTheExtension(): void
    {
        self::assertTrue(\function_exists('fiber_interop\\scope\\activate'));
        if (IS_POLYFILL) {
            self::markTestSkipped('extension only');
        }
        $_GET = ['x' => 1];
        (new \Scope())->run(function () {
            $_GET = ['x' => 2];
            \fiber_interop\scope\activate();
        });
        \fiber_interop\scope\activate();
        self::assertSame(['x' => 2], $_GET);
    }

    public function testSuperglobalsArePerScopeAcrossRun(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $_GET = ['who' => 'root']; $_POST = ['p' => 'root']; $_COOKIE = ['c' => 'root']; $_FILES = [];
        $a = new \Scope(); $b = new \Scope();
        $a->run(function () { self::assertSame(['who' => 'root'], $_GET); $_GET = ['who' => 'a']; $_POST = ['p' => 'a']; });
        $b->run(function () { self::assertSame(['who' => 'root'], $_GET); $_GET = ['who' => 'b']; $_COOKIE = ['c' => 'b']; });
        $a->run(function () {
            self::assertSame(['who' => 'a'], $_GET);
            self::assertSame(['p' => 'a'], $_POST);
            self::assertSame(['c' => 'root'], $_COOKIE);
        });
        $b->run(function () {
            self::assertSame(['who' => 'b'], $_GET);
            self::assertSame(['p' => 'root'], $_POST);
            self::assertSame(['c' => 'b'], $_COOKIE);
        });
        self::assertSame(['who' => 'root'], $_GET, 'main sees the root values after the outermost run()');
        self::assertSame(['p' => 'root'], $_POST);
        self::assertSame(['c' => 'root'], $_COOKIE);
    }

    public function testNestedScopeStartsWithACopyAndDoesNotLeak(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $_GET = ['who' => 'root'];
        $a = new \Scope();
        $a->run(function () {
            $_GET['who'] = 'a';
            $child = new \Scope();
            $child->run(function () {
                self::assertSame(['who' => 'a'], $_GET, 'inherits a copy of its creator');
                $_GET['who'] = 'child';
                $_GET['extra'] = 1;
            });
            self::assertSame(['who' => 'a'], $_GET, 'the child write does not leak to the parent');
            $child->run(fn() => self::assertSame(['who' => 'child', 'extra' => 1], $_GET));
        });
        self::assertSame(['who' => 'root'], $_GET);
    }

    public function testActivateSwitchesWithAProviderDrivenKey(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $_GET = ['who' => 'root'];
        $key = $k1 = new \stdClass(); $k2 = new \stdClass();
        \fiber_interop\scope\set_execution_key_provider(function () use (&$key) { return $key; });
        try {
            $a = new \Scope(); $b = new \Scope();
            $a->run(function () use (&$key, $k1, $k2, $b) {
                $_GET = ['who' => 'a'];
                $key = $k2;
                $b->run(function () use (&$key, $k1, $k2) {
                    $_GET = ['who' => 'b'];
                    $key = $k1;                       // the loop switches to unit 1 ...
                    \fiber_interop\scope\activate();  // ... and activates its scope
                    self::assertSame(['who' => 'a'], $_GET);
                    $key = $k2;
                    \fiber_interop\scope\activate();
                    self::assertSame(['who' => 'b'], $_GET);
                });
                $key = $k1;
                \fiber_interop\scope\activate();
                self::assertSame(['who' => 'a'], $_GET);
            });
            $key = null;
            self::assertSame(['who' => 'root'], $_GET);
        } finally {
            \fiber_interop\scope\set_execution_key_provider(null);
        }
    }

    public function testActivateIsANoOpWhenTheScopeIsUnchanged(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $_GET = ['v' => 0];
        $records = new \ReflectionProperty(\Scope::class, 'globals');
        $a = new \Scope();
        $a->run(function () use ($a, $records) {
            $_GET = ['v' => 1];
            \fiber_interop\scope\activate();
            self::assertSame(['v' => 1], $_GET);
            self::assertSame(['v' => 0], $records->getValue()[$a][0], 'nothing was saved: no swap happened');
        });
        self::assertSame(['v' => 1], $records->getValue()[$a][0], 'saved when run() switched away');
    }
}
