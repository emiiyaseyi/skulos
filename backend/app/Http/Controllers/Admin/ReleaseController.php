<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Services\Licensing\ReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Self-hosted licensing: release management (2026-08-07). A platform
 * admin builds a real, checksummed code release from this platform's own
 * working copy, then explicitly publishes it — only a published release
 * is ever offered through the license-gated download endpoint
 * (ReleaseDownloadController) a self-hosted school's own install calls.
 */
class ReleaseController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.releases', [
            'releases' => Release::orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request, ReleaseService $releases): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'unique:releases,version'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $releases->build($data['version'], $data['notes'] ?? null, Auth::user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', 'Could not build the release: '.$e->getMessage());
        }

        return back()->with('status', "Release {$data['version']} built. Review it, then publish when ready.");
    }

    public function publish(Release $release, ReleaseService $releases): \Illuminate\Http\RedirectResponse
    {
        $releases->publish($release);

        return back()->with('status', "Release {$release->version} published — self-hosted schools will see it on their next check-in.");
    }

    public function unpublish(Release $release, ReleaseService $releases): \Illuminate\Http\RedirectResponse
    {
        $releases->unpublish($release);

        return back()->with('status', "Release {$release->version} unpublished.");
    }

    public function destroy(Release $release, ReleaseService $releases): \Illuminate\Http\RedirectResponse
    {
        $releases->delete($release);

        return back()->with('status', "Release {$release->version} deleted.");
    }

    /** The admin's own direct download, for manually handing a release to a school — session-authenticated, not license-key-authenticated (see ReleaseDownloadController for that). */
    public function download(Release $release): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($release->exists(), 404);

        return Storage::disk('local')->download($release->file_path, basename($release->file_path));
    }
}
