<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;

final class OfficeOpenXmlPackage
{
    /** @param array<string,string> $entries */
    private function __construct(private array $entries) {}

    public static function fromBytes(string $bytes, int $maxEntries = 2000, int $maxExpandedBytes = 25_000_000): self
    {
        $eocd = strrpos($bytes, "PK\x05\x06");
        if ($eocd === false || strlen($bytes) - $eocd < 22) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
        }

        $end = unpack(
            'vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length',
            substr($bytes, $eocd + 4, 18),
        );
        if (! is_array($end)
            || (int) $end['disk'] !== 0
            || (int) $end['central_disk'] !== 0
            || (int) $end['entries_disk'] !== (int) $end['entries']
            || (int) $end['entries'] < 1
            || (int) $end['entries'] > $maxEntries) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
        }

        $offset = (int) $end['central_offset'];
        $centralEnd = $offset + (int) $end['central_size'];
        if ($offset < 0 || $centralEnd > strlen($bytes) || $centralEnd > $eocd) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
        }

        $entries = [];
        $expanded = 0;
        for ($i = 0; $i < (int) $end['entries']; $i++) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02") {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $header = unpack(
                'vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/Vexternal/Vlocal_offset',
                substr($bytes, $offset + 4, 42),
            );
            if (! is_array($header)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $nameLength = (int) $header['name_length'];
            $extraLength = (int) $header['extra_length'];
            $commentLength = (int) $header['comment_length'];
            $name = substr($bytes, $offset + 46, $nameLength);
            $offset += 46 + $nameLength + $extraLength + $commentLength;

            self::assertSafeEntryName($name);
            if (((int) $header['flags'] & 0x1) !== 0) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ENCRYPTED');
            }
            if (array_key_exists($name, $entries)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_DUPLICATE_ENTRY');
            }

            $uncompressed = (int) $header['uncompressed'];
            $compressed = (int) $header['compressed'];
            if ($uncompressed < 0 || $compressed < 0 || $uncompressed > $maxExpandedBytes) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_EXPANSION_LIMIT');
            }
            $expanded += $uncompressed;
            if ($expanded > $maxExpandedBytes) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_EXPANSION_LIMIT');
            }

            $localOffset = (int) $header['local_offset'];
            if (substr($bytes, $localOffset, 4) !== "PK\x03\x04") {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $local = unpack(
                'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length',
                substr($bytes, $localOffset + 4, 26),
            );
            if (! is_array($local)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $localName = substr($bytes, $localOffset + 30, (int) $local['name_length']);
            if ($localName !== $name
                || (int) $local['method'] !== (int) $header['method']
                || (((int) $local['flags'] & 0x1) !== 0)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $dataOffset = $localOffset + 30 + (int) $local['name_length'] + (int) $local['extra_length'];
            if ($dataOffset < 0 || $dataOffset + $compressed > strlen($bytes)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $compressedData = substr($bytes, $dataOffset, $compressed);
            $plain = match ((int) $header['method']) {
                0 => $compressedData,
                8 => @gzinflate($compressedData),
                default => throw new DocumentFormatException('FORMAT_SOURCE_DOCX_COMPRESSION_UNSUPPORTED'),
            };
            if (! is_string($plain) || strlen($plain) !== $uncompressed) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_INVALID');
            }
            $crc = (int) sprintf('%u', crc32($plain));
            if ($crc !== (int) $header['crc']) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_CRC_MISMATCH');
            }
            $entries[$name] = $plain;
        }

        return new self($entries);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    public function get(string $name): string
    {
        if (! $this->has($name)) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_REQUIRED_ENTRY_MISSING:' . $name);
        }

        return $this->entries[$name];
    }

    public function replace(string $name, string $contents): void
    {
        if (! $this->has($name)) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_REQUIRED_ENTRY_MISSING:' . $name);
        }
        $this->entries[$name] = $contents;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function toBytes(): string
    {
        $zip = new MinimalZipBuilder();
        foreach ($this->entries as $name => $contents) {
            $zip->add($name, $contents);
        }

        return $zip->finish();
    }

    private static function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_UNSAFE_ENTRY');
        }
        $normalized = str_replace('\\', '/', $name);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_UNSAFE_ENTRY');
            }
        }
    }
}
