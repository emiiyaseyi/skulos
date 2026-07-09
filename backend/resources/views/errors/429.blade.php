@include('errors.minimal', [
  'code' => 429,
  'title' => 'Too many attempts',
  'message' => 'You have tried this too many times in a short period. Please wait a moment and try again.',
  'actionLabel' => 'Go back',
])
