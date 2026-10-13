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

    public function testGapFiberConstructedByNonCooperatingCode(): void
    {
        if (!IS_POLYFILL) {
            $a = new \Scope();
            $f = $a->run(fn() => new \Fiber(fn() => \Scope::getCurrent()));
            $f->start();
            self::assertSame($a, $f->getReturn());
            return;
        }
        self::markTestSkipped('Polyfill gap: a fiber created with `new Fiber` by code that does not wrap its callable in Scope::getCurrent()->run() sees the root scope, not the scope current at its construction. The extension closes this gap.');
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
}
