<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Services\Billing\SubscriptionBankService;
use App\Services\Billing\SubscriptionService;
use App\Services\Media\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A school's own SKULOS subscription (user request 2026-07-17): due
 * date, real amount owed (active learners × plan price), a bank
 * transfer payment submission with evidence, mirroring the Foundation
 * Finance slice's parent-facing flow but for the school paying the
 * platform. Visible only to config('skulos.subscription_visible_role_slugs')
 * (Owner/Principal/VP/Head Teacher/School Admin), a direct role-slug
 * check rather than an RBAC permission, since this is not a toggleable
 * plan feature, every school on every plan has a subscription.
 */
class SubscriptionController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        $slug = Auth::user()?->roleIn($this->school($request))?->slug;
        abort_unless(in_array($slug, config('skulos.subscription_visible_role_slugs', []), true), 403, 'You do not have permission for this.');
    }

    public function index(Request $request, SubscriptionService $subs, SubscriptionBankService $bank): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        return view('school.subscription.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'learners' => $subs->activeLearnerCount($school),
            'amountDue' => $subs->amountDue($school),
            'daysUntilDue' => $subs->daysUntilDue($school),
            'bank' => $bank->effective(),
            'payments' => SubscriptionPayment::where('school_id', $school->id)->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request, ImageProcessingService $images, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('skulos.upload_limits.payment_evidence_kb')],
        ]);

        $file = $request->file('evidence');
        $evidencePath = $file->getClientOriginalExtension() === 'pdf'
            ? $file->storeAs('subscription-payments/'.$school->slug, Str::random(24).'.pdf', 'public')
            : $images->toWebp($file, 'subscription-payments/'.$school->slug);

        SubscriptionPayment::create([
            'school_id' => $school->id,
            'amount' => $data['amount'],
            'bank_name' => $data['bank_name'] ?? null,
            'reference' => $data['reference'] ?? null,
            'evidence_path' => $evidencePath,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'submitted_by_user_id' => Auth::id(),
        ]);

        return redirect()->route('school.subscription.index', ['school' => $school->slug])
            ->with('status', 'Payment submitted. SKULOS will verify it shortly.');
    }
}
