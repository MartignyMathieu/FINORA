<?php

namespace App\Service;

/**
 * Lecture d'un fichier CSV envoyé par l'utilisateur : encodage (UTF-8 avec ou sans BOM, sinon Windows-1252), séparateur
 * (« ; » « , » tabulation ou « | ») détectés automatiquement, première ligne = en-têtes. Taille et nombre de lignes bornés.
 */
final class CsvReader
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 2000;
    public const MAX_COLUMNS = 30;
    private const MAX_CELL = 500;

    /**
     * @return array{header: list<string>, rows: list<list<string>>, delimiter: string}
     *
     * @throws \InvalidArgumentException avec un message destiné à l'utilisateur
     */
    public function read(string $content): array
    {
        if (\strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Le fichier dépasse 2 Mo.');
        }

        $content = $this->toUtf8($content);
        if ('' === trim($content)) {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }

        $delimiter = $this->detectDelimiter($content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $header = null;
        while (false !== ($record = fgetcsv($stream, null, $delimiter, '"', ''))) {
            // Ligne vide : fgetcsv renvoie [null].
            if ([null] === $record || [] === array_filter($record, static fn (?string $cell): bool => null !== $cell && '' !== trim($cell))) {
                continue;
            }
            $record = array_map(static fn (?string $cell): string => CsvValueParser::text((string) $cell, self::MAX_CELL), $record);
            if (\count($record) > self::MAX_COLUMNS) {
                fclose($stream);
                throw new \InvalidArgumentException(sprintf('Trop de colonnes (%d maximum).', self::MAX_COLUMNS));
            }

            if (null === $header) {
                $header = $record;
                continue;
            }
            if (\count($rows) >= self::MAX_ROWS) {
                fclose($stream);
                throw new \InvalidArgumentException(sprintf('Le fichier contient plus de %d lignes : découpez-le en plusieurs fichiers.', self::MAX_ROWS));
            }
            $rows[] = $record;
        }
        fclose($stream);

        if (null === $header || \count($header) < 2) {
            throw new \InvalidArgumentException('Le fichier ne ressemble pas à un CSV : la première ligne doit contenir les noms des colonnes.');
        }
        if ([] === $rows) {
            throw new \InvalidArgumentException('Le fichier ne contient que la ligne d\'en-têtes : aucune transaction à importer.');
        }

        // Les lignes plus courtes que l'en-tête sont complétées, pour que chaque colonne existe toujours.
        $width = \count($header);
        $rows = array_map(static fn (array $row): array => array_pad($row, $width, ''), $rows);

        return ['header' => $header, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Exports de banques et d'Excel (Windows) : Windows-1252.
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace("\0", '', $content);
    }

    /** Le séparateur le plus fréquent dans la première ligne non vide, « ; » par défaut. */
    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok(ltrim($content), "\r\n") ?: '';
        $best = ';';
        $bestCount = 0;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
