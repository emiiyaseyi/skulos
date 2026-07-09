@include('errors.minimal', [
  'code' => 500,
  'title' => 'Something went wrong on our end',
  'message' => 'We hit an unexpected error. Please try again in a moment; if this keeps happening, let us know.',
  'actionLabel' => 'Go back',
])
