<?php

namespace Tests\Feature\AI;

use App\Models\PlanningRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class AdaptiveDatabaseGuardTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    private function readyRequest(): PlanningRequest
    {
        $request = $this->draft();
        $this->period($request);

        return $this->authorize($this->confirm($request));
    }

    public function test_database_accepts_adaptive_canonical_document_version(): void
    {
        $request = $this->readyRequest();
        $documentId = DB::table('documents')->insertGetId([
            'request_id' => $request->id,
            'owner_id' => $request->owner_id,
            'title' => 'Adaptive canonical test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_versions')->insert([
            'document_id' => $documentId,
            'number' => 1,
            'parent_version_id' => null,
            'input_revision' => $request->input_revision,
            'content' => json_encode([
                'schema_version' => 'canonical_adaptive_plan_v1',
                'source' => [
                    'planning_request_id' => $request->id,
                    'input_revision' => $request->input_revision,
                ],
                'context' => [],
                'curricular_alignment' => [],
                'planning' => [],
                'pedagogical_design' => [],
            ], JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('a', 64),
            'source_payload_hash' => null,
            'created_by' => null,
            'ai_execution_id' => null,
            'status' => 'validated',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('document_versions', [
            'document_id' => $documentId,
            'number' => 1,
        ]);
    }

    public function test_database_still_rejects_unknown_canonical_schema(): void
    {
        $request = $this->readyRequest();
        $documentId = DB::table('documents')->insertGetId([
            'request_id' => $request->id,
            'owner_id' => $request->owner_id,
            'title' => 'Unknown canonical test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('document_versions')->insert([
                'document_id' => $documentId,
                'number' => 1,
                'parent_version_id' => null,
                'input_revision' => $request->input_revision,
                'content' => json_encode([
                    'schema_version' => 'canonical_unknown_v999',
                    'source' => [
                        'planning_request_id' => $request->id,
                        'input_revision' => $request->input_revision,
                    ],
                ], JSON_THROW_ON_ERROR),
                'content_hash' => str_repeat('b', 64),
                'source_payload_hash' => null,
                'created_by' => null,
                'ai_execution_id' => null,
                'status' => 'validated',
                'created_at' => now(),
            ]);
            $this->fail('Un schema canónico no reconocido debe ser rechazado por PostgreSQL.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('DOCUMENT_VERSION_CANONICAL_SOURCE_MISMATCH', $e->getMessage());
        }
    }

    public function test_correction_manifest_accepts_adaptive_scopes_but_rejects_unknown_scope(): void
    {
        $manifest = [
            'schema_version' => 1,
            'request_id' => 1,
            'input_revision' => 1,
            'source_kind' => 'audit',
            'source_version_id' => 1,
            'source_content_hash' => str_repeat('a', 64),
            'source_audit_execution_id' => 1,
            'source_audit_report_hash' => str_repeat('b', 64),
            'section_keys' => ['template_fields', 'custom'],
            'correction_round' => 1,
            'correlation_id' => '11111111-1111-4111-8111-111111111111',
        ];

        $accepted = DB::selectOne(
            'SELECT correction_input_manifest_v1_is_valid(?::jsonb) AS valid',
            [json_encode($manifest, JSON_THROW_ON_ERROR)],
        );
        $this->assertTrue((bool) $accepted->valid);

        $manifest['section_keys'] = ['template_fields', 'forbidden_root'];
        $rejected = DB::selectOne(
            'SELECT correction_input_manifest_v1_is_valid(?::jsonb) AS valid',
            [json_encode($manifest, JSON_THROW_ON_ERROR)],
        );
        $this->assertFalse((bool) $rejected->valid);
    }
}
