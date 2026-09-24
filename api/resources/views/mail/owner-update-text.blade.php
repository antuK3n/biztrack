{{-- Plain-text part, so unescaped: {{ }} would print an officer's "&" as "&amp;". --}}
@if ($recipientName)
Hi {!! $recipientName !!},

@endif
{!! $title !!}
@if ($businessName)
Business: {!! $businessName !!}
@endif
@if ($reference)
Reference: {!! $reference !!}
@endif

{!! $body !!}

Open BizTrack to see the details:
{!! $url !!}

--
City of Malabon Business Permits and Licensing Office, through BizTrack.
This is an automatic message. Replies to this address are not read; use Messages in BizTrack to reach the office.
