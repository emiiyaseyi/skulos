<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Notification Center (user directed backlog item, 2026-07-12, "To Do
 * List 2" doc item 8: categorize into Academic/Finance/Urgent/System, with
 * unread, pinned, and archived views, not one flat feed). Notifications
 * are personal, a user only ever sees and acts on their own, so nothing
 * here needs an RBAC permission check beyond being signed in, the
 * `forUser(Auth::id())` scoping on every query is the access control.
 */
class NotificationController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $view = in_array($request->query('view'), ['pinned', 'archived'], true) ? $request->query('view') : 'inbox';
        $type = $request->query('type');
        $search = trim((string) $request->query('q'));

        $query = Notification::forUser(Auth::id())->ofType($type);

        $query = match ($view) {
            'pinned' => $query->pinned()->notArchived(),
            'archived' => $query->archived(),
            default => $query->notArchived(),
        };

        if ($search) {
            $query->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%");
            });
        }

        $notifications = $query->orderByRaw('pinned_at is null')->latest()->paginate(20)->withQueryString();

        return view('school.notifications.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'notifications' => $notifications,
            'view' => $view,
            'type' => $type,
            'search' => $search,
            'types' => config('skulos.notification_types'),
            'unread' => Notification::forUser(Auth::id())->unread()->notArchived()->count(),
        ]);
    }

    /** Bulk mark read, pin, unpin, archive, or unarchive ticked notifications, scoped to the signed in user's own. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'notification_ids' => ['required', 'array', 'min:1'],
            'notification_ids.*' => ['integer'],
            'action' => ['required', Rule::in(['read', 'pin', 'unpin', 'archive', 'unarchive'])],
        ]);

        $notifications = Notification::forUser(Auth::id())->whereIn('id', $data['notification_ids'])->get();

        foreach ($notifications as $notification) {
            match ($data['action']) {
                'read' => $notification->markRead(),
                'pin' => $notification->pinned_at ?: $notification->togglePin(),
                'unpin' => $notification->pinned_at ? $notification->togglePin() : null,
                'archive' => $notification->archive(),
                'unarchive' => $notification->unarchive(),
            };
        }

        return back()->with('status', count($notifications).' notification(s) updated.');
    }
}
