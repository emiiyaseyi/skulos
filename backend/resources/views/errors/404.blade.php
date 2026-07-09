@include('errors.minimal', [
  'code' => 404,
  'title' => 'Page not found',
  'message' => 'The page you are looking for does not exist, or you do not have access to it.',
  'actionLabel' => 'Go back',
])
