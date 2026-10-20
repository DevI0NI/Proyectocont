<?php

function report_format_display_datetime(?string $value, bool $includeTime = true): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $value = trim($value);
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    if ($includeTime) {
        return date('d/m/Y | H:i', $timestamp);
    }

    return date('d/m/Y', $timestamp);
}

function report_format_row_dates(array $rows, array $dateKeys, bool $includeTime = true): array
{
    foreach ($rows as &$row) {
        foreach ($dateKeys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = report_format_display_datetime((string)$row[$key], $includeTime);
            }
        }
    }
    unset($row);

    return $rows;
}
