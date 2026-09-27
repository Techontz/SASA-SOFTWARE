<?php

namespace App\Domain\Notification;

/**
 * Every rule — trigger, recipient role, channel, timing and template text — is
 * configurable per project. These are the triggers the platform emits.
 */
final class NotificationEvents
{
    public const GRIEVANCE_CREATED = 'grievance.created';

    public const GRIEVANCE_HIGH_SEVERITY = 'grievance.high_severity';

    public const GRIEVANCE_ASSIGNED = 'grievance.assigned';

    public const GRIEVANCE_REASSIGNED = 'grievance.reassigned';

    public const GRIEVANCE_ACKNOWLEDGEMENT_DUE = 'grievance.acknowledgement_due';

    public const GRIEVANCE_SLA_AT_RISK = 'grievance.sla_at_risk';

    public const GRIEVANCE_SLA_BREACHED = 'grievance.sla_breached';

    public const GRIEVANCE_ESCALATED = 'grievance.escalated';

    public const GRIEVANCE_RESOLVED = 'grievance.resolved';

    public const GRIEVANCE_CLOSED = 'grievance.closed';

    public const GRIEVANCE_REOPENED = 'grievance.reopened';

    public const COMMITMENT_DUE_SOON = 'commitment.due_soon';

    public const COMMITMENT_OVERDUE = 'commitment.overdue';

    public const COMMITMENT_ESCALATED = 'commitment.escalated';

    public const STAKEHOLDER_REVIEW_DUE = 'stakeholder.review_due';

    public const ENGAGEMENT_PLAN_DUE = 'engagement_plan.due';

    public const ENGAGEMENT_PLAN_MISSED = 'engagement_plan.missed';

    public const SYNC_CONFLICT_RAISED = 'sync.conflict_raised';

    public const VOICE_CALL_NEEDS_REVIEW = 'voice.needs_review';

    /**
     * Defaults seeded per organisation. Every one of these is editable.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'event_key' => self::GRIEVANCE_CREATED,
                'name' => 'A new grievance is logged',
                'recipient_roles' => ['grievance_officer', 'project_admin'],
                'channels' => ['in_app', 'email'],
                'template_subject' => 'New grievance {reference}',
                'template_body' => 'A new grievance ({reference}) was logged via {channel} on {project}. It needs classification and an owner.',
            ],
            [
                'event_key' => self::GRIEVANCE_HIGH_SEVERITY,
                'name' => 'A Level 4 or 5 case is logged',
                'recipient_roles' => ['project_admin', 'management', 'grievance_officer', 'hse_officer'],
                'channels' => ['in_app', 'email'],
                'conditions' => ['severity_gte' => 4],
                'template_subject' => 'High-severity grievance {reference}',
                'template_body' => '{reference} has been assessed at {severity_label}. Management attention is required.',
            ],
            [
                'event_key' => self::GRIEVANCE_ASSIGNED,
                'name' => 'A case is assigned to someone',
                'recipient_roles' => [],
                'channels' => ['in_app', 'email'],
                'template_subject' => 'You have been assigned {reference}',
                'template_body' => '{reference} — {title} — is now yours. Acknowledgement is due by {acknowledgement_due}.',
            ],
            [
                'event_key' => self::GRIEVANCE_REASSIGNED,
                'name' => 'A case moves to a different owner',
                'recipient_roles' => ['grievance_officer'],
                'channels' => ['in_app'],
                'template_subject' => '{reference} reassigned',
                'template_body' => '{reference} has been reassigned to {assignee}.',
            ],
            [
                'event_key' => self::GRIEVANCE_SLA_AT_RISK,
                'name' => 'A case is approaching its SLA',
                'recipient_roles' => ['grievance_officer'],
                'channels' => ['in_app', 'email'],
                'template_subject' => '{reference} is approaching its {clock} deadline',
                'template_body' => '{percent}% of the {clock} window for {reference} has elapsed. It is due {due_at}.',
            ],
            [
                'event_key' => self::GRIEVANCE_SLA_BREACHED,
                'name' => 'A case has breached its SLA',
                'recipient_roles' => ['grievance_officer', 'project_admin', 'management'],
                'channels' => ['in_app', 'email'],
                'template_subject' => '{reference} has breached its {clock} deadline',
                'template_body' => 'The {clock} deadline for {reference} passed on {due_at}. This case is now escalated.',
            ],
            [
                'event_key' => self::GRIEVANCE_ESCALATED,
                'name' => 'A case is escalated',
                'recipient_roles' => ['project_admin', 'management'],
                'channels' => ['in_app', 'email'],
                'template_subject' => '{reference} escalated',
                'template_body' => '{reference} was escalated ({trigger}). {reason}',
            ],
            [
                'event_key' => self::GRIEVANCE_RESOLVED,
                'name' => 'A case is resolved',
                'recipient_roles' => ['grievance_officer', 'project_admin'],
                'channels' => ['in_app'],
                'template_subject' => '{reference} resolved',
                'template_body' => '{reference} has been resolved and is awaiting the complainant\'s confirmation.',
            ],
            [
                'event_key' => self::GRIEVANCE_CLOSED,
                'name' => 'A case is closed',
                'recipient_roles' => ['grievance_officer'],
                'channels' => ['in_app'],
                'template_subject' => '{reference} closed',
                'template_body' => '{reference} was closed on {closed_at}.',
            ],
            [
                'event_key' => self::GRIEVANCE_REOPENED,
                'name' => 'A case is reopened',
                'recipient_roles' => ['grievance_officer', 'project_admin', 'management'],
                'channels' => ['in_app', 'email'],
                'template_subject' => '{reference} reopened',
                'template_body' => 'The complainant did not accept the resolution of {reference}. Cycle {cycle} has started. Reason: {reason}',
            ],
            [
                'event_key' => self::COMMITMENT_DUE_SOON,
                'name' => 'A commitment is approaching its due date',
                'recipient_roles' => [],
                'channels' => ['in_app', 'email'],
                'template_subject' => 'Commitment {reference} is due {due_date}',
                'template_body' => '{commitment_text} — due {due_date}.',
            ],
            [
                'event_key' => self::COMMITMENT_OVERDUE,
                'name' => 'A commitment is overdue',
                'recipient_roles' => ['project_admin'],
                'channels' => ['in_app', 'email'],
                'template_subject' => 'Commitment {reference} is overdue',
                'template_body' => '{commitment_text} was due on {due_date} and is still open.',
            ],
            [
                'event_key' => self::COMMITMENT_ESCALATED,
                'name' => 'An overdue commitment is escalated',
                'recipient_roles' => ['project_admin', 'management'],
                'channels' => ['in_app', 'email'],
                'template_subject' => 'Commitment {reference} escalated',
                'template_body' => '{reference} is {days_overdue} days overdue and has been escalated.',
            ],
            [
                'event_key' => self::STAKEHOLDER_REVIEW_DUE,
                'name' => 'A stakeholder record is due for review',
                'recipient_roles' => ['community_relations_officer'],
                'channels' => ['in_app'],
                'template_subject' => '{reference} is due for review',
                'template_body' => 'The register entry for {name} was due for review on {review_date}.',
            ],
            [
                'event_key' => self::ENGAGEMENT_PLAN_DUE,
                'name' => 'A planned engagement is coming up',
                'recipient_roles' => [],
                'channels' => ['in_app'],
                'template_subject' => '{reference} is planned for {target_date}',
                'template_body' => '{title} is scheduled for {target_date} at {location}.',
            ],
            [
                'event_key' => self::ENGAGEMENT_PLAN_MISSED,
                'name' => 'A planned engagement was missed',
                'recipient_roles' => ['community_relations_officer', 'project_admin'],
                'channels' => ['in_app', 'email'],
                'template_subject' => '{reference} was missed',
                'template_body' => '{title} was planned for {target_date} and nothing has been logged against it.',
            ],
            [
                'event_key' => self::SYNC_CONFLICT_RAISED,
                'name' => 'A sync conflict needs a decision',
                'recipient_roles' => ['project_admin'],
                'channels' => ['in_app'],
                'template_subject' => 'Sync conflict on {entity_reference}',
                'template_body' => 'A record edited offline conflicts with a change made on the server. Someone needs to choose which version is correct.',
            ],
            [
                'event_key' => self::VOICE_CALL_NEEDS_REVIEW,
                'name' => 'A voice call needs human review',
                'recipient_roles' => ['grievance_officer'],
                'channels' => ['in_app'],
                'template_subject' => 'Voice call needs review',
                'template_body' => 'A call was cut short or could not be classified confidently and needs a person to listen to it.',
            ],
        ];
    }
}
