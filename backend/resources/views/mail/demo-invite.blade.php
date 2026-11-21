{{-- Plain text, so nothing is HTML-escaped: an "&" in a company name stays an "&". --}}
Hello {!! $name !!},

Your SASA demo for {!! $organisation !!} is ready. It is filled with sample records, so you can try every part of SASA straight away.

Set your password here:
{!! $inviteUrl !!}

This link works for {!! $linkHours !!} hours. After that, use "Forgot password" on the sign-in page with {!! $email !!}.

Your demo is open until {!! $endsOn !!}.

The SASA team
