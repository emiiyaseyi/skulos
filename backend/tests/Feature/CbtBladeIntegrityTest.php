<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Tests\TestCase;

/**
 * Guards against two Blade traps that both fail SILENTLY — the
 * template compiles without error, but produces broken PHP whose
 * reported error line points somewhere innocent. Both cost real
 * debugging time on this module, so they are asserted here rather
 * than left to be rediscovered.
 *
 * TRAP 1 — mixing the inline php() directive form with a php...endphp
 * block in the same template. The compiler treats the inline form as
 * REOPENING raw-PHP mode, so every directive after it is emitted as
 * literal text. Symptom: a confusing parse error about an "unexpected
 * elseif" on a line that is perfectly valid.
 *
 * TRAP 2 — a directive glued straight to markup with no whitespace
 * (for example an else immediately followed by an opening tag). The
 * directive is not recognised and passes through as text.
 *
 * Both are caught by one check: after compiling, NO directive should
 * survive uncompiled in the output.
 */
class CbtBladeIntegrityTest extends TestCase
{
    /** @return array<int, string> */
    protected function assessmentHubViews(): array
    {
        return glob(resource_path('views/school/cbt/**/*.blade.php'), GLOB_BRACE)
            + glob(resource_path('views/school/cbt/*.blade.php'));
    }

    public function test_every_assessment_hub_view_compiles_to_valid_php(): void
    {
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $checked = 0;

        foreach ($this->assessmentHubViews() as $path) {
            $compiled = $compiler->compileString(file_get_contents($path));
            $name = str_replace(resource_path('views/'), '', $path);

            // Trap 1 and 2: a directive left uncompiled at the start of
            // a line means the compiler bailed out partway through.
            preg_match_all(
                '/^\s*@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|php|endphp|isset|endisset|include)\b/m',
                $compiled,
                $matches
            );
            $this->assertSame([], $matches[0],
                "{$name} has directives that did not compile: ".implode(', ', array_unique($matches[0] ?? [])));

            // And the result must actually be parseable PHP.
            $tmp = tempnam(sys_get_temp_dir(), 'blade').'.php';
            file_put_contents($tmp, $compiled);
            $output = []; // exec() APPENDS, so this must be reset per view
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $exit);
            @unlink($tmp);

            $this->assertSame(0, $exit, "{$name} compiles to invalid PHP: ".implode(' ', $output));
            $checked++;
        }

        $this->assertGreaterThan(10, $checked, 'expected to have checked the Assessment Hub views');
    }

    public function test_no_assessment_hub_view_glues_a_directive_to_markup(): void
    {
        foreach ($this->assessmentHubViews() as $path) {
            $source = file_get_contents($path);
            $name = str_replace(resource_path('views/'), '', $path);

            // "@else<span" and friends. Comments explaining the trap are
            // allowed, so anything on a line starting with // is skipped.
            $lines = preg_grep('#^\s*//#', explode("\n", $source), PREG_GREP_INVERT);
            $offenders = preg_grep('/@(else|endif|endforeach)</', $lines);

            $this->assertSame([], array_values($offenders ?: []),
                "{$name} glues a directive straight to markup, which silently fails to compile.");
        }
    }
}
