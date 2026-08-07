<?php

namespace Tests\Feature\Cleanup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Prompt 195 — a Livewire action named after one of `$wire`'s aliases is UNREACHABLE FROM A BROWSER, and
 * fails without a single symptom.
 *
 * Livewire v4's `generateWireObject()` builds a Proxy whose `get` trap consults an alias table BEFORE it
 * falls through to the handler that dispatches a component method:
 *
 *     if (property in aliases)      return getProperty(component, aliases[property]);   // ← taken
 *     else if (property in properties) …
 *     else return getFallback(component)(property);                                     // ← never reached
 *
 * `aliases` maps `commit` → `$commit`, a built-in state flush returning null. So `wire:click="commit"` ran
 * Livewire's own no-op, and `DispensaryPos::commit()` and `BarPos::commit()` were never invoked from the
 * counter. The buttons were enabled, hit-testable, and returned 200. **No sale could be completed from a
 * browser**, on either screen, for as long as they had those names.
 *
 * Forty-two tests exercised that path and all passed, because `Livewire::test(...)->call('commit')` invokes
 * the PHP method directly and never meets the proxy. They proved the method works. They could not prove the
 * button reaches it. That is the lesson this file exists to keep.
 *
 * The alias list is PARSED FROM THE VENDOR DIST rather than copied here — one writer per fact. A Livewire
 * upgrade that adds an alias colliding with an existing action then fails loudly instead of silently
 * killing another button.
 */
class LivewireReservedNameTest extends TestCase
{
    private const DIST = 'vendor/livewire/livewire/dist/livewire.esm.js';

    /** @return list<string> */
    private function aliases(): array
    {
        $dist = (string) file_get_contents(base_path(self::DIST));

        $this->assertMatchesRegularExpression('/var aliases = \{/', $dist,
            'Livewire\'s alias table was not found in '.self::DIST.'. If the dist changed shape, FIX THIS '.
            'PARSER — do not delete the test: it is the only thing standing between a rename and a dead button.');

        preg_match('/var aliases = \{(.*?)\};/s', $dist, $m);
        preg_match_all('/"([a-zA-Z]+)":/', $m[1], $names);

        $aliases = $names[1];
        $this->assertContains('commit', $aliases, 'The alias that caused prompt 195 is gone — verify before relaxing anything.');

        return $aliases;
    }

    /** @return list<class-string> */
    private function livewireClasses(): array
    {
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Livewire'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([app_path().'/', '/', '.php'], ['App\\', '\\', ''], $file->getPathname());

            if (class_exists($relative) || trait_exists($relative)) {
                $classes[] = $relative;
            }
        }

        sort($classes);

        return $classes;
    }

    public function test_no_livewire_action_is_named_after_a_wire_alias(): void
    {
        $aliases = $this->aliases();
        $offenders = [];

        foreach ($this->livewireClasses() as $class) {
            $reflection = new ReflectionClass($class);

            // getMethods() includes methods pulled in from traits, which is the point — a trait can put an
            // unreachable action on every screen that composes it. It ALSO includes Livewire's own base-class
            // methods (`id()`, `dispatch()`, `js()`), which are the framework's and are exactly what the
            // aliases point at — so only what this project DECLARES is a finding.
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                if (! str_starts_with($method->getDeclaringClass()->getName(), 'App\\')) {
                    continue;
                }

                if (str_starts_with($name, '$') || in_array($name, $aliases, true)) {
                    $offenders[] = $class.'::'.$name.'()';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "These Livewire actions are shadowed by \$wire's alias table and can NEVER be called from a ".
            'browser — the click returns 200 and runs a built-in no-op instead. Rename them: '.
            implode(', ', array_unique($offenders)));
    }

    public function test_no_blade_wire_directive_names_a_wire_alias(): void
    {
        // The same bug arriving from the template side: a correctly-named method with a `wire:click` that
        // names an alias is just as dead.
        $aliases = $this->aliases();
        $offenders = [];

        $directives = ['wire:click', 'wire:submit', 'wire:change', 'wire:blur', 'wire:target', 'wire:keydown'];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // `wire:click.prevent`, `wire:keydown.enter` etc. — the modifier is part of the directive, the
            // VALUE is what names the method.
            preg_match_all('/(wire:[a-z]+)(?:\.[a-z.]+)?="([a-zA-Z_$][a-zA-Z0-9_]*)/', $source, $m, PREG_SET_ORDER);

            foreach ($m as [$_, $directive, $value]) {
                if (! in_array($directive, $directives, true)) {
                    continue;
                }

                if (in_array($value, $aliases, true)) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname()).": {$directive}=\"{$value}\"";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'These Blade directives name a $wire alias, so the click runs a Livewire built-in instead of the '.
            'component action: '.implode(', ', array_unique($offenders)));
    }
}
