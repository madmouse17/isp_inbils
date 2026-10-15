<?php

namespace Tests\Traits;

use Illuminate\Testing\TestResponse;

trait ParsesCsvDownloads
{
    /**
     * Rows of a CSV download, BOM stripped, header row first.
     *
     * @return list<list<string|null>>
     */
    protected function csvRows(TestResponse $response): array
    {
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'CSV must start with a UTF-8 BOM');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($content, 3));
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Data rows keyed by header, without the header row and the truncation marker row.
     *
     * @return list<array<string, string|null>>
     */
    protected function csvRecords(TestResponse $response): array
    {
        $rows = $this->csvRows($response);
        $header = array_shift($rows);

        $records = [];
        foreach ($rows as $row) {
            if (count($row) === 1 && str_starts_with((string) $row[0], '# Ekspor dipotong')) {
                continue;
            }
            $records[] = array_combine($header, $row);
        }

        return $records;
    }

    /**
     * A summary export as `Metrik` or `Metrik / Rincian` => Nilai.
     *
     * @return array<string, string|null>
     */
    protected function csvFigures(TestResponse $response): array
    {
        $figures = [];
        foreach ($this->csvRecords($response) as $record) {
            $figures[$record['Rincian'] === '' ? $record['Metrik'] : $record['Metrik'].' / '.$record['Rincian']] = $record['Nilai'];
        }

        return $figures;
    }
}
