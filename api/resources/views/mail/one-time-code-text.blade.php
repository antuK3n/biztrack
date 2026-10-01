{{-- Plain-text part, so unescaped: {{ }} would print "&" as "&amp;". --}}
@if ($recipientName)
Hi {!! $recipientName !!},

@endif
{!! $heading !!}

{!! $lead !!}

    {!! $code !!}

This code works for {!! $minutes !!} minutes.

{!! $warning !!}

--
City of Malabon Business Permits and Licensing Office, through BizTrack.
This is an automatic message. Replies to this address are not read.
