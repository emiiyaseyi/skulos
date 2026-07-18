<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Edit the static marketing site's HTML directly from the platform admin
 * portal (user request 2026-07-18: "link the platform owner end to the
 * marketing pages so its easier to make changes to any of the pages").
 * The marketing site (repo-root `/site`) is deliberately still hardcoded
 * HTML, not database-backed (CLAUDE.md: "a stopgap... explicitly
 * temporary until the admin-CMS-backed version replaces it") and is
 * deployed separately via GitHub Pages, not served by this Laravel app.
 * This editor writes straight to those files on whatever disk this app
 * is actually running on: in local development that is the real `/site`
 * folder the Pages workflow deploys from, so saving here and then
 * committing/pushing publishes it for real. On a production install
 * where the Laravel backend (cPanel) and the static site (GitHub Pages)
 * are genuinely two separate hosts, this app has no filesystem access to
 * the other host's copy, so editing there would need to happen through
 * git directly, not this screen, until the two are consolidated. Not
 * hidden from the admin: the edit screen states this plainly.
 *
 * Every filename is checked against an explicit allow-list, never taken
 * as a raw path, so this can never be tricked into reading or writing
 * outside the site directory.
 */
class MarketingPageController extends Controller
{
    public function siteDirectory(): string
    {
        return base_path('../site');
    }

    /** @return array<string, string> filename => display label */
    public function pages(): array
    {
        return [
            'index.html' => 'Home',
            'about.html' => 'About',
            'pricing.html' => 'Pricing',
            'contact.html' => 'Contact',
            'faq.html' => 'FAQ',
            'trial.html' => 'Start a Trial',
            'privacy.html' => 'Privacy Policy',
            'terms.html' => 'Terms of Service',
        ];
    }

    public function index(): \Illuminate\View\View
    {
        $dir = $this->siteDirectory();
        $pages = collect($this->pages())->map(function ($label, $file) use ($dir) {
            $path = $dir.'/'.$file;

            return [
                'file' => $file,
                'label' => $label,
                'exists' => File::exists($path),
                'modified' => File::exists($path) ? File::lastModified($path) : null,
                'size' => File::exists($path) ? File::size($path) : null,
            ];
        });

        return view('admin.marketing-pages', [
            'pages' => $pages,
            'siteReachable' => File::isDirectory($dir),
        ]);
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $file = $request->route('page');
        abort_unless(array_key_exists($file, $this->pages()), 404);

        $path = $this->siteDirectory().'/'.$file;
        abort_unless(File::exists($path), 404, 'This page was not found on disk. Check the site is reachable from this server.');

        return view('admin.marketing-page-edit', [
            'file' => $file,
            'label' => $this->pages()[$file],
            'content' => File::get($path),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $file = $request->route('page');
        abort_unless(array_key_exists($file, $this->pages()), 404);

        $data = $request->validate(['content' => ['required', 'string']]);

        $path = $this->siteDirectory().'/'.$file;
        abort_unless(File::isDirectory($this->siteDirectory()), 422, 'The marketing site folder is not reachable from this server.');

        File::put($path, $data['content']);

        return redirect()->route('admin.marketing-pages.edit', ['page' => $file])
            ->with('status', "{$this->pages()[$file]} saved. This only updates the file on this server; commit and push the site/ folder to actually publish it live.");
    }
}
