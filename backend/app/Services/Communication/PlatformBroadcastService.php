<?php

namespace App\Services\Communication;

use App\Models\PlatformBroadcast;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Collection;

/**
 * Fans a platform-owner broadcast out to every matched school's owner/admin
 * (messaging increment 3, 2026-07-15). The broadcast's content lives once
 * in the platform database (App\Models\PlatformBroadcast); delivery itself
 * is written into each matched school's own tenant database as a real
 * Announcement + Notification rows tagged with platform_broadcast_id, the
 * exact same sign-in pop-up + acknowledge mechanism school-level broadcasts
 * already use (see App\Http\Controllers\School\MessageController), so no
 * second pop-up UI was needed. Email delivery is a separate, unrelated
 * concern still handled by BroadcastController's own Mail::to() loop
 * (unconditional, unlike the per-audience email toggle
 * NotificationService::dispatchAnnouncement() uses for school-authored
 * messages, since that toggle is a school's own setting and has nothing to
 * do with a platform-level message).
 */
class PlatformBroadcastService
{
    public function __construct(protected TenantManager $tenants)
    {
    }

    public function roleSlugsFor(string $group): array
    {
        return config("skulos.platform_message_audience_roles.{$group}", []);
    }

    /**
     * @param  Collection<int, School>  $schools
     * @return array{schools_reached: int, recipients_reached: int}
     */
    public function deliver(PlatformBroadcast $broadcast, Collection $schools): array
    {
        $roleSlugs = $this->roleSlugsFor($broadcast->recipient_group);
        $schoolsReached = 0;
        $recipientsReached = 0;

        foreach ($schools as $school) {
            if (! $school->isProvisioned() || empty($roleSlugs)) {
                continue;
            }

            $roleIds = Role::where('school_id', $school->id)->whereIn('slug', $roleSlugs)->pluck('id');
            if ($roleIds->isEmpty()) {
                continue;
            }

            $members = $school->users()
                ->wherePivotIn('role_id', $roleIds)
                ->wherePivot('status', 'active')
                ->get();

            if ($members->isEmpty()) {
                continue;
            }

            $this->tenants->connectTo($school, function () use ($broadcast, $members) {
                $announcement = Announcement::create([
                    'title' => $broadcast->title,
                    'body' => $broadcast->body,
                    'type' => Announcement::TYPE_BROADCAST,
                    'image_path' => $broadcast->image_path,
                    'audience' => 'staff',
                    'status' => Announcement::STATUS_PUBLISHED,
                    'published_at' => now(),
                    'created_by_user_id' => $broadcast->created_by_user_id,
                    'platform_broadcast_id' => $broadcast->id,
                ]);

                foreach ($members as $member) {
                    Notification::create([
                        'user_id' => $member->id,
                        'audience' => 'staff',
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                        'category' => 'broadcast',
                        'type' => Notification::typeFor('broadcast'),
                        'announcement_id' => $announcement->id,
                    ]);
                }
            });

            $schoolsReached++;
            $recipientsReached += $members->count();
        }

        $broadcast->forceFill([
            'schools_reached' => $schoolsReached,
            'recipients_reached' => $recipientsReached,
        ])->save();

        return ['schools_reached' => $schoolsReached, 'recipients_reached' => $recipientsReached];
    }

    /**
     * Aggregated read receipts across every school this broadcast reached,
     * for the sender's own view ("this is tracked to the sender to see").
     *
     * @param  Collection<int, School>  $schools
     * @return array{delivered: int, read: int}
     */
    public function stats(PlatformBroadcast $broadcast, Collection $schools): array
    {
        $delivered = 0;
        $read = 0;

        foreach ($schools as $school) {
            if (! $school->isProvisioned()) {
                continue;
            }

            [$d, $r] = $this->tenants->connectTo($school, function () use ($broadcast) {
                $announcement = Announcement::where('platform_broadcast_id', $broadcast->id)->first();
                if (! $announcement) {
                    return [0, 0];
                }

                return [
                    $announcement->notifications()->count(),
                    $announcement->notifications()->whereNotNull('read_at')->count(),
                ];
            });

            $delivered += $d;
            $read += $r;
        }

        return ['delivered' => $delivered, 'read' => $read];
    }
}
