<?php

namespace App\Services\Documents;

use App\Data\Documents\RenderedArtifact;
use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersionFile;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

final class DocumentOutputStorage
{
    /**
     * @param array<int,RenderedArtifact> $artifacts
     * @return list<array{format:string,file_id:int,sha256:string,size_bytes:int,mime:string,path:string}>
     */
    public function persist(DocumentRenderRun $run, array $artifacts): array
    {
        $run->loadMissing(['request', 'version.document']);
        $request = $run->request;
        $version = $run->version;
        if (! $request || ! $version || ! $version->document
            || (int) $version->document->request_id !== (int) $request->id) {
            throw new DocumentRenderException('DOCUMENT_RENDER_STORAGE_SOURCE_MISMATCH');
        }

        $disk = trim((string) config('documents.disk', 'private'));
        $prefix = trim((string) config('documents.prefix', 'documents'), '/');
        if ($disk === '' || $prefix === '') {
            throw new DocumentRenderException('DOCUMENT_RENDER_STORAGE_CONFIG_INVALID');
        }

        $rendererPath = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $run->renderer_version) ?: 'renderer';
        $basePath = sprintf(
            '%s/owner-%d/request-%d/version-%d/%s',
            $prefix,
            $request->owner_id,
            $request->id,
            $version->id,
            $rendererPath,
        );

        $manifest = [];
        foreach ($artifacts as $artifact) {
            if (! $artifact instanceof RenderedArtifact) {
                throw new DocumentRenderException('DOCUMENT_RENDER_ARTIFACT_INVALID');
            }
            $filename = sprintf('planeacion-v%d.%s', $version->number, $artifact->extension);
            $path = $basePath . '/' . $filename;
            $hash = $artifact->sha256();
            $size = $artifact->sizeBytes();
            if ($size < 1) {
                throw new DocumentRenderException('DOCUMENT_RENDER_ARTIFACT_EMPTY', $artifact->format->value);
            }

            $storage = Storage::disk($disk);
            if ($storage->exists($path)) {
                $existingBytes = $storage->get($path);
                if (hash('sha256', $existingBytes) !== $hash) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_PATH_HASH_CONFLICT', $artifact->format->value);
                }
            } else {
                $written = $storage->put($path, $artifact->bytes);
                if ($written !== true || ! $storage->exists($path)) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_STORAGE_WRITE_FAILED', $artifact->format->value);
                }
                if (hash('sha256', $storage->get($path)) !== $hash) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_STORAGE_VERIFY_FAILED', $artifact->format->value);
                }
            }

            $file = StoredFile::query()->where('path', $path)->first();
            if (! $file) {
                $file = StoredFile::query()->create([
                    'owner_id' => $request->owner_id,
                    'request_id' => $request->id,
                    'category' => FileCategory::Result->value,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $filename,
                    'detected_mime' => $artifact->mimeType,
                    'size_bytes' => $size,
                    'sha256' => $hash,
                    'scan_status' => FileScanStatus::Clean->value,
                    'uploaded_by' => null,
                ]);
            } elseif ((int) $file->owner_id !== (int) $request->owner_id
                || (int) $file->request_id !== (int) $request->id
                || $file->category !== FileCategory::Result
                || $file->scan_status !== FileScanStatus::Clean
                || $file->disk !== $disk
                || $file->sha256 !== $hash
                || (int) $file->size_bytes !== $size
                || $file->detected_mime !== $artifact->mimeType) {
                throw new DocumentRenderException('DOCUMENT_RENDER_FILE_IDENTITY_CONFLICT', $artifact->format->value);
            }

            $link = DocumentVersionFile::query()
                ->where('version_id', $version->id)
                ->where('output_format', $artifact->format->value)
                ->where('renderer_version', $run->renderer_version)
                ->first();
            if (! $link) {
                DocumentVersionFile::query()->create([
                    'version_id' => $version->id,
                    'file_id' => $file->id,
                    'output_format' => $artifact->format->value,
                    'renderer_version' => $run->renderer_version,
                ]);
            } elseif ((int) $link->file_id !== (int) $file->id) {
                throw new DocumentRenderException('DOCUMENT_RENDER_OUTPUT_LINK_CONFLICT', $artifact->format->value);
            }

            $manifest[] = [
                'format' => $artifact->format->value,
                'file_id' => (int) $file->id,
                'sha256' => $hash,
                'size_bytes' => $size,
                'mime' => $artifact->mimeType,
                'path' => $path,
            ];
        }

        usort($manifest, static fn (array $a, array $b): int => strcmp($a['format'], $b['format']));
        return $manifest;
    }
}
