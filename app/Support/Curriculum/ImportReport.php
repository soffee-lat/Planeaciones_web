<?php

namespace App\Support\Curriculum;

class ImportReport
{
    public bool $success = false;
    public bool $dryRun = false;

    public ?string $curriculumCode = null;
    public ?int $versionNumber = null;
    public ?int $draftId = null;
    public ?string $fileHash = null;

    /** @var array<string,int> */
    public array $counts = [
        'curricula' => 0,
        'versions' => 0,
        'phases' => 0,
        'grades' => 0,
        'fields' => 0,
        'contents' => 0,
        'pdas' => 0,
        'axes' => 0,
    ];

    /** @var ImportError[] */
    public array $errors = [];

    /** @var string[] */
    public array $warnings = [];

    public function addError(string $code, string $message, string $location = ''): void
    {
        $this->errors[] = new ImportError($code, $message, $location);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'dry_run' => $this->dryRun,
            'curriculum_code' => $this->curriculumCode,
            'version_number' => $this->versionNumber,
            'draft_id' => $this->draftId,
            'file_hash' => $this->fileHash,
            'counts' => $this->counts,
            'errors' => array_map(fn (ImportError $e) => $e->toArray(), $this->errors),
            'warnings' => $this->warnings,
        ];
    }
}
