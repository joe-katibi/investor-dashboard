<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The technician teams of the technician list: the in-house team and the contractors. Each is a
 * team of the same name in the system (the "Team" column of the list, e.g. "TeamSaro").
 */
enum TechnicianCompany: string implements HasLabel
{
    case Inhouse = 'TeamInhouse';
    case Arviant = 'TeamArviant';
    case Atomet = 'TeamAtomet';
    case Beasconect = 'TeamBeasconect';
    case Emmaki = 'TeamEmmaki';
    case Eritel = 'TeamEritel';
    case Gtech = 'TeamGtech';
    case Inivigor = 'TeamInivigor';
    case Jeydah = 'TeamJeydah';
    case Molecule = 'TeamMolecule';
    case Optix = 'TeamOptix';
    case Prolink = 'TeamProlink';
    case Saro = 'TeamSaro';
    case Taifa = 'TeamTaifa';
    case Trazent = 'TeamTrazent';

    public function getLabel(): string
    {
        return 'Team '.substr($this->value, 4);
    }

    public function engagement(): EngagementType
    {
        return $this === self::Inhouse ? EngagementType::Inhouse : EngagementType::Outsourced;
    }

    /** "Teammolecule", "Team Optix", "teamoptix" … → the case. */
    public static function fromList(string $value): ?self
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', $value));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $key) {
                return $case;
            }
        }

        return null;
    }
}
