{{-- Plain text, so nothing is HTML-escaped: an "&" in a company name stays an "&". --}}
{!! match ($enquiry->type) { 'demo' => 'Someone has asked for a demo of SASA.', 'quote' => 'Someone has asked for a quote for SASA.', default => 'Someone has registered interest in SASA.' } !!}

Reference: {!! $enquiry->reference !!}
Name: {!! $enquiry->full_name !!}
Organisation: {!! $enquiry->organization !!}
Sector: {!! ucfirst($enquiry->sector) !!}
E-mail: {!! $enquiry->email !!}
Phone: {!! $enquiry->phone ?? 'Not given' !!}
Modules: {!! $enquiry->modules ? implode(', ', array_map(fn ($m) => str_replace('_', ' ', $m), $enquiry->modules)) : 'Not given' !!}
@if ($enquiry->type === 'quote' && is_array($enquiry->details))
Company size: {!! $enquiry->details['company_size'] ?? 'Not given' !!} people
Projects on SASA: {!! $enquiry->details['projects_count'] ?? 'Not given' !!}
Expected users: {!! $enquiry->details['users_count'] ?? 'Not given' !!}
Project location: {!! $enquiry->details['project_location'] ?? 'Not given' !!}
Project stage: {!! isset($enquiry->details['project_stage']) ? ucfirst($enquiry->details['project_stage']) : 'Not given' !!}
Wants to start: {!! isset($enquiry->details['start_timeframe']) ? str_replace(['_', '-'], [' ', ' to '], $enquiry->details['start_timeframe']) : 'Not given' !!}
@endif

Message:
{!! $enquiry->message ?? 'None' !!}

Reply to this e-mail to answer {!! $enquiry->full_name !!} directly.
