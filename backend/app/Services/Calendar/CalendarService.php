<?php

namespace App\Services\Calendar;

use App\Models\Tenant\CalendarEvent;

/**
 * School calendar helpers. seedDefaults() populates a new school's calendar
 * with the default notable days from config; it is idempotent so it never
 * duplicates them, and it never touches events a school added or edited.
 */
class CalendarService
{
    /**
     * Seed the default notable days for a freshly provisioned school. Safe
     * to call more than once. Returns how many were newly created.
     */
    public function seedDefaults(): int
    {
        $created = 0;

        foreach (config('skulos.default_calendar_events', []) as $event) {
            $exists = CalendarEvent::where('is_default', true)
                ->where('title', $event['title'])
                ->exists();

            if ($exists) {
                continue;
            }

            CalendarEvent::create([
                'title' => $event['title'],
                'category' => $event['category'] ?? 'general',
                'is_default' => true,
                'all_day' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
