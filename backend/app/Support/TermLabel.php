<?php

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * A school's own word for "Term" (user directive 2026-09-21: "let's make
 * it possible for schools who don't want to use the word Term on their
 * report card or platform... this can be changed from school profile"),
 * e.g. "Trimester", "Semester". Defaults to "Term" for every school that
 * has never set this, so nothing changes anywhere until a school
 * deliberately opts in from School Profile.
 *
 * Deliberately scoped: this drives the word used when auto-naming NEW
 * terms going forward (AcademicDefaults::createSessionWithTerms(), so it
 * also covers the automatic End of Term rollover), the Sessions & Terms
 * page's own generic labels, and the report card's generic "Term" labels.
 * It does NOT rewrite an already-created Term's own .name (that field is
 * plain admin-editable text already, e.g. via the Sessions & Terms
 * screen), and it is not swept across every other module (Finance,
 * Attendance, Examinations, Timetable, ...) that also happens to use the
 * word "Term" as a generic filter/selector label -- a broader sweep is a
 * larger follow-up, not attempted here to keep this change's blast
 * radius contained to what was actually asked for.
 */
class TermLabel
{
    public static function current(): string
    {
        $saved = Setting::grab('term_label');

        return is_string($saved) && trim($saved) !== '' ? trim($saved) : 'Term';
    }

    public static function pluralCurrent(): string
    {
        return self::current().'s';
    }
}
