<?php

namespace App\Domain\Configuration;

use App\Models\Project;

/**
 * Which fields a staff form must have filled in before it saves.
 *
 * The lists live in config/sasa.php under `required_fields` and a project can
 * override them through the configuration registry. The same lists reach the
 * browser with the device bootstrap, so the form and the API ask for the
 * same things.
 */
final class RequiredFields
{
    public const FORMS = ['grievance', 'commitment', 'engagement', 'stakeholder', 'eshs_anomaly', 'incident'];

    public function __construct(private readonly ConfigurationRegistry $configuration) {}

    /** @return array<string, list<string>> */
    public function all(Project $project): array
    {
        $configured = $this->configuration->get(ConfigurationKeys::REQUIRED_FIELDS, $project->organisation_id, $project->id, []);
        $configured = is_array($configured) ? $configured : [];

        $lists = [];
        foreach (self::FORMS as $form) {
            $fields = $configured[$form] ?? [];
            $lists[$form] = array_values(array_filter(is_array($fields) ? $fields : [], 'is_string'));
        }

        return $lists;
    }

    /** @return list<string> */
    public function for(Project $project, string $form): array
    {
        return $this->all($project)[$form] ?? [];
    }

    /**
     * Makes the form's required fields required in a rule set.
     *
     * $conditions replaces the plain `required` for a field — a conditional
     * rule, or false to leave the field alone (a question answered only in
     * the form, such as "already in the register?", where "no" is an answer).
     * When $partial, a field is only checked if it is sent, so an update that
     * changes one thing does not have to resend everything.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @param  array<string, mixed>  $conditions
     * @return array<string, array<int, mixed>>
     */
    public function apply(array $rules, Project $project, string $form, array $conditions = [], bool $partial = false): array
    {
        foreach ($this->for($project, $form) as $field) {
            $condition = array_key_exists($field, $conditions) ? $conditions[$field] : 'required';

            if ($condition === false) {
                continue;
            }

            // `nullable` stays: `required` still refuses an empty value, and a
            // conditional requirement that does not apply must still accept
            // one — "no e-mail" arrives as an empty e-mail.
            $existing = array_values(array_filter($rules[$field] ?? [], fn ($rule) => $rule !== 'sometimes'));

            $rules[$field] = array_merge($partial ? ['sometimes', $condition] : [$condition], $existing);
        }

        return $rules;
    }

    /**
     * One plain message for every required field on the form, so a person is
     * never shown "The complainant phone field is required." A form adds its
     * own wording on top where it can say something better.
     *
     * @return array<string, string>
     */
    public function messages(Project $project, string $form): array
    {
        $messages = [];
        foreach ($this->for($project, $form) as $field) {
            $messages[$field.'.required'] = 'This is needed before the record can be saved.';
        }

        return $messages;
    }
}
