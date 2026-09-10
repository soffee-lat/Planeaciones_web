<?php

namespace App\Services\Documents;

final class MinimalZipBuilder
{
    /** @var list<array{name:string,data:string,crc:int,size:int,offset:int}> */
    private array $entries = [];

    private string $body = '';

    public function add(string $name, string $data): void
    {
        $name = str_replace('\\', '/', trim($name));
        $crc = crc32($data);
        $size = strlen($data);
        $offset = strlen($this->body);
        $dosTime = 0;
        $dosDate = 33; // 1980-01-01 for deterministic archives.

        $header = pack('VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
        );

        $this->body .= $header . $name . $data;
        $this->entries[] = compact('name', 'data', 'crc', 'size', 'offset');
    }

    public function finish(): string
    {
        $central = '';
        $dosTime = 0;
        $dosDate = 33;

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $central .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $entry['offset'],
            ) . $name;
        }

        $centralOffset = strlen($this->body);
        $centralSize = strlen($central);
        $count = count($this->entries);
        $end = pack('VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            $centralSize,
            $centralOffset,
            0,
        );

        return $this->body . $central . $end;
    }
}
