<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('planning_requests')->restrictOnDelete();
            $table->foreignId('delivered_version_id')->nullable()->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('source_version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 24);
            $table->string('reason', 64);
            $table->text('description');
            $table->jsonb('section_keys');
            $table->string('status', 24);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resulting_version_id')->nullable()->constrained('document_versions')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_type_check CHECK (type IN ('client','internal'))");
        DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_status_check CHECK (status IN ('requested','accepted','processing','resolved','rejected','withdrawn'))");
        DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_sections_check CHECK (jsonb_typeof(section_keys) = 'array' AND jsonb_array_length(section_keys) > 0)");
        DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_terminal_check CHECK (((status IN ('resolved','rejected','withdrawn')) = (resolved_at IS NOT NULL)) AND ((status = 'resolved') = (resulting_version_id IS NOT NULL)) AND ((status IN ('resolved','rejected','withdrawn')) = (resolution IS NOT NULL)))");
        DB::statement("ALTER TABLE correction_requests ADD CONSTRAINT correction_requests_client_identity_check CHECK (type <> 'client' OR (requester_id IS NOT NULL AND delivered_version_id IS NOT NULL AND delivered_version_id = source_version_id))");
        DB::statement("CREATE UNIQUE INDEX correction_requests_one_open_per_request_idx ON correction_requests (request_id) WHERE status IN ('requested','accepted','processing')");
        DB::statement('CREATE INDEX correction_requests_request_status_idx ON correction_requests (request_id, status)');

        Schema::table('usage_reservations', function (Blueprint $table) {
            $table->foreign('correction_request_id', 'usage_reservations_correction_request_fk')
                ->references('id')->on('correction_requests')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION correction_request_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'CORRECTION_REQUEST_HISTORY_IMMUTABLE';
                END IF;

                IF ROW(NEW.id, NEW.request_id, NEW.delivered_version_id, NEW.source_version_id,
                       NEW.requester_id, NEW.type, NEW.reason, NEW.description, NEW.section_keys,
                       NEW.requested_at, NEW.created_at)
                   IS DISTINCT FROM
                   ROW(OLD.id, OLD.request_id, OLD.delivered_version_id, OLD.source_version_id,
                       OLD.requester_id, OLD.type, OLD.reason, OLD.description, OLD.section_keys,
                       OLD.requested_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'CORRECTION_REQUEST_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.status IN ('resolved','rejected','withdrawn') AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'CORRECTION_REQUEST_TERMINAL_IMMUTABLE';
                END IF;
                IF OLD.assigned_to IS NOT NULL AND OLD.assigned_to IS DISTINCT FROM NEW.assigned_to THEN
                    RAISE EXCEPTION 'CORRECTION_REQUEST_ASSIGNEE_IMMUTABLE';
                END IF;

                IF OLD.status <> NEW.status THEN
                    IF OLD.status = 'requested' AND NEW.status NOT IN ('accepted','rejected','withdrawn') THEN
                        RAISE EXCEPTION 'CORRECTION_REQUEST_INVALID_TRANSITION';
                    ELSIF OLD.status = 'accepted' AND NEW.status NOT IN ('processing','rejected') THEN
                        RAISE EXCEPTION 'CORRECTION_REQUEST_INVALID_TRANSITION';
                    ELSIF OLD.status = 'processing' AND NEW.status <> 'resolved' THEN
                        RAISE EXCEPTION 'CORRECTION_REQUEST_INVALID_TRANSITION';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION correction_request_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                cr correction_requests;
                parent planning_requests;
                source_document_request bigint;
            BEGIN
                SELECT * INTO cr FROM correction_requests WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;
                SELECT * INTO parent FROM planning_requests WHERE id = cr.request_id FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'CORRECTION_REQUEST_PARENT_REQUIRED'; END IF;

                SELECT d.request_id INTO source_document_request
                FROM document_versions dv JOIN documents d ON d.id = dv.document_id
                WHERE dv.id = cr.source_version_id;
                IF source_document_request IS DISTINCT FROM cr.request_id THEN
                    RAISE EXCEPTION 'CORRECTION_REQUEST_SOURCE_REQUEST_MISMATCH';
                END IF;

                IF cr.type = 'client' THEN
                    IF cr.requester_id IS DISTINCT FROM parent.owner_id THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_REQUESTER_MISMATCH';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM deliveries d
                        WHERE d.request_id = cr.request_id AND d.version_id = cr.delivered_version_id
                    ) THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_DELIVERY_REQUIRED';
                    END IF;
                    IF cr.status = 'requested' AND parent.status <> 'CORRECCION_SOLICITADA' THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_REQUEST_STATE_REQUIRED';
                    END IF;
                    IF cr.status = 'accepted' AND parent.status <> 'CORRECCION_SOLICITADA' THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_ACCEPTED_STATE_REQUIRED';
                    END IF;
                    IF cr.status = 'processing' THEN
                        IF parent.status <> 'CORRECCION_IA' OR cr.assigned_to IS NULL THEN
                            RAISE EXCEPTION 'CLIENT_CORRECTION_PROCESSING_STATE_REQUIRED';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM usage_reservations ur
                            WHERE ur.correction_request_id = cr.id
                              AND ur.planning_request_id = cr.request_id
                              AND ur.subscription_period_id = parent.subscription_period_id
                              AND ur.resource = 'client_correction'
                              AND ur.quantity = 1
                              AND ur.status = 'consumed'
                        ) THEN
                            RAISE EXCEPTION 'CLIENT_CORRECTION_CONSUMED_RESERVATION_REQUIRED';
                        END IF;
                    END IF;
                    IF cr.status = 'resolved' AND NOT EXISTS (
                        SELECT 1 FROM document_versions child
                        WHERE child.id = cr.resulting_version_id
                          AND child.parent_version_id = cr.source_version_id
                    ) THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_RESULT_VERSION_INVALID';
                    END IF;
                    IF cr.status IN ('rejected','withdrawn') AND parent.status NOT IN ('ENTREGADA','COMPLETADA') THEN
                        RAISE EXCEPTION 'CLIENT_CORRECTION_RETURN_STATE_REQUIRED';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION correction_request_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM correction_request_integrity_check(NEW.id);
                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement('CREATE TRIGGER correction_requests_history_trg BEFORE UPDATE OR DELETE ON correction_requests FOR EACH ROW EXECUTE FUNCTION correction_request_history_guard()');
        DB::statement('CREATE CONSTRAINT TRIGGER correction_requests_integrity_trg AFTER INSERT OR UPDATE ON correction_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION correction_request_integrity_trigger()');

        $this->installCorrectionManifestFunction(clientEnabled: true);
        $this->installPostAuditRoutingFunction(clientEnabled: true);
    }

    public function down(): void
    {
        // Restaurar primero contratos de 5C para no dejar funciones apuntando a
        // correction_requests después de eliminar la tabla.
        $this->installPostAuditRoutingFunction(clientEnabled: false);
        $this->installCorrectionManifestFunction(clientEnabled: false);

        Schema::table('usage_reservations', function (Blueprint $table) {
            $table->dropForeign('usage_reservations_correction_request_fk');
        });
        DB::statement('DROP TRIGGER IF EXISTS correction_requests_integrity_trg ON correction_requests');
        DB::statement('DROP TRIGGER IF EXISTS correction_requests_history_trg ON correction_requests');
        DB::statement('DROP FUNCTION IF EXISTS correction_request_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS correction_request_integrity_check(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS correction_request_history_guard()');
        Schema::dropIfExists('correction_requests');
    }

    private function installCorrectionManifestFunction(bool $clientEnabled): void
    {
        if (! $clientEnabled) {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION correction_input_manifest_v1_is_valid(manifest jsonb) RETURNS boolean
                LANGUAGE plpgsql IMMUTABLE AS $$
                DECLARE key text; source_kind text;
                BEGIN
                    IF manifest IS NULL OR jsonb_typeof(manifest) <> 'object' THEN RETURN false; END IF;
                    IF (manifest - 'schema_version' - 'request_id' - 'input_revision' - 'source_kind'
                        - 'source_version_id' - 'source_content_hash' - 'source_audit_execution_id'
                        - 'source_audit_report_hash' - 'source_review_id' - 'source_review_payload_hash'
                        - 'section_keys' - 'correction_round' - 'correlation_id') <> '{}'::jsonb THEN RETURN false; END IF;
                    source_kind := COALESCE(NULLIF(manifest->>'source_kind', ''), 'audit');
                    IF source_kind NOT IN ('audit','human_review') THEN RETURN false; END IF;
                    IF NULLIF(manifest->>'schema_version', '')::integer IS DISTINCT FROM 1
                       OR COALESCE(NULLIF(manifest->>'request_id', '')::bigint, 0) < 1
                       OR NULLIF(manifest->>'input_revision', '')::integer IS NULL
                       OR COALESCE(NULLIF(manifest->>'source_version_id', '')::bigint, 0) < 1
                       OR COALESCE(NULLIF(manifest->>'source_audit_execution_id', '')::bigint, 0) < 1
                       OR COALESCE(NULLIF(manifest->>'correction_round', '')::integer, 0) < 1
                       OR manifest->>'source_content_hash' !~ '^[0-9a-f]{64}$'
                       OR manifest->>'source_audit_report_hash' !~ '^[0-9a-f]{64}$'
                       OR jsonb_typeof(manifest->'section_keys') IS DISTINCT FROM 'array'
                       OR jsonb_array_length(manifest->'section_keys') < 1
                       OR manifest->>'correlation_id' !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' THEN RETURN false; END IF;
                    IF source_kind = 'audit' THEN
                        IF manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash' THEN RETURN false; END IF;
                    ELSE
                        IF COALESCE(NULLIF(manifest->>'source_review_id', '')::bigint, 0) < 1
                           OR manifest->>'source_review_payload_hash' !~ '^[0-9a-f]{64}$' THEN RETURN false; END IF;
                    END IF;
                    FOR key IN SELECT jsonb_array_elements_text(manifest->'section_keys') LOOP
                        IF key NOT IN ('planning','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes') THEN RETURN false; END IF;
                    END LOOP;
                    RETURN true;
                EXCEPTION WHEN others THEN RETURN false;
                END;
                $$;
            SQL);
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION correction_input_manifest_v1_is_valid(manifest jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE key text; source_kind text;
            BEGIN
                IF manifest IS NULL OR jsonb_typeof(manifest) <> 'object' THEN RETURN false; END IF;
                IF (manifest - 'schema_version' - 'request_id' - 'input_revision' - 'source_kind'
                    - 'source_version_id' - 'source_content_hash' - 'source_audit_execution_id'
                    - 'source_audit_report_hash' - 'source_review_id' - 'source_review_payload_hash'
                    - 'source_correction_request_id' - 'source_correction_payload_hash'
                    - 'section_keys' - 'correction_round' - 'correlation_id') <> '{}'::jsonb THEN RETURN false; END IF;
                source_kind := COALESCE(NULLIF(manifest->>'source_kind', ''), 'audit');
                IF source_kind NOT IN ('audit','human_review','client') THEN RETURN false; END IF;
                IF NULLIF(manifest->>'schema_version', '')::integer IS DISTINCT FROM 1
                   OR COALESCE(NULLIF(manifest->>'request_id', '')::bigint, 0) < 1
                   OR NULLIF(manifest->>'input_revision', '')::integer IS NULL
                   OR COALESCE(NULLIF(manifest->>'source_version_id', '')::bigint, 0) < 1
                   OR COALESCE(NULLIF(manifest->>'source_audit_execution_id', '')::bigint, 0) < 1
                   OR COALESCE(NULLIF(manifest->>'correction_round', '')::integer, 0) < 1
                   OR manifest->>'source_content_hash' !~ '^[0-9a-f]{64}$'
                   OR manifest->>'source_audit_report_hash' !~ '^[0-9a-f]{64}$'
                   OR jsonb_typeof(manifest->'section_keys') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(manifest->'section_keys') < 1
                   OR manifest->>'correlation_id' !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' THEN RETURN false; END IF;

                IF source_kind = 'audit' THEN
                    IF manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash'
                       OR manifest ? 'source_correction_request_id' OR manifest ? 'source_correction_payload_hash' THEN RETURN false; END IF;
                ELSIF source_kind = 'human_review' THEN
                    IF COALESCE(NULLIF(manifest->>'source_review_id', '')::bigint, 0) < 1
                       OR manifest->>'source_review_payload_hash' !~ '^[0-9a-f]{64}$'
                       OR manifest ? 'source_correction_request_id' OR manifest ? 'source_correction_payload_hash' THEN RETURN false; END IF;
                ELSE
                    IF COALESCE(NULLIF(manifest->>'source_correction_request_id', '')::bigint, 0) < 1
                       OR manifest->>'source_correction_payload_hash' !~ '^[0-9a-f]{64}$'
                       OR manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash' THEN RETURN false; END IF;
                END IF;

                FOR key IN SELECT jsonb_array_elements_text(manifest->'section_keys') LOOP
                    IF key NOT IN ('planning','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes') THEN RETURN false; END IF;
                END LOOP;
                RETURN true;
            EXCEPTION WHEN others THEN RETURN false;
            END;
            $$;
        SQL);
    }

    private function installPostAuditRoutingFunction(bool $clientEnabled): void
    {
        $clientDeclaration = $clientEnabled ? "\n                correction_request_id bigint;\n                cr correction_requests;" : '';
        $clientSelect = $clientEnabled
            ? ",\n                           NULLIF(ae.input_manifest->>'source_correction_request_id','')::bigint"
            : '';
        $clientInto = $clientEnabled ? ', correction_request_id' : '';
        $clientBranch = $clientEnabled ? <<<'SQL'
                    ELSIF correction_source_kind = 'client' THEN
                        IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM true THEN
                            RAISE EXCEPTION 'AI_CLIENT_CORRECTION_REQUIRES_PASSED_AUDIT';
                        END IF;
                        SELECT * INTO cr FROM correction_requests WHERE id = correction_request_id;
                        IF NOT FOUND OR cr.request_id IS DISTINCT FROM parent.id
                           OR cr.source_version_id IS DISTINCT FROM current_version_id
                           OR cr.status IS DISTINCT FROM 'processing'
                           OR cr.assigned_to IS NULL THEN
                            RAISE EXCEPTION 'AI_CLIENT_CORRECTION_REQUEST_REQUIRED';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM usage_reservations ur
                            WHERE ur.correction_request_id = cr.id
                              AND ur.planning_request_id = parent.id
                              AND ur.resource = 'client_correction'
                              AND ur.quantity = 1
                              AND ur.status = 'consumed'
                        ) THEN
                            RAISE EXCEPTION 'AI_CLIENT_CORRECTION_RESERVATION_REQUIRED';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'CORRECCION_SOLICITADA'
                              AND rse.to_status = 'CORRECCION_IA'
                              AND rse.reason = 'client_correction_processing'
                              AND rse.actor_id = cr.assigned_to
                              AND rse.correlation_id::text = correction_correlation
                        ) THEN
                            RAISE EXCEPTION 'AI_CLIENT_CORRECTION_STATE_EVENT_REQUIRED';
                        END IF;
SQL
            : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION planning_post_audit_routing_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS \$\$
            DECLARE
                parent planning_requests;
                current_version_id bigint;
                current_content_hash char(64);
                audit_id bigint;
                audit_report_value jsonb;
                correction_id bigint;
                correction_status text;
                correction_source_kind text;
                correction_source_audit_id bigint;
                correction_review_id bigint;
                correction_correlation text;
                rv reviews;
                ra review_assignments;{$clientDeclaration}
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND OR parent.commercial_authorized_at IS NULL THEN RETURN; END IF;
                IF parent.status NOT IN (
                    'CORRECCION_IA','REVISION_HUMANA','APROBADA','GENERANDO_DOCUMENTO',
                    'LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA'
                ) THEN RETURN; END IF;

                SELECT d.current_version_id, dv.content_hash INTO current_version_id, current_content_hash
                FROM documents d JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.request_id = parent.id;
                IF current_version_id IS NULL OR current_content_hash IS NULL THEN RETURN; END IF;

                SELECT ae.id, ae.audit_report INTO audit_id, audit_report_value
                FROM ai_executions ae
                WHERE ae.request_id = parent.id AND ae.stage = 'audit' AND ae.status = 'succeeded'
                  AND ae.input_revision = parent.input_revision
                  AND ae.input_manifest->>'source_version_id' = current_version_id::text
                  AND ae.input_manifest->>'source_content_hash' = current_content_hash
                ORDER BY ae.id DESC LIMIT 1;
                IF audit_id IS NULL OR audit_report_value IS NULL OR NOT audit_result_v1_is_valid(audit_report_value) THEN RETURN; END IF;

                IF parent.status = 'CORRECCION_IA' THEN
                    SELECT ae.id, ae.status,
                           COALESCE(NULLIF(ae.input_manifest->>'source_kind',''), 'audit'),
                           NULLIF(ae.input_manifest->>'source_audit_execution_id','')::bigint,
                           NULLIF(ae.input_manifest->>'source_review_id','')::bigint,
                           ae.input_manifest->>'correlation_id'{$clientSelect}
                    INTO correction_id, correction_status, correction_source_kind,
                         correction_source_audit_id, correction_review_id, correction_correlation{$clientInto}
                    FROM ai_executions ae
                    WHERE ae.request_id = parent.id AND ae.stage = 'correction'
                      AND ae.input_revision = parent.input_revision
                      AND ae.input_manifest->>'source_version_id' = current_version_id::text
                      AND ae.input_manifest->>'source_content_hash' = current_content_hash
                    ORDER BY ae.id DESC LIMIT 1;

                    IF correction_id IS NULL OR correction_status NOT IN ('pending','waiting_manual','running') THEN
                        RAISE EXCEPTION 'AI_CORRECTION_EXECUTION_REQUIRED';
                    END IF;
                    IF correction_source_audit_id IS DISTINCT FROM audit_id THEN
                        RAISE EXCEPTION 'AI_CORRECTION_SOURCE_AUDIT_MISMATCH';
                    END IF;

                    IF correction_source_kind = 'audit' THEN
                        IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM false THEN
                            RAISE EXCEPTION 'AI_CORRECTION_REQUIRES_FAILED_AUDIT';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'AUDITORIA_IA' AND rse.to_status = 'CORRECCION_IA'
                              AND rse.reason = 'audit_failed_correction_dispatched'
                              AND rse.correlation_id::text = correction_correlation
                        ) THEN RAISE EXCEPTION 'AI_CORRECTION_STATE_EVENT_REQUIRED'; END IF;
                    ELSIF correction_source_kind = 'human_review' THEN
                        IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM true THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_REQUIRES_PASSED_AUDIT';
                        END IF;
                        SELECT * INTO rv FROM reviews WHERE id = correction_review_id;
                        IF NOT FOUND OR rv.request_id IS DISTINCT FROM parent.id
                           OR rv.version_id IS DISTINCT FROM current_version_id
                           OR rv.status IS DISTINCT FROM 'changes_requested' THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_REVIEW_REQUIRED';
                        END IF;
                        SELECT * INTO ra FROM review_assignments WHERE id = rv.assignment_id;
                        IF NOT FOUND OR ra.status IS DISTINCT FROM 'completed'
                           OR ra.ended_reason IS DISTINCT FROM 'human_review_changes_requested' THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_ASSIGNMENT_REQUIRED';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'REVISION_HUMANA' AND rse.to_status = 'CORRECCION_IA'
                              AND rse.reason = 'human_review_changes_requested'
                              AND rse.actor_id = rv.reviewer_id
                              AND rse.correlation_id::text = correction_correlation
                        ) THEN RAISE EXCEPTION 'AI_HUMAN_CORRECTION_STATE_EVENT_REQUIRED'; END IF;
        {$clientBranch}                    ELSE
                        RAISE EXCEPTION 'AI_CORRECTION_SOURCE_KIND_INVALID';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM outbox_events oe
                        WHERE oe.event_key = 'ai-execution:' || correction_id || ':correction-dispatch'
                          AND oe.type = 'planning.correction.requested'
                          AND oe.aggregate_id = parent.id
                          AND oe.payload->>'ai_execution_id' = correction_id::text
                          AND oe.payload->>'source_version_id' = current_version_id::text
                    ) THEN RAISE EXCEPTION 'AI_CORRECTION_OUTBOX_REQUIRED'; END IF;
                    IF correction_status IN ('waiting_manual','running') AND NOT EXISTS (
                        SELECT 1 FROM ai_manual_packages WHERE ai_execution_id = correction_id
                    ) THEN RAISE EXCEPTION 'AI_CORRECTION_MANUAL_PACKAGE_REQUIRED'; END IF;
                    RETURN;
                END IF;

                IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'AI_POST_AUDIT_PASS_REQUIRED';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM approvals a
                    WHERE a.request_id = parent.id AND a.version_id = current_version_id
                      AND a.kind = 'ai' AND a.ai_execution_id = audit_id
                ) THEN RAISE EXCEPTION 'AI_APPROVAL_REQUIRED'; END IF;

                IF parent.status = 'REVISION_HUMANA' THEN
                    IF parent.human_review_required_snapshot IS DISTINCT FROM true THEN
                        RAISE EXCEPTION 'AI_HUMAN_REVIEW_NOT_REQUIRED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM request_state_events rse
                        WHERE rse.request_id = parent.id
                          AND rse.from_status = 'AUDITORIA_IA' AND rse.to_status = 'REVISION_HUMANA'
                          AND rse.reason = 'audit_passed_human_review_required'
                    ) THEN RAISE EXCEPTION 'AI_HUMAN_REVIEW_STATE_EVENT_REQUIRED'; END IF;
                    RETURN;
                END IF;

                IF parent.status IN ('APROBADA','GENERANDO_DOCUMENTO','LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA') THEN
                    IF parent.human_review_required_snapshot THEN
                        IF NOT EXISTS (
                            SELECT 1 FROM approvals a
                            WHERE a.request_id = parent.id AND a.version_id = current_version_id AND a.kind = 'human'
                        ) THEN RAISE EXCEPTION 'HUMAN_APPROVAL_REQUIRED'; END IF;
                    ELSE
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'AUDITORIA_IA' AND rse.to_status = 'APROBADA'
                              AND rse.reason = 'audit_passed_ai_approved'
                        ) THEN RAISE EXCEPTION 'AI_APPROVAL_STATE_EVENT_REQUIRED'; END IF;
                    END IF;
                END IF;
            END;
            \$\$;
        SQL);
    }
};
