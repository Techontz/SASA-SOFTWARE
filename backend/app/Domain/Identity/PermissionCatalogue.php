<?php

namespace App\Domain\Identity;

/**
 * The permission surface, in one place.
 *
 * BLUEPRINT NOTE (§20): the source document names the officer functions but
 * does not define a permission model. This is our proposal and is intended to
 * be confirmed — which is why roles and their permission sets are data, not
 * code, and can be edited per organisation without a release.
 */
final class PermissionCatalogue
{
    /** @return array<int,array{key:string,group:string,name:string,description:string,is_sensitive:bool}> */
    public static function permissions(): array
    {
        $p = fn (string $key, string $group, string $name, string $description, bool $sensitive = false) => compact('key', 'group', 'name', 'description') + ['is_sensitive' => $sensitive];

        return [
            // --- Register ---------------------------------------------------
            $p('stakeholder.view', 'Stakeholders', 'See the stakeholder register', 'Open the register and view stakeholder records.'),
            $p('stakeholder.create', 'Stakeholders', 'Add stakeholders', 'Create new register entries.'),
            $p('stakeholder.update', 'Stakeholders', 'Edit stakeholders', 'Change details on an existing register entry.'),
            $p('stakeholder.archive', 'Stakeholders', 'Archive stakeholders', 'Remove a record from active use. Nothing is ever deleted.'),
            $p('stakeholder.override_priority', 'Stakeholders', 'Override calculated priority', 'Set a priority different from the calculated one, with a reason.'),
            $p('stakeholder.merge', 'Stakeholders', 'Merge duplicates', 'Merge one register entry into another.'),
            $p('stakeholder.export', 'Stakeholders', 'Export the register', 'Download register data.'),

            // --- Engagement -------------------------------------------------
            $p('engagement.view', 'Engagement', 'See engagement plans and logs', 'Open the engagement calendar and logged engagements.'),
            $p('engagement.plan', 'Engagement', 'Plan engagements', 'Create and change engagement plans.'),
            $p('engagement.log', 'Engagement', 'Log engagements', 'Record what actually happened, with attendance and minutes.'),
            $p('engagement.archive', 'Engagement', 'Archive engagements', 'Remove an engagement record from active use.'),

            // --- Concerns and commitments -----------------------------------
            $p('concern.view', 'Concerns', 'See concerns', 'View concerns raised in engagements.'),
            $p('concern.manage', 'Concerns', 'Record and respond to concerns', 'Add concerns and record how they were addressed.'),
            $p('concern.escalate', 'Concerns', 'Escalate a concern to a grievance', 'Turn a concern into a formal grievance case.'),
            $p('commitment.view', 'Commitments', 'See the commitments register', 'View commitments and their status.'),
            $p('commitment.manage', 'Commitments', 'Record and update commitments', 'Add commitments, set owners and due dates, and update status.'),
            $p('commitment.verify', 'Commitments', 'Verify completed commitments', 'Confirm that a commitment was actually delivered.'),

            // --- Grievances --------------------------------------------------
            $p('grievance.view', 'Grievances', 'See grievance cases', 'Open the case list and view non-restricted cases.'),
            $p('grievance.create', 'Grievances', 'Log a grievance', 'Record a new case through any channel.'),
            $p('grievance.update', 'Grievances', 'Edit case details', 'Change the details of an open case.'),
            $p('grievance.classify', 'Grievances', 'Classify and set severity', 'Confirm the category, subcategory and severity of a case.'),
            $p('grievance.assign', 'Grievances', 'Assign cases', 'Give a case an owner, or move it to someone else.'),
            $p('grievance.acknowledge', 'Grievances', 'Acknowledge to the complainant', 'Record that the complainant has been told their case is open.'),
            $p('grievance.investigate', 'Grievances', 'Record investigation', 'Add investigation steps, findings and corrective actions.'),
            $p('grievance.resolve', 'Grievances', 'Resolve cases', 'Record how a case was resolved.'),
            $p('grievance.close', 'Grievances', 'Close cases', 'Close a case after the complainant has been contacted.'),
            $p('grievance.reopen', 'Grievances', 'Reopen cases', 'Start a new resolution cycle when a complainant is not satisfied.'),
            $p('grievance.escalate', 'Grievances', 'Escalate cases', 'Raise a case to the next level.'),
            $p('grievance.view_confidential', 'Grievances', 'See complainant identity on confidential cases', 'Receive the complainant name, contact and precise location on cases marked confidential. Every such view is audited.', true),
            $p('grievance.view_restricted', 'Grievances', 'See restricted categories', 'See cases in restricted categories such as SEA/SH, retaliation and ethics, regardless of handling group.', true),
            $p('grievance.export', 'Grievances', 'Export cases', 'Download case data. Identity is stripped unless you can see confidential cases.'),

            // --- Reporting ----------------------------------------------------
            $p('dashboard.view', 'Reporting', 'See dashboards', 'Open the dashboards for this project.'),
            $p('dashboard.view_disaggregation', 'Reporting', 'See disaggregation', 'Open the disaggregation dashboard and its cross-tabs.'),
            $p('report.generate', 'Reporting', 'Generate reports', 'Run a report definition and produce a file.'),
            $p('report.manage_definitions', 'Reporting', 'Manage report definitions', 'Create, edit and schedule report definitions.'),

            // --- Administration ------------------------------------------------
            $p('project.view', 'Administration', 'See project settings', 'View the project record and its configuration.'),
            $p('project.manage', 'Administration', 'Manage the project', 'Change project details and lifecycle.'),
            $p('configuration.view', 'Administration', 'See configuration', 'View categories, severities, SLA standards and other settings.'),
            $p('configuration.manage', 'Administration', 'Change configuration', 'Edit categories, severity, SLA standards, calendars, priority weights and notification rules.', true),
            $p('user.view', 'Administration', 'See project members', 'View who is on this project and what role they hold.'),
            $p('user.manage', 'Administration', 'Manage project members', 'Invite people, change roles and remove access.', true),
            $p('location.manage', 'Administration', 'Manage locations', 'Edit the region, district, ward and village hierarchy.'),
            $p('import.run', 'Administration', 'Import data', 'Upload and commit spreadsheets of records.'),
            $p('audit.view', 'Administration', 'See the audit trail', 'Search who did what, and when.', true),
            $p('audit.view_sensitive', 'Administration', 'See sensitive-view audit events', 'See who opened confidential cases.', true),
            $p('sync.resolve_conflicts', 'Administration', 'Resolve sync conflicts', 'Decide which version of a record is correct after an offline edit clashed.'),
            $p('ai.review', 'AI', 'Review AI suggestions', 'Accept, change or reject a classification the AI proposed.'),
            $p('ai.configure', 'AI', 'Configure the AI voice agent', 'Change consent wording, call length and languages.', true),
        ];
    }

    /**
     * Roles from the blueprint, with a proposed permission set each.
     *
     * @return array<int,array{key:string,name:string,description:string,escalation_rank:int,permissions:array<int,string>|string,handling_groups?:array<int,string>}>
     */
    public static function roles(): array
    {
        $all = array_column(self::permissions(), 'key');

        $view = ['stakeholder.view', 'engagement.view', 'concern.view', 'commitment.view', 'grievance.view', 'dashboard.view', 'project.view', 'user.view', 'configuration.view'];

        return [
            [
                'key' => 'system_administrator',
                'name' => 'System administrator',
                'description' => 'Runs the platform across organisations. Has every permission everywhere.',
                'escalation_rank' => 1,
                'permissions' => $all,
                'handling_groups' => ['*'],
            ],
            [
                'key' => 'project_admin',
                'name' => 'Project administrator',
                'description' => 'Runs one project: its configuration, its people and its data.',
                'escalation_rank' => 10,
                'permissions' => array_values(array_diff($all, ['grievance.view_restricted'])),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'management',
                'name' => 'Management / executive',
                'description' => 'Consumes the reporting and makes the decisions cases escalate to.',
                'escalation_rank' => 5,
                'permissions' => array_merge($view, [
                    'dashboard.view_disaggregation', 'report.generate', 'grievance.escalate',
                    'stakeholder.export', 'grievance.export', 'audit.view',
                ]),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'project_management',
                'name' => 'Project management',
                'description' => 'Owns delivery: decides on escalations, commitments and corrective actions.',
                'escalation_rank' => 8,
                'permissions' => array_merge($view, [
                    'stakeholder.create', 'stakeholder.update', 'engagement.plan', 'engagement.log',
                    'concern.manage', 'concern.escalate', 'commitment.manage', 'commitment.verify',
                    'grievance.assign', 'grievance.escalate', 'grievance.resolve', 'grievance.close',
                    'dashboard.view_disaggregation', 'report.generate', 'stakeholder.export', 'grievance.export',
                ]),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'grievance_officer',
                'name' => 'Grievance officer',
                'description' => 'Owns the case load end to end: classification, investigation, resolution and closure.',
                'escalation_rank' => 20,
                'permissions' => array_merge($view, [
                    'stakeholder.create', 'stakeholder.update',
                    'concern.manage', 'concern.escalate', 'commitment.manage',
                    'grievance.create', 'grievance.update', 'grievance.classify', 'grievance.assign',
                    'grievance.acknowledge', 'grievance.investigate', 'grievance.resolve', 'grievance.close',
                    'grievance.reopen', 'grievance.escalate', 'grievance.view_confidential', 'grievance.export',
                    'report.generate', 'ai.review', 'sync.resolve_conflicts',
                ]),
                'handling_groups' => ['general', 'restricted_handling'],
            ],
            [
                'key' => 'community_relations_officer',
                'name' => 'Community relations officer',
                'description' => 'Owns the register and the engagement programme; raises concerns and commitments.',
                'escalation_rank' => 30,
                'permissions' => array_merge($view, [
                    'stakeholder.create', 'stakeholder.update', 'stakeholder.archive',
                    'stakeholder.override_priority', 'stakeholder.merge', 'stakeholder.export',
                    'engagement.plan', 'engagement.log', 'engagement.archive',
                    'concern.manage', 'concern.escalate', 'commitment.manage', 'commitment.verify',
                    'grievance.create', 'grievance.update', 'report.generate', 'import.run',
                ]),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'hr_officer',
                'name' => 'HR officer',
                'description' => 'Handles worker grievances: labour, pay, contracts and workplace conduct.',
                'escalation_rank' => 25,
                'permissions' => array_merge($view, [
                    'grievance.create', 'grievance.update', 'grievance.classify', 'grievance.acknowledge',
                    'grievance.investigate', 'grievance.resolve', 'grievance.escalate',
                    'grievance.view_confidential', 'commitment.manage', 'report.generate', 'ai.review',
                ]),
                'handling_groups' => ['general', 'restricted_handling'],
            ],
            [
                'key' => 'hse_officer',
                'name' => 'HSE officer',
                'description' => 'Handles environment, health and safety cases and their corrective actions.',
                'escalation_rank' => 25,
                'permissions' => array_merge($view, [
                    'grievance.create', 'grievance.update', 'grievance.classify', 'grievance.acknowledge',
                    'grievance.investigate', 'grievance.resolve', 'grievance.escalate',
                    'commitment.manage', 'commitment.verify', 'report.generate', 'ai.review',
                ]),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'security_officer',
                'name' => 'Security officer',
                'description' => 'Handles security-related cases and incidents involving project security.',
                'escalation_rank' => 25,
                'permissions' => array_merge($view, [
                    'grievance.create', 'grievance.update', 'grievance.classify',
                    'grievance.investigate', 'grievance.escalate', 'report.generate',
                ]),
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'field_officer',
                'name' => 'Field officer',
                'description' => 'Works offline in the field: records stakeholders, engagements, concerns and new cases.',
                'escalation_rank' => 40,
                'permissions' => [
                    'stakeholder.view', 'stakeholder.create', 'stakeholder.update',
                    'engagement.view', 'engagement.log',
                    'concern.view', 'concern.manage',
                    'commitment.view', 'commitment.manage',
                    'grievance.view', 'grievance.create',
                    'dashboard.view', 'project.view',
                ],
                'handling_groups' => ['general'],
            ],
            [
                'key' => 'auditor',
                'name' => 'Auditor / read-only',
                'description' => 'Verifies what happened. Can read everything on the project and change nothing.',
                'escalation_rank' => 60,
                'permissions' => array_merge($view, [
                    'dashboard.view_disaggregation', 'audit.view', 'report.generate',
                    'stakeholder.export', 'grievance.export',
                ]),
                'handling_groups' => [],
            ],
        ];
    }
}
