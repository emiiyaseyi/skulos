<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\SupportContactService;
use Illuminate\Http\Request;

/**
 * The support email/phone shown on the login page and in every
 * transactional email footer used to be config('skulos.support')/env only,
 * unchangeable without a code deploy. Same shape as MailSettingsController/
 * IdleTimeoutController: a platform wide default a super admin can edit.
 */
class SupportContactController extends Controller
{
    public function edit(SupportContactService $support): \Illuminate\View\View
    {
        return view('admin.support-contact', ['settings' => $support->effective()]);
    }

    public function update(Request $request, SupportContactService $support): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $support->saveSettings($data);

        return back()->with('status', 'Support contact details saved.');
    }
}
