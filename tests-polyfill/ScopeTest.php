<?php

declare(strict_types=1);

namespace fiber_interop\scope\tests;

use PHPUnit\Framework\TestCase;

use const fiber_interop\scope\IS_POLYFILL;

use function fiber_interop\scope\activate;
use function fiber_interop\scope\generation;
use function fiber_interop\scope\set_execution_key_provider;

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
        // __run_without() is the extension's, not part of the RFC
        $methods = array_map(fn($m) => $m->getName(), array_filter($c->getMethods(\ReflectionMethod::IS_PUBLIC), fn($m) => !in_array($m->getName(), ['__clone', '__serialize', '__unserialize', '__run_without'])));
        sort($methods);
        self::assertSame(['__construct', 'bind', 'getCurrent', 'getRootScope', 'run'], $methods);
        self::assertSame('Scope', (string) $c->getMethod('getCurrent')->getReturnType());
        self::assertSame('Scope', (string) $c->getMethod('getRootScope')->getReturnType());
        self::assertSame('mixed', (string) $c->getMethod('run')->getReturnType());
        // Native from ext-scope, or from ext-phasync, which bundles it
        self::assertSame(!$c->isInternal(), IS_POLYFILL);
    }

    public function testRootIsIdenticalAcrossCalls(): void
    {
        self::assertSame(\Scope::getRootScope(), \Scope::getRootScope());
    }

    public function testRootIsCurrentInMain(): void
    {
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testFiberStartedInMainSeesRoot(): void
    {
        $seen = null;
        (new \Fiber(function () use (&$seen) { $seen = \Scope::getCurrent(); }))->start();
        self::assertSame(\Scope::getRootScope(), $seen);
    }

    public function testFiberRunsInTheScopeItIsStartedInNotConstructedIn(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $f = $a->run(fn() => new \Fiber(fn() => \Scope::getCurrent()));
        $b->run(fn() => $f->start());
        self::assertSame($b, $f->getReturn());
        $f = $a->run(fn() => new \Fiber(fn() => \Scope::getCurrent()));
        $f->start();
        self::assertSame(\Scope::getRootScope(), $f->getReturn());
    }

    public function testFiberOfABoundClosureRunsInItsScopeWhoeverStartsAndResumesIt(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $f = new \Fiber($a->bind(fn() => \Scope::getCurrent()));
        $f->start();
        self::assertSame($a, $f->getReturn());
        $f = new \Fiber($a->bind(function () {
            $seen = [\Scope::getCurrent()];
            \Fiber::suspend();
            $seen[] = \Scope::getCurrent();
            return $seen;
        }));
        $b->run(fn() => $f->start());
        $b->run(fn() => $f->resume());
        self::assertSame([$a, $a], $f->getReturn());
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testFiberSuspendedInsideRunIsBackInItsStartScopeAfterThatRun(): void
    {
        $a = new \Scope(); $b = new \Scope(); $c = new \Scope();
        $f = new \Fiber(function () use ($c) {
            $seen = [$c->run(function () { \Fiber::suspend(); return \Scope::getCurrent(); })];
            $seen[] = \Scope::getCurrent();
            return $seen;
        });
        $a->run(fn() => $f->start());
        $b->run(fn() => $f->resume());
        self::assertSame([$c, $a], $f->getReturn());
    }

    public function testThereIsNoFiberGetScope(): void
    {
        self::assertFalse(method_exists(\Fiber::class, 'getScope'));
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
        // A library that hands its fibers to something that starts them from another scope binds them.
        $spawn = fn(\Closure $fn): \Fiber => new \Fiber(\Scope::getCurrent()->bind($fn));
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

    public function testFiberStartedAndDrivenInsideRunKeepsTheScope(): void
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
        $f = new \Fiber(function () { \Fiber::suspend(); return \Scope::getCurrent(); });
        $a->run(fn() => $f->start());
        $b->run(fn() => $f->resume());
        if (!IS_POLYFILL) {
            self::assertSame($a, $f->getReturn());
            return;
        }
        self::assertSame($b, $f->getReturn());
        self::markTestSkipped('Polyfill gap: a fiber with no run() of its own sees the scope most recently made current, so a raw fiber started in scope A and resumed from scope B without $scope->run() sees B. The extension closes this gap.');
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
        $this->savedGlobals = [$_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER];
    }

    protected function tearDown(): void
    {
        [$_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER] = $this->savedGlobals;
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
        // the extension follows the scope itself (secondary vote A): the write stayed in the scope
        self::assertSame(['x' => 1], $_GET);
    }

    public function testSuperglobalsArePerScopeAcrossRun(): void
    {
        $_GET = ['who' => 'root']; $_POST = ['p' => 'root']; $_COOKIE = ['c' => 'root']; $_FILES = [];
        $a = new \Scope(); $b = new \Scope();
        $a->run(function () { self::assertSame([], $_GET); $_GET = ['who' => 'a']; $_POST = ['p' => 'a']; });
        $b->run(function () { self::assertSame([], $_GET); $_GET = ['who' => 'b']; $_COOKIE = ['c' => 'b']; });
        $a->run(function () {
            self::assertSame(['who' => 'a'], $_GET);
            self::assertSame(['p' => 'a'], $_POST);
            self::assertSame([], $_COOKIE);
        });
        $b->run(function () {
            self::assertSame(['who' => 'b'], $_GET);
            self::assertSame([], $_POST);
            self::assertSame(['c' => 'b'], $_COOKIE);
        });
        self::assertSame(['who' => 'root'], $_GET, 'main sees the root values after the outermost run()');
        self::assertSame(['p' => 'root'], $_POST);
        self::assertSame(['c' => 'root'], $_COOKIE);
    }

    public function testNestedScopeStartsEmptyAndDoesNotLeak(): void
    {
        $_GET = ['who' => 'root'];
        $a = new \Scope();
        $a->run(function () {
            $_GET['who'] = 'a';
            $child = new \Scope();
            $child->run(function () {
                self::assertSame([], $_GET, 'starts empty, not a copy of its creator');
                $_GET['who'] = 'child';
                $_GET['extra'] = 1;
            });
            self::assertSame(['who' => 'a'], $_GET, 'the child write does not leak to the parent');
            $child->run(fn() => self::assertSame(['who' => 'child', 'extra' => 1], $_GET));
        });
        self::assertSame(['who' => 'root'], $_GET);
    }

    public function testRequestAndServerArePerScopeAcrossRun(): void
    {
        $_REQUEST = ['who' => 'root']; $_SERVER['who'] = 'root';
        $a = new \Scope(); $b = new \Scope();
        $a->run(function () { self::assertFalse(isset($_SERVER['who'])); $_REQUEST = ['who' => 'a']; $_SERVER['who'] = 'a'; });
        $b->run(function () { self::assertFalse(isset($_SERVER['who'])); $_REQUEST = ['who' => 'b']; $_SERVER['who'] = 'b'; });
        $a->run(function () {
            self::assertSame(['who' => 'a'], $_REQUEST);
            self::assertSame('a', $_SERVER['who']);
        });
        $b->run(function () {
            self::assertSame(['who' => 'b'], $_REQUEST);
            self::assertSame('b', $_SERVER['who']);
        });
        self::assertSame(['who' => 'root'], $_REQUEST, 'main sees the root values after the outermost run()');
        self::assertSame('root', $_SERVER['who']);
    }

    public function testNestedScopeServerStartsEmptyAndDoesNotLeak(): void
    {
        // "argv" is the SAPI's: present before any scope exists, which is what "environment
        // entries present" means for $_SERVER.
        self::assertTrue(isset($_SERVER['argv']));
        $_SERVER['who'] = 'root';
        $a = new \Scope();
        $a->run(function () {
            self::assertSame([], $_SERVER, 'a new scope starts with an empty \$_SERVER');
            $_SERVER['who'] = 'a';
            $child = new \Scope();
            $child->run(function () {
                self::assertSame([], $_SERVER);
                $_SERVER['who'] = 'child';
            });
            self::assertSame('a', $_SERVER['who'], 'the child write does not leak to the parent');
            $child->run(fn() => self::assertSame('child', $_SERVER['who']));
        });
        self::assertSame('root', $_SERVER['who']);
    }

    public function testServerAndRequestAlternateAcrossFiberSuspension(): void
    {
        // A fiber runs in the scope it is started in (RFC); wrapping its resume() in that scope's
        // run() too (as testRunScopeIsKeptPerFiberAcrossSuspension does) is what keeps the live
        // arrays right across the suspension in between under the polyfill, whose simpler model
        // does not tie a fiber to its start scope on its own.
        $_SERVER['v'] = 'root'; $_REQUEST['v'] = 'root';
        $a = new \Scope(); $b = new \Scope();
        $fa = $a->run(fn() => new \Fiber(function () {
            $_SERVER['v'] = 'A'; $_REQUEST['v'] = 'A';
            \Fiber::suspend();
            self::assertSame('A', $_SERVER['v']);
            self::assertSame('A', $_REQUEST['v']);
        }));
        $fb = $b->run(fn() => new \Fiber(function () {
            $_SERVER['v'] = 'B'; $_REQUEST['v'] = 'B';
            \Fiber::suspend();
            self::assertSame('B', $_SERVER['v']);
            self::assertSame('B', $_REQUEST['v']);
        }));
        $a->run(fn() => $fa->start());
        $b->run(fn() => $fb->start());
        self::assertSame('root', $_SERVER['v']);
        self::assertSame('root', $_REQUEST['v']);
        $a->run(fn() => $fa->resume());
        $b->run(fn() => $fb->resume());
        self::assertTrue($fa->isTerminated() && $fb->isTerminated());
        self::assertSame('root', $_SERVER['v']);
        self::assertSame('root', $_REQUEST['v']);
    }

    public function testFiberSeesTheSuperglobalsOfItsStartScope(): void
    {
        $_GET = ['who' => 'root'];
        $a = new \Scope(); $b = new \Scope();
        $a->run(function () { $_GET = ['who' => 'A']; });
        $b->run(function () { $_GET = ['who' => 'B']; });
        $f = $a->run(fn() => new \Fiber(fn() => $_GET['who']));
        $b->run(fn() => $f->start());
        self::assertSame('B', $f->getReturn());
        $f = new \Fiber($a->bind(fn() => $_GET['who']));
        $b->run(fn() => $f->start());
        self::assertSame('A', $f->getReturn());
        self::assertSame('root', $_GET['who']);
    }

    public function testGlobalsArrayAndGlobalKeywordFollowServer(): void
    {
        $_SERVER['v'] = 'root';
        $s = new \Scope();
        $s->run(function () {
            $GLOBALS['_SERVER']['v'] = 'child-via-GLOBALS';
            self::assertSame('child-via-GLOBALS', (function () { global $_SERVER; return $_SERVER['v']; })());
        });
        self::assertSame('root', $_SERVER['v']);
        self::assertSame('root', $GLOBALS['_SERVER']['v']);
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
            self::assertSame([], $records->getValue()[$a][0], 'nothing was saved: no swap happened');
        });
        self::assertSame(['v' => 1], $records->getValue()[$a][0], 'saved when run() switched away');
    }

    public function testGenerationExistsOnlyInThePolyfill(): void
    {
        self::assertSame(IS_POLYFILL, \function_exists('fiber_interop\\scope\\generation'));
    }

    public function testGenerationIsUnchangedAcrossPlainCode(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $g = generation();
        self::assertIsInt($g);
        \Scope::getCurrent();
        \Scope::getRootScope();
        new \Scope();
        $f = new \Fiber(function () { \Fiber::suspend(); });
        $f->start();
        $f->resume();
        self::assertSame($g, generation());
    }

    public function testGenerationBumpsOnRunEnterAndLeave(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        foreach ([new \Scope(), \Scope::getRootScope()] as $scope) {   // the root: a run() that changes nothing
            $before = generation();
            $inside = $scope->run(fn () => generation());
            self::assertGreaterThan($before, $inside);
            self::assertGreaterThan($inside, generation());
        }
    }

    public function testGenerationBumpsOnRunLeaveByException(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $inside = null;
        try {
            (new \Scope())->run(function () use (&$inside) {
                $inside = generation();
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
        }
        self::assertGreaterThan($inside, generation());
    }

    public function testGenerationBumpsOnActivateOnlyWhenItSwitches(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $key = $k1 = new \stdClass();
        set_execution_key_provider(function () use (&$key) { return $key; });
        try {
            $a = new \Scope();
            $a->run(function () use (&$key, $k1) {
                $g = generation();
                activate();
                self::assertSame($g, generation(), 'unchanged scope: no bump');
                $key = null;                          // the loop switches to {main} (root) ...
                activate();
                self::assertGreaterThan($g, $g = generation());
                $key = $k1;                           // ... and back to unit 1 (a)
                activate();
                self::assertGreaterThan($g, generation());
            });
        } finally {
            $key = null;
            set_execution_key_provider(null);
        }
    }

    public function testGenerationMovesWhenAnotherFiberMadeAScopeCurrent(): void
    {
        if (!IS_POLYFILL) {
            self::markTestSkipped(self::SKIP_NATIVE);
        }
        $b = new \Scope();
        $seen = [];
        $loop = new \Fiber(function () use (&$seen) {       // no run() of its own: sees the active scope
            while (true) {
                $seen[] = [generation(), \Scope::getCurrent()];
                \Fiber::suspend();
            }
        });
        $other = new \Fiber(fn () => $b->run(fn () => \Fiber::suspend()));
        $loop->start();
        $other->start();                                    // enters $b and suspends inside it
        $loop->resume();
        self::assertGreaterThan($seen[0][0], $seen[1][0]);
        self::assertSame(\Scope::getRootScope(), $seen[0][1]);
        self::assertSame($b, $seen[1][1]);
        $other->resume();                                   // leaves $b
        $loop->resume();
        self::assertGreaterThan($seen[1][0], $seen[2][0]);
        self::assertSame(\Scope::getRootScope(), $seen[2][1]);
    }

    // --- Secondary vote B: default timezone and locale follow the scope ---

    public function testTimezoneRootUnchanged(): void
    {
        $tz = date_default_timezone_get();
        (new \Scope())->run(fn() => 1);
        self::assertSame($tz, date_default_timezone_get());
    }

    public function testTimezoneInheritsAtCreationAndIsolatesSiblings(): void
    {
        $orig = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Oslo');
            $a = new \Scope();
            date_default_timezone_set('UTC');
            $b = new \Scope();                      // inherits UTC
            $a->run(fn() => date_default_timezone_set('Europe/Oslo'));
            $a->run(fn() => date_default_timezone_set('Asia/Tokyo'));
            $b->run(fn() => date_default_timezone_set('America/Chicago'));
            self::assertSame('UTC', date_default_timezone_get());
            self::assertSame('Asia/Tokyo', $a->run(fn() => date_default_timezone_get()));
            self::assertSame('America/Chicago', $b->run(fn() => date_default_timezone_get()));
            self::assertSame(
                'Asia/Tokyo America/Chicago Asia/Tokyo',
                $a->run(fn() => date_default_timezone_get() . ' ' . $b->run(fn() => date_default_timezone_get()) . ' ' . date_default_timezone_get()),
            );
            // A scope created inside a scope starts from ini, not from the creator.
            self::assertSame(ini_get('date.timezone') ?: 'UTC', $a->run(fn() => (new \Scope())->run(fn() => date_default_timezone_get())));
        } finally {
            date_default_timezone_set($orig);
        }
    }

    public function testTimezoneOfNewScopeComesFromIniNotCreator(): void
    {
        $orig = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Tokyo');
            $expected = ini_get('date.timezone') ?: 'UTC';
            self::assertSame($expected, (new \Scope())->run(fn() => date_default_timezone_get()));
            self::assertSame('Asia/Tokyo', date_default_timezone_get());
        } finally {
            date_default_timezone_set($orig);
        }
    }

    public function testNewScopeSuperglobalsAreEmptyAndRootIsUnchanged(): void
    {
        $_GET = ['r' => 1]; $_POST = ['r' => 2]; $_COOKIE = ['r' => 3]; $_FILES = ['r' => 4]; $_REQUEST = ['r' => 5];
        $_SERVER['rootmark'] = 1;
        (new \Scope())->run(function () {
            self::assertSame([], $_GET);
            self::assertSame([], $_POST);
            self::assertSame([], $_COOKIE);
            self::assertSame([], $_FILES);
            self::assertSame([], $_REQUEST);
            self::assertSame([], $_SERVER);
        });
        self::assertSame(['r' => 1], $_GET);
        self::assertSame(['r' => 5], $_REQUEST);
        self::assertSame(1, $_SERVER['rootmark']);
        self::assertTrue(isset($_SERVER['argv']));
    }

    public function testReferenceToSuperglobalSeesWritesFromAnotherFiberOfTheScope(): void
    {
        (new \Scope())->run(function () {
            $g = &$_GET;
            $f = new \Fiber(function () { $_GET['k'] = 'v'; });
            $f->start();
            self::assertSame(['k' => 'v'], $g);
            self::assertSame(['k' => 'v'], $_GET);
        });
    }

    public function testNewScopeHasNoSession(): void
    {
        (new \Scope())->run(fn() => self::assertFalse(isset($_SESSION)));
    }

    public function testBindRunsInTheBoundScopeAndLeavesTheOriginalAlone(): void
    {
        $a = new \Scope();
        $orig = fn() => \Scope::getCurrent();
        $bound = $a->bind($orig);
        self::assertNotSame($orig, $bound);
        $b = new \Scope();
        self::assertSame($a, $b->run($bound));
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
        self::assertSame($b, $b->run($orig));
        $add = $a->bind(fn(int $x, int $y) => [$x + $y, \Scope::getCurrent()]);
        self::assertSame([5, $a], $add(2, 3));
    }

    public function testBindRestoresTheScopeWhenTheClosureThrows(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $bound = $a->bind(function () { throw new \RuntimeException('x'); });
        $b->run(function () use ($bound, $b) {
            try { $bound(); self::fail('no throw'); } catch (\RuntimeException) {}
            self::assertSame($b, \Scope::getCurrent());
        });
        self::assertSame(\Scope::getRootScope(), \Scope::getCurrent());
    }

    public function testBindingABoundClosureReplacesItsScope(): void
    {
        $a = new \Scope(); $b = new \Scope();
        $inB = $b->bind(fn() => \Scope::getCurrent());
        $inA = $a->bind($inB);
        self::assertSame($a, $inA());
        self::assertSame($b, $inB());
        self::assertSame($b, $b->bind($inA)());
    }

    public function testBindKeepsByReferenceParameters(): void
    {
        if (IS_POLYFILL) {
            self::markTestSkipped('by-reference parameters through bind() need the extension');
        }
        $bound = (new \Scope())->bind(function (int &$n) { $n++; });
        $v = 1;
        $bound($v);
        self::assertSame(2, $v);
    }

    public function testTimezoneSharedAcrossFibersOfOneScope(): void
    {
        $orig = date_default_timezone_get();
        try {
            $a = new \Scope();
            $seen = $a->run(function () {
                $f = new \Fiber(function () { echo ''; return date_default_timezone_get(); });
                date_default_timezone_set('Asia/Tokyo');
                $f->start();
                return $f->getReturn();
            });
            self::assertSame('Asia/Tokyo', $seen);
            self::assertSame($orig, date_default_timezone_get());
        } finally {
            date_default_timezone_set($orig);
        }
    }

    public function testDateOutputReflectsScopeTimezone(): void
    {
        $orig = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $a = new \Scope();
            $a->run(fn() => date_default_timezone_set('Asia/Tokyo'));
            self::assertSame('1970-01-01 00:00', date('Y-m-d H:i', 0));
            self::assertSame('1970-01-01 09:00', $a->run(fn() => date('Y-m-d H:i', 0)));
            self::assertSame('1970-01-01 00:00', date('Y-m-d H:i', 0));
        } finally {
            date_default_timezone_set($orig);
        }
    }

    public function testLocaleFollowsScope(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('intl not loaded');
        }
        $orig = \Locale::getDefault();
        try {
            $start = (new \Scope())->run(fn() => \Locale::getDefault());
            \Locale::setDefault($start === 'de_DE' ? 'en_US' : 'de_DE');
            $creator = \Locale::getDefault();
            $a = new \Scope();
            $b = new \Scope();
            $a->run(function () { \Locale::setDefault('nb_NO'); });
            self::assertSame($creator, \Locale::getDefault());
            self::assertSame('nb_NO', $a->run(fn() => \Locale::getDefault()));
            self::assertSame($start, $b->run(fn() => \Locale::getDefault()));
            self::assertSame($creator, \Locale::getDefault());
        } finally {
            \Locale::setDefault($orig);
        }
    }

    public function testTimezoneStress(): void
    {
        $orig = date_default_timezone_get();
        try {
            $zones = ['UTC', 'Asia/Tokyo', 'Europe/Oslo', 'America/Chicago'];
            $scopes = [];
            for ($i = 0; $i < 1000; $i++) {
                $scopes[$i] = new \Scope();
                $scopes[$i]->run(fn() => date_default_timezone_set($zones[$i % 4]));
            }
            for ($i = 999; $i >= 0; $i--) {
                self::assertSame($zones[$i % 4], $scopes[$i]->run(fn() => date_default_timezone_get()));
            }
            self::assertSame($orig, date_default_timezone_get());
        } finally {
            date_default_timezone_set($orig);
        }
    }
}
