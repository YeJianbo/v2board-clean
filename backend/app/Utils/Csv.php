<?php

namespace App\Utils;

class Csv
{
    public static function download(string $filename, callable $write)
    {
        $stream = tmpfile();
        if ($stream === false) throw new \RuntimeException('无法创建导出临时文件');
        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) throw new \RuntimeException('导出文件写入失败');
            $write($stream);
            if (!fflush($stream) || !rewind($stream)) throw new \RuntimeException('导出文件读取失败');
            $stat = fstat($stream);
            if ($stat === false) throw new \RuntimeException('无法确认导出文件大小');
            return response()->streamDownload(static function () use ($stream): void {
                try {
                    if (fpassthru($stream) === false) throw new \RuntimeException('导出文件传输失败');
                } finally {
                    fclose($stream);
                }
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Length' => (string) $stat['size'],
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public static function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '') === false) {
            throw new \RuntimeException('导出文件写入失败');
        }
    }

    public static function text(?string $value): string
    {
        $value = $value ?? '';
        // CSV quotes alone do not make formula-like text safe for spreadsheet viewing.
        if (preg_match('/^(?:[\t\r\n]|[\x00-\x20]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}])/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
