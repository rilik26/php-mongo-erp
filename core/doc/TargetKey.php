<?php
/**
 * core/doc/TargetKey.php
 *
 * GENEL target_key standardı:
 * module|doc_type|doc_id|CDEF01_id|period_id|facility_id
 */

final class TargetKey
{
    public static function build(array $target, array $ctx): string
    {
        $module = (string)($target['module'] ?? 'null');
        $docType = (string)($target['doc_type'] ?? 'null');
        $docId = (string)($target['doc_id'] ?? 'null');

        $cdef = (string)($ctx['CDEF01_id'] ?? 'null');
        $period = (string)($ctx['period_id'] ?? 'null');
        $facility = (string)($ctx['facility_id'] ?? 'null');

        return $module . '|' . $docType . '|' . $docId . '|' . $cdef . '|' . $period . '|' . $facility;
    }
}
