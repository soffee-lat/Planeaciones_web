<?php

namespace App\Console\Commands;

use App\Actions\Validation\EnsurePilotAiPrompts;
use App\Enums\RoleCode;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class EnsurePilotAiPromptsCommand extends Command
{
    protected $signature = 'validation:ensure-ai-prompts';

    protected $description = 'Garantiza los prompts manuales versionados requeridos por el piloto curricular sin reemplazar prompts activos existentes.';

    public function handle(EnsurePilotAiPrompts $ensure): int
    {
        $admin = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('code', RoleCode::Administrator->value))
            ->orderBy('id')
            ->first();

        if (! $admin) {
            $this->error('PILOT_AI_PROMPTS_ADMIN_REQUIRED');
            return self::FAILURE;
        }

        try {
            $prompts = $ensure->execute($admin);
            foreach ($prompts as $name => $version) {
                $this->info(sprintf(
                    '%s: template=%s version=%d schema=%s active=yes',
                    $name,
                    $version->template->key,
                    $version->number,
                    $version->schema_version,
                ));
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error($error->getMessage() !== '' ? $error->getMessage() : 'PILOT_AI_PROMPTS_ENSURE_FAILED');
            return self::FAILURE;
        }
    }
}
