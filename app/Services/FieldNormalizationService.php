<?php

namespace App\Services;

class FieldNormalizationService
{
    public function normalize(string $key, ?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['value' => null, 'issues' => []];
        }$value = $raw;
        $issues = [];
        if (in_array($key, ['contact_number', 'whatsapp_number'])) {
            $value = preg_replace('/[\s()\-]/', '', $raw);
            if (! preg_match('/^(0\d{9}|\+94\d{9})$/', $value)) {
                $issues[] = 'Expected a Sri Lankan phone number. No digits have been inferred.';
            }
        }
        if ($key === 'email_address' && ! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'Email syntax needs review.';
        }
        if ($key === 'nic_number' && ! preg_match('/^(\d{12}|\d{9}[vVxX])$/', $raw)) {
            $issues[] = 'NIC format needs review.';
        }
        if ($key === 'al_year' && ! preg_match('/^(19|20)\d{2}$/', $raw)) {
            $issues[] = 'Enter a four-digit A/L year.';
        }
        if ($key === 'inquiry_date') {
            $parsed = null;
            $dateText = preg_replace('/\s*([\/\.\-])\s*/', '$1', trim($raw));
            foreach (['!Y-m-d', '!Y/m/d', '!Y.m.d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!j M Y', '!j F Y'] as $format) {
                $d = \DateTimeImmutable::createFromFormat($format, $dateText);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($d && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    $parsed = $d->format('Y-m-d');
                    break;
                }
            }if ($parsed) {
                $value = $parsed;
            } else {
                $issues[] = 'Use an unambiguous calendar date, such as DD/MM/YYYY.';
            }
        }

        return ['value' => $issues ? null : $value, 'issues' => $issues];
    }

    public function forReview(string $key, ?string $raw): ?string
    {
        if ($key !== 'inquiry_date' || $raw === null || $raw === '') {
            return $raw;
        }

        $result = $this->normalize($key, $raw);

        if ($result['issues']) {
            return $raw;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $result['value'])->format('d/m/Y');
    }
}
