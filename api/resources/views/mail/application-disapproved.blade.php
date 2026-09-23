{{-- Plain text, so unescaped: {{ }} would print an officer's "&" as "&amp;". --}}
Your application {!! $application->tracking_id !!} was disapproved.

@if ($reason)
Reason given by the office:
{!! $reason !!}

@endif
You can message the office about it in BizTrack, or file a new application once the issue is settled.
