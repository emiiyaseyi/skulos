{{--
  A one-time link that did not pass its signature check (2026-08-16).
  Shown instead of a bare 403 so the person holding the link knows
  whether to simply try again or to ask for a new one -- the two causes
  are "it expired" and "it was altered", and both are recoverable.
--}}
@include('errors.minimal', [
  'code' => 403,
  'title' => 'This link is no longer valid',
  'message' => 'One time links expire after a few minutes, and stop working if any part of the address is changed. Go back and start the action again to get a fresh link.',
  'actionLabel' => 'Go to sign in',
])
