<?php
/**
 * core/helpers/TimeHelper.php
 * TR saat formatı (Europe/Istanbul)
 */

final class TimeHelper
{
    public static function tz(): DateTimeZone
    {
        return new DateTimeZone('Europe/Istanbul');
    }

    /**
     * ISO (c) veya DateTime => "d.m.Y H:i:s"
     */
    public static function toTrString($dt): ?string
    {
        if ($dt === null || $dt === '') return null;

        try {
            if ($dt instanceof DateTimeInterface) {
                $d = new DateTime($dt->format('c'));
            } else {
                $d = new DateTime((string)$dt);
            }
            $d->setTimezone(self::tz());
            return $d->format('d.m.Y H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
