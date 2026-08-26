<?php

namespace Pbiaut\AiSeeder\Creator;

use Carbon\Carbon;
use DateTimeInterface;

class Dates
{
    /**
     * The planner works in the forum's own timezone (so "21:00" means a
     * plausible evening for the audience), but Flarum stores every timestamp in
     * UTC. Converting here keeps both true: an evening post stays an evening
     * post once rendered back in the reader's locale.
     */
    public static function toUtc(DateTimeInterface $date): Carbon
    {
        return Carbon::createFromInterface($date)->setTimezone('UTC');
    }
}
