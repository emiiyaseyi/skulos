<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Symlinks the static marketing pages into public/ so Apache serves
 * them directly (2026-08-15 performance fix).
 *
 * public/.htaccess only forwards a request to index.php when the file
 * does NOT exist on disk, so once these links are in place the
 * marketing pages never boot Laravel at all. Before this, every visit
 * to the homepage started the whole framework — session, middleware,
 * service providers, and a real database query from
 * MailSettingsService on every single web request — purely to read a
 * static HTML file. Measured live: the homepage averaged 1.4s and
 * peaked at 8.3s, which is what uptime monitors and Lighthouse were
 * reporting as 504s and a 93% availability score.
 *
 * Same shape as Laravel's own storage:link, and safe to re-run: an
 * existing link is left alone, and nothing is deleted.
 */
class LinkMarketingSite extends Command
{
    protected $signature = 'skulos:link-marketing {--force : Replace links that already exist}';

    protected $description = 'Symlink the static marketing pages into public/ so they are served without booting the app';

    /** Assets are linked separately as a directory; sitemap/robots must be real files for crawlers. */
    protected const FILES = [
        'index.html', 'about.html', 'pricing.html', 'contact.html',
        'faq.html', 'trial.html', 'privacy.html', 'terms.html',
        'sitemap.xml', 'robots.txt',
    ];

    public function handle(): int
    {
        $siteDir = realpath(base_path('../site'));

        if (! $siteDir) {
            $this->error('No ../site directory found next to the backend. Nothing to link.');

            return self::FAILURE;
        }

        $linked = 0;
        foreach (array_merge(self::FILES, ['assets']) as $name) {
            $target = $siteDir.DIRECTORY_SEPARATOR.$name;
            $link = public_path($name);

            if (! file_exists($target)) {
                $this->warn("skipped {$name} (not present in site/)");

                continue;
            }

            if (file_exists($link) || is_link($link)) {
                if (! $this->option('force')) {
                    $this->line("exists  {$name}");

                    continue;
                }
                is_dir($link) && ! is_link($link) ? null : @unlink($link);
            }

            if (@symlink($target, $link)) {
                $this->info("linked  {$name}");
                $linked++;
            } else {
                // Some shared hosts disable symlink(); copying still gets
                // the file served statically, it just has to be re-run
                // after the marketing pages change.
                if (is_dir($target)) {
                    $this->warn("could not symlink {$name} (a directory) — link it manually or enable symlink()");

                    continue;
                }
                copy($target, $link)
                    ? $this->info("copied  {$name} (symlink unavailable on this host)")
                    : $this->error("failed  {$name}");
                $linked++;
            }
        }

        $this->newLine();
        $this->info("{$linked} marketing file(s) now served statically.");
        $this->line('Re-run this after changing anything under site/ if the host copied instead of symlinked.');

        return self::SUCCESS;
    }
}
