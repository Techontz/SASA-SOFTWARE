<?php

namespace Database\Seeders;

use App\Domain\Demo\DemoCast;
use App\Models\User;

/** The showcase seed's people, one per part of the sample story (see DemoSeeder). */
final class ShowcaseCast
{
    public const EMAILS = [
        'admin' => 'admin@sasa.test',
        'project_admin' => 'project.admin@sasa.test',
        'executive' => 'executive@sasa.test',
        'pm' => 'pm@sasa.test',
        'grievance' => 'grievance@sasa.test',
        'cro' => 'cro@sasa.test',
        'hr' => 'hr@sasa.test',
        'hse' => 'hse@sasa.test',
        'security' => 'security@sasa.test',
        'field' => 'field@sasa.test',
        'field2' => 'field2@sasa.test',
    ];

    public static function load(): DemoCast
    {
        $byEmail = User::whereIn('email', array_values(self::EMAILS))->get()->keyBy('email');
        $users = [];

        foreach (self::EMAILS as $part => $email) {
            if ($byEmail->has($email)) {
                $users[$part] = $byEmail[$email];
            }
        }

        return DemoCast::of($users, User::query()->orderBy('id')->first());
    }
}
