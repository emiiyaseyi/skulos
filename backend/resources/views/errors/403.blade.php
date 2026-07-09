@include('errors.minimal', [
  'code' => 403,
  'title' => 'Access not available',
  'message' => $exception->getMessage() ?: 'You do not have permission to view this page, or it is not currently available.',
  'actionLabel' => 'Go to sign in',
])
