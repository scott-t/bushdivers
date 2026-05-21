<?php

namespace App\Services\AirportSync;

use App\Models\Enums\AirportRunwaySurface;
use Illuminate\Support\Collection;
use League\Csv\Reader;

class LnmCsvParser
{
    public function parse(string $filePath): Collection
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setDelimiter($this->detectDelimiter($filePath));
        $csv->setHeaderOffset(0);

        return collect($csv->getRecords())
            ->map(fn (array $row) => $this->normaliseRow($row))
            ->filter()
            ->values();
    }

    private function detectDelimiter(string $filePath): string
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("CSV file not found: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new \RuntimeException("CSV file is not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if (! $handle) {
            throw new \RuntimeException("Unable to open CSV file: {$filePath}");
        }

        $line = (string) fgets($handle);

        fclose($handle);

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function normaliseRow(array $row): ?array
    {
        $identifier = strtoupper(trim((string) ($row['ident'] ?? '')));

        if ($identifier === '') {
            return null;
        }

        $country = trim((string) ($row['country'] ?? ''));

        return [
            'identifier' => $identifier,
            'name' => $this->nullableTrim($row['name'] ?? null),
            'location' => $this->nullableTrim($row['city'] ?? null),
            'country' => $country !== '' ? $country : null,
            'country_code' => $this->normaliseCountryCode($row['country_code'] ?? null, $country),
            'lat' => $this->toFloat($row['laty'] ?? null),
            'lon' => $this->toFloat($row['lonx'] ?? null),
            'altitude' => $this->toInt($row['altitude'] ?? null),
            'magnetic_variance' => $this->toFloat($row['mag_var'] ?? null),
            'longest_runway_length' => $this->toInt($row['longest_runway_length'] ?? null),
            'longest_runway_surface' => $this->normaliseRunwaySurface($row['longest_runway_surface'] ?? null),
            'has_avgas' => $this->toBool($row['has_avgas'] ?? null),
            'has_jetfuel' => $this->toBool($row['has_jetfuel'] ?? null),
            'size' => $this->toInt($row['rating'] ?? null),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function toInt(mixed $value): ?int
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (int) round((float) $trimmed);
    }

    private function toFloat(mixed $value): ?float
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }

    private function toBool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function normaliseCountryCode(mixed $countryCode, string $country): ?string
    {
        $rawCode = strtoupper(trim((string) $countryCode));

        if ($rawCode !== '') {
            return substr($rawCode, 0, 2);
        }

        // Best-effort fallback when LNM export does not include country_code:
        // strip non-letters from country name and take the first 2 letters.
        // This keeps the import resilient but does not guarantee ISO accuracy.
        $letters = preg_replace('/[^A-Za-z]/', '', strtoupper($country));

        if (! $letters) {
            return null;
        }

        return substr($letters, 0, 2);
    }

    private function normaliseRunwaySurface(mixed $surface): ?string
    {
        $raw = strtoupper(trim((string) $surface));

        if ($raw === '') {
            return null;
        }

        foreach (AirportRunwaySurface::cases() as $case) {
            if (
                $raw === strtoupper($case->name)
                || $raw === strtoupper($case->label())
                || $raw === strtoupper($case->value)
            ) {
                return $case->value;
            }
        }

        return $raw;
    }
}
