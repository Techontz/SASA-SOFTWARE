<?php

namespace App\Domain\Dashboard;

/**
 * Every number on every dashboard has ONE definition, stated here, so two
 * reports never disagree. The definition is shown on hover in the UI and
 * reproduced in every export footer.
 */
final class MetricDefinitions
{
    /** @return array<string,array{label:string,definition:string,unit:string}> */
    public static function all(): array
    {
        return [
            'total_stakeholders' => [
                'label' => 'Total stakeholders',
                'definition' => 'Every stakeholder record on the project that has not been archived or merged, regardless of when it was created.',
                'unit' => 'count',
            ],
            'active_stakeholders' => [
                'label' => 'Active stakeholders',
                'definition' => 'Stakeholders with register status "active" — excludes inactive, merged and archived records.',
                'unit' => 'count',
            ],
            'high_priority_stakeholders' => [
                'label' => 'High-priority stakeholders',
                'definition' => 'Active stakeholders whose stored priority is High, whether calculated or overridden.',
                'unit' => 'count',
            ],
            'stakeholders_due_review' => [
                'label' => 'Register entries due for review',
                'definition' => 'Active stakeholders whose review date is today or earlier.',
                'unit' => 'count',
            ],
            'engagements_planned' => [
                'label' => 'Planned engagements',
                'definition' => 'Engagement plans whose target date falls inside the reporting period, in any status.',
                'unit' => 'count',
            ],
            'engagements_completed' => [
                'label' => 'Completed engagements',
                'definition' => 'Engagements logged with a held date inside the reporting period.',
                'unit' => 'count',
            ],
            'engagement_completion_rate' => [
                'label' => 'Engagement completion rate',
                'definition' => 'Plans in the period that were closed by a logged engagement, divided by all plans targeted in the period. Cancelled plans are excluded from both sides.',
                'unit' => 'percent',
            ],
            'engagements_on_plan' => [
                'label' => 'On-plan engagements',
                'definition' => 'Logged engagements held inside their planned window, allowing a two-day grace either side.',
                'unit' => 'count',
            ],
            'engagements_late' => [
                'label' => 'Late engagements',
                'definition' => 'Logged engagements held after the end of their planned window plus the grace period.',
                'unit' => 'count',
            ],
            'engagements_unplanned' => [
                'label' => 'Unplanned engagements',
                'definition' => 'Logged engagements not linked to any engagement plan.',
                'unit' => 'count',
            ],
            'engagements_missed' => [
                'label' => 'Missed engagements',
                'definition' => 'Engagement plans whose window has passed with nothing logged against them and which were not cancelled or postponed.',
                'unit' => 'count',
            ],
            'total_attendance' => [
                'label' => 'People reached',
                'definition' => 'Sum of recorded attendance across engagements held in the period. A person attending two meetings counts twice.',
                'unit' => 'count',
            ],
            'grievances_received' => [
                'label' => 'Grievances received',
                'definition' => 'Cases whose received date falls inside the reporting period, across every channel.',
                'unit' => 'count',
            ],
            'grievances_open' => [
                'label' => 'Open grievances',
                'definition' => 'Cases not currently closed, rejected or withdrawn — measured now, not within the period.',
                'unit' => 'count',
            ],
            'grievances_closed' => [
                'label' => 'Closed grievances',
                'definition' => 'Cases closed inside the reporting period. A case reopened and closed again in the same period counts once.',
                'unit' => 'count',
            ],
            'resolution_rate' => [
                'label' => 'Resolution rate',
                'definition' => 'Cases received in the period that are now closed, divided by all cases received in the period.',
                'unit' => 'percent',
            ],
            'average_resolution_days' => [
                'label' => 'Average time to resolve',
                'definition' => 'Mean calendar days from received to resolved, over cases resolved in the period. Reopened cases measure to their most recent resolution.',
                'unit' => 'days',
            ],
            'average_acknowledgement_hours' => [
                'label' => 'Average time to acknowledge',
                'definition' => 'Mean calendar hours from received to acknowledged, over cases acknowledged in the period. Cases where acknowledgement was not possible are excluded and reported separately.',
                'unit' => 'hours',
            ],
            'sla_compliance' => [
                'label' => 'SLA compliance',
                'definition' => 'Finished clocks that were met on time, divided by all finished clocks in the period. Paused time is excluded from elapsed time.',
                'unit' => 'percent',
            ],
            'sla_breaches' => [
                'label' => 'SLA breaches',
                'definition' => 'Clocks currently in breach, plus clocks completed after their due instant during the period.',
                'unit' => 'count',
            ],
            'open_high_severity' => [
                'label' => 'Open Level 4–5 cases',
                'definition' => 'Open cases assessed at severity 4 or 5 by a person or awaiting confirmation of an AI proposal at that level.',
                'unit' => 'count',
            ],
            'escalations' => [
                'label' => 'Escalations',
                'definition' => 'Escalation events raised inside the period, whether triggered by SLA breach, severity or a person.',
                'unit' => 'count',
            ],
            'commitments_open' => [
                'label' => 'Open commitments',
                'definition' => 'Commitments in status open, in progress or overdue.',
                'unit' => 'count',
            ],
            'commitments_overdue' => [
                'label' => 'Overdue commitments',
                'definition' => 'Open commitments whose due date has passed.',
                'unit' => 'count',
            ],
            'commitments_fulfilled' => [
                'label' => 'Fulfilled commitments',
                'definition' => 'Commitments marked fulfilled with a completion date inside the period.',
                'unit' => 'count',
            ],
            'commitments_unverified' => [
                'label' => 'Unverified commitments',
                'definition' => 'Commitments marked fulfilled whose completion has not yet been verified by a second person.',
                'unit' => 'count',
            ],
            'commitments_high_risk' => [
                'label' => 'High-risk commitments',
                'definition' => 'Open commitments recorded at high risk.',
                'unit' => 'count',
            ],
            'concern_to_grievance_rate' => [
                'label' => 'Concern-to-grievance conversion',
                'definition' => 'Concerns raised in the period that were escalated into a formal grievance, divided by all concerns raised in the period.',
                'unit' => 'percent',
            ],
            'data_completeness' => [
                'label' => 'Register data completeness',
                'definition' => 'Mean percentage of the core register fields (type, location, contact, priority inputs, consent) that are populated across active stakeholders.',
                'unit' => 'percent',
            ],
            'uptake_equity' => [
                'label' => 'Uptake equity',
                'definition' => 'Each vulnerable group\'s share of grievances and engagements, compared with that group\'s estimated share of the project-affected population from the register.',
                'unit' => 'ratio',
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function definition(string $key): string
    {
        return self::all()[$key]['definition'] ?? '';
    }
}
