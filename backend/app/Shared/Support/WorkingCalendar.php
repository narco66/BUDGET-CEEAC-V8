<?php

namespace App\Shared\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Échéances des circuits : délai en jours calendaires, reporté au premier
 * jour non férié lorsque la date tombe sur un jour férié du calendrier
 * institutionnel (Administration > Paramétrage > Calendrier).
 */
class WorkingCalendar
{
    public static function dueIn(int $days): string
    {
        return self::nextWorkingDate(CarbonImmutable::today()->addDays($days))->toDateString();
    }

    public static function nextWorkingDate(CarbonImmutable $date): CarbonImmutable
    {
        $holidays = DB::table('holidays')
            ->whereBetween('holiday_on', [$date->toDateString(), $date->addDays(31)->toDateString()])
            ->pluck('holiday_on')
            ->map(fn ($day) => substr((string) $day, 0, 10))
            ->all();

        while (in_array($date->toDateString(), $holidays, true)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
