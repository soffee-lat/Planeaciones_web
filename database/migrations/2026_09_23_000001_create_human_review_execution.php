<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_checklist_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['key', 'version']);
            $table->index(['key', 'status', 'version']);
        });
        DB::statement("ALTER TABLE review_checklist_versions ADD CONSTRAINT review_checklist_versions_status_check CHECK (status IN ('draft','published'))");
        DB::statement("ALTER TABLE review_checklist_versions ADD CONSTRAINT review_checklist_versions_published_check CHECK ((status = 'published') = (published_at IS NOT NULL))");
        DB::statement('ALTER TABLE review_checklist_versions ADD CONSTRAINT review_checklist_versions_version_check CHECK (version > 0)');

        Schema::create('review_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_version_id')->constrained('review_checklist_versions')->restrictOnDelete();
            $table->string('key', 64);
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['checklist_version_id', 'key']);
            $table->index(['checklist_version_id', 'sort_order']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('planning_requests')->restrictOnDelete();
            $table->foreignId('assignment_id')->unique()->constrained('review_assignments')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('checklist_version_id')->constrained('review_checklist_versions')->restrictOnDelete();
            $table->unsignedBigInteger('reviewer_id');
            $table->string('status', 32)->default('in_progress');
            $table->timestampTz('started_at');
            $table->timestampTz('decided_at')->nullable();
            $table->text('general_comment')->nullable();
            $table->jsonb('section_comments')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
            $table->index(['reviewer_id', 'status', 'started_at']);
            $table->index(['request_id', 'version_id']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('in_progress','approved'))");
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_decision_check CHECK ((status = 'in_progress' AND decided_at IS NULL) OR (status <> 'in_progress' AND decided_at IS NOT NULL))");
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_section_comments_object_check CHECK (jsonb_typeof(section_comments) = 'object')");

        Schema::create('review_checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->restrictOnDelete();
            $table->foreignId('checklist_item_id')->constrained('review_checklist_items')->restrictOnDelete();
            $table->boolean('passed');
            $table->text('comment')->nullable();
            $table->timestampsTz();
            $table->unique(['review_id', 'checklist_item_id']);
        });

        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_review_fk FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE RESTRICT');

        // Checklist estándar v1 para que el flujo sea utilizable al aplicar la migración.
        $checklistId = DB::table('review_checklist_versions')->insertGetId([
            'key' => 'standard',
            'version' => 1,
            'name' => 'Lista estándar de revisión docente',
            'status' => 'draft',
            'published_at' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $items = [
            ['grade', 'Grado correcto'],
            ['dates', 'Fechas y periodo coherentes'],
            ['contents', 'Contenidos curriculares correctos'],
            ['pda', 'PDA cubiertos'],
            ['fields', 'Campos formativos coherentes'],
            ['axes', 'Ejes articuladores pertinentes'],
            ['coherence', 'Coherencia pedagógica general'],
            ['difficulty', 'Dificultad adecuada al grupo'],
            ['activities', 'Actividades viables y pertinentes'],
            ['moments', 'Inicio, desarrollo y cierre suficientes'],
            ['assessment', 'Evaluación formativa alineada'],
            ['materials', 'Materiales factibles'],
            ['transversal', 'Transversalidad pertinente'],
            ['orthography', 'Ortografía y redacción correctas'],
            ['format', 'Estructura completa para el formato final'],
        ];
        foreach ($items as $index => [$key, $label]) {
            DB::table('review_checklist_items')->insert([
                'checklist_version_id' => $checklistId,
                'key' => $key,
                'label' => $label,
                'description' => null,
                'required' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => now(),
            ]);
        }
        DB::table('review_checklist_versions')->where('id', $checklistId)->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_checklist_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.status = 'published' THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_PUBLISHED_IMMUTABLE';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status = 'published' AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_PUBLISHED_IMMUTABLE';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status = 'draft' THEN
                    IF OLD.key IS DISTINCT FROM NEW.key OR OLD.version IS DISTINCT FROM NEW.version OR OLD.created_by IS DISTINCT FROM NEW.created_by THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_IDENTITY_IMMUTABLE';
                    END IF;
                    IF NEW.status = 'published' AND NOT EXISTS (
                        SELECT 1 FROM review_checklist_items i
                        WHERE i.checklist_version_id = OLD.id AND i.required = true
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_REQUIRED_ITEM_MISSING';
                    END IF;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER review_checklist_versions_guard_trg BEFORE UPDATE OR DELETE ON review_checklist_versions FOR EACH ROW EXECUTE FUNCTION review_checklist_version_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_checklist_item_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_version bigint;
                parent_status text;
            BEGIN
                target_version := CASE WHEN TG_OP = 'DELETE' THEN OLD.checklist_version_id ELSE NEW.checklist_version_id END;
                SELECT status INTO parent_status FROM review_checklist_versions WHERE id = target_version;
                IF parent_status = 'published' THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_ITEMS_PUBLISHED_IMMUTABLE';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.checklist_version_id IS DISTINCT FROM NEW.checklist_version_id THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_ITEM_VERSION_IMMUTABLE';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER review_checklist_items_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON review_checklist_items FOR EACH ROW EXECUTE FUNCTION review_checklist_item_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_HISTORY_IMMUTABLE';
                END IF;
                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.assignment_id IS DISTINCT FROM NEW.assignment_id
                   OR OLD.version_id IS DISTINCT FROM NEW.version_id
                   OR OLD.checklist_version_id IS DISTINCT FROM NEW.checklist_version_id
                   OR OLD.reviewer_id IS DISTINCT FROM NEW.reviewer_id
                   OR OLD.started_at IS DISTINCT FROM NEW.started_at
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_IDENTITY_IMMUTABLE';
                END IF;
                IF OLD.status <> 'in_progress' AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_TERMINAL_IMMUTABLE';
                END IF;
                IF OLD.status = 'in_progress' AND NEW.status NOT IN ('in_progress','approved') THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_INVALID_TRANSITION';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER reviews_history_trg BEFORE UPDATE OR DELETE ON reviews FOR EACH ROW EXECUTE FUNCTION human_review_history_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_response_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                parent_status text;
                parent_checklist bigint;
                item_checklist bigint;
                target_review bigint;
                target_item bigint;
            BEGIN
                target_review := CASE WHEN TG_OP = 'DELETE' THEN OLD.review_id ELSE NEW.review_id END;
                target_item := CASE WHEN TG_OP = 'DELETE' THEN OLD.checklist_item_id ELSE NEW.checklist_item_id END;
                SELECT status, checklist_version_id INTO parent_status, parent_checklist FROM reviews WHERE id = target_review;
                IF parent_status IS DISTINCT FROM 'in_progress' THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_RESPONSES_LOCKED';
                END IF;
                SELECT checklist_version_id INTO item_checklist FROM review_checklist_items WHERE id = target_item;
                IF item_checklist IS DISTINCT FROM parent_checklist THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_RESPONSE_CHECKLIST_MISMATCH';
                END IF;
                IF TG_OP = 'UPDATE' AND (OLD.review_id IS DISTINCT FROM NEW.review_id OR OLD.checklist_item_id IS DISTINCT FROM NEW.checklist_item_id) THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_RESPONSE_IDENTITY_IMMUTABLE';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER review_checklist_responses_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON review_checklist_responses FOR EACH ROW EXECUTE FUNCTION human_review_response_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                rv reviews;
                a review_assignments;
                r planning_requests;
                current_version bigint;
                missing_required integer;
            BEGIN
                SELECT * INTO rv FROM reviews WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;

                SELECT * INTO a FROM review_assignments WHERE id = rv.assignment_id FOR UPDATE;
                IF NOT FOUND OR a.request_id IS DISTINCT FROM rv.request_id OR a.reviewer_id IS DISTINCT FROM rv.reviewer_id THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_ASSIGNMENT_MISMATCH';
                END IF;
                SELECT * INTO r FROM planning_requests WHERE id = rv.request_id FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'HUMAN_REVIEW_REQUEST_REQUIRED'; END IF;
                SELECT current_version_id INTO current_version FROM documents WHERE request_id = rv.request_id;
                IF current_version IS DISTINCT FROM rv.version_id THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_VERSION_STALE';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM review_checklist_versions cv
                    WHERE cv.id = rv.checklist_version_id AND cv.status = 'published'
                ) THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_NOT_PUBLISHED';
                END IF;

                IF rv.status = 'in_progress' THEN
                    IF a.status <> 'in_progress' OR r.status <> 'REVISION_HUMANA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_ACTIVE_STATE_MISMATCH';
                    END IF;
                ELSIF rv.status = 'approved' THEN
                    IF a.status <> 'completed' OR r.status <> 'APROBADA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_APPROVED_STATE_MISMATCH';
                    END IF;
                    SELECT count(*) INTO missing_required
                    FROM review_checklist_items i
                    LEFT JOIN review_checklist_responses rr
                      ON rr.review_id = rv.id AND rr.checklist_item_id = i.id AND rr.passed = true
                    WHERE i.checklist_version_id = rv.checklist_version_id
                      AND i.required = true
                      AND rr.id IS NULL;
                    IF missing_required > 0 THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_REQUIRED_CHECKLIST_INCOMPLETE';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM approvals ap
                        WHERE ap.review_id = rv.id AND ap.kind = 'human'
                          AND ap.request_id = rv.request_id AND ap.version_id = rv.version_id
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_APPROVAL_REQUIRED';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION human_review_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'reviews' THEN
                    target_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'review_checklist_responses' THEN
                    target_id := NEW.review_id;
                ELSIF TG_TABLE_NAME = 'review_assignments' THEN
                    SELECT id INTO target_id FROM reviews WHERE assignment_id = NEW.id;
                ELSIF TG_TABLE_NAME = 'approvals' AND NEW.review_id IS NOT NULL THEN
                    target_id := NEW.review_id;
                END IF;
                IF target_id IS NOT NULL THEN PERFORM human_review_integrity_check(target_id); END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER reviews_integrity_trg AFTER INSERT OR UPDATE ON reviews DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER review_responses_integrity_trg AFTER INSERT OR UPDATE ON review_checklist_responses DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER review_assignments_review_integrity_trg AFTER UPDATE ON review_assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER approvals_review_integrity_trg AFTER INSERT ON approvals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_integrity_trigger()');

        // Sustituye la frontera temporal de 4E: ahora la aprobación humana tiene integridad verificable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                a approvals;
                version_request_id bigint;
                audit_request_id bigint;
                audit_stage text;
                audit_status text;
                audit_report_value jsonb;
                audit_source_version_id bigint;
                rv reviews;
                ra review_assignments;
                missing_required integer;
            BEGIN
                SELECT * INTO a FROM approvals WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;

                SELECT d.request_id INTO version_request_id
                FROM document_versions dv JOIN documents d ON d.id = dv.document_id
                WHERE dv.id = a.version_id;
                IF version_request_id IS DISTINCT FROM a.request_id THEN
                    RAISE EXCEPTION 'APPROVAL_VERSION_REQUEST_MISMATCH';
                END IF;

                IF a.kind = 'human' THEN
                    SELECT * INTO rv FROM reviews WHERE id = a.review_id;
                    IF NOT FOUND THEN RAISE EXCEPTION 'HUMAN_APPROVAL_REVIEW_REQUIRED'; END IF;
                    SELECT * INTO ra FROM review_assignments WHERE id = rv.assignment_id;
                    IF rv.request_id IS DISTINCT FROM a.request_id
                       OR rv.version_id IS DISTINCT FROM a.version_id
                       OR rv.status IS DISTINCT FROM 'approved'
                       OR rv.decided_at IS NULL
                       OR ra.status IS DISTINCT FROM 'completed'
                       OR ra.reviewer_id IS DISTINCT FROM rv.reviewer_id
                       OR a.actor_id IS DISTINCT FROM rv.reviewer_id THEN
                        RAISE EXCEPTION 'HUMAN_APPROVAL_REVIEW_MISMATCH';
                    END IF;
                    SELECT count(*) INTO missing_required
                    FROM review_checklist_items i
                    LEFT JOIN review_checklist_responses rr
                      ON rr.review_id = rv.id AND rr.checklist_item_id = i.id AND rr.passed = true
                    WHERE i.checklist_version_id = rv.checklist_version_id
                      AND i.required = true AND rr.id IS NULL;
                    IF missing_required > 0 THEN
                        RAISE EXCEPTION 'HUMAN_APPROVAL_CHECKLIST_INCOMPLETE';
                    END IF;
                    RETURN;
                END IF;

                IF a.kind = 'ai' THEN
                    SELECT ae.request_id, ae.stage, ae.status, ae.audit_report,
                           NULLIF(ae.input_manifest->>'source_version_id', '')::bigint
                    INTO audit_request_id, audit_stage, audit_status, audit_report_value, audit_source_version_id
                    FROM ai_executions ae WHERE ae.id = a.ai_execution_id;
                    IF audit_request_id IS DISTINCT FROM a.request_id
                       OR audit_stage IS DISTINCT FROM 'audit'
                       OR audit_status IS DISTINCT FROM 'succeeded'
                       OR audit_report_value IS NULL
                       OR NOT audit_result_v1_is_valid(audit_report_value)
                       OR (audit_report_value->>'passed')::boolean IS DISTINCT FROM true
                       OR audit_source_version_id IS DISTINCT FROM a.version_id THEN
                        RAISE EXCEPTION 'AI_APPROVAL_AUDIT_MISMATCH';
                    END IF;
                END IF;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_request_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                r planning_requests;
                current_version bigint;
                human_approval_id bigint;
                review_id bigint;
                reviewer_id bigint;
            BEGIN
                SELECT * INTO r FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND OR r.human_review_required_snapshot IS DISTINCT FROM true THEN RETURN; END IF;
                IF r.status NOT IN ('APROBADA','GENERANDO_DOCUMENTO','LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA') THEN RETURN; END IF;

                SELECT current_version_id INTO current_version FROM documents WHERE request_id = r.id;
                SELECT ap.id, ap.review_id, rv.reviewer_id
                INTO human_approval_id, review_id, reviewer_id
                FROM approvals ap
                JOIN reviews rv ON rv.id = ap.review_id
                WHERE ap.request_id = r.id AND ap.version_id = current_version AND ap.kind = 'human'
                  AND rv.status = 'approved'
                ORDER BY ap.id DESC LIMIT 1;
                IF human_approval_id IS NULL THEN
                    RAISE EXCEPTION 'HUMAN_APPROVAL_REQUIRED';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM request_state_events e
                    WHERE e.request_id = r.id
                      AND e.from_status = 'REVISION_HUMANA'
                      AND e.to_status = 'APROBADA'
                      AND e.reason = 'human_review_approved'
                      AND e.actor_id = reviewer_id
                ) THEN
                    RAISE EXCEPTION 'HUMAN_APPROVAL_STATE_EVENT_REQUIRED';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION human_review_request_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'planning_requests' THEN target_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'approvals' THEN target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'reviews' THEN target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'review_assignments' THEN target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'request_state_events' THEN target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'documents' THEN target_id := NEW.request_id;
                END IF;
                IF target_id IS NOT NULL THEN PERFORM human_review_request_integrity_check(target_id); END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_request_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_approval_trg AFTER INSERT ON approvals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_review_trg AFTER INSERT OR UPDATE ON reviews DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_assignment_trg AFTER UPDATE ON review_assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_event_trg AFTER INSERT ON request_state_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER human_review_request_document_trg AFTER UPDATE ON documents DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION human_review_request_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_document_trg ON documents');
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_event_trg ON request_state_events');
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_assignment_trg ON review_assignments');
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_review_trg ON reviews');
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_approval_trg ON approvals');
        DB::statement('DROP TRIGGER IF EXISTS human_review_request_request_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS human_review_request_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS human_review_request_integrity_check(bigint)');

        DB::statement('DROP TRIGGER IF EXISTS approvals_review_integrity_trg ON approvals');
        DB::statement('DROP TRIGGER IF EXISTS review_assignments_review_integrity_trg ON review_assignments');
        DB::statement('DROP TRIGGER IF EXISTS review_responses_integrity_trg ON review_checklist_responses');
        DB::statement('DROP TRIGGER IF EXISTS reviews_integrity_trg ON reviews');
        DB::statement('DROP FUNCTION IF EXISTS human_review_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS human_review_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS review_checklist_responses_guard_trg ON review_checklist_responses');
        DB::statement('DROP FUNCTION IF EXISTS human_review_response_guard()');
        DB::statement('DROP TRIGGER IF EXISTS reviews_history_trg ON reviews');
        DB::statement('DROP FUNCTION IF EXISTS human_review_history_guard()');
        DB::statement('DROP TRIGGER IF EXISTS review_checklist_items_guard_trg ON review_checklist_items');
        DB::statement('DROP FUNCTION IF EXISTS review_checklist_item_guard()');
        DB::statement('DROP TRIGGER IF EXISTS review_checklist_versions_guard_trg ON review_checklist_versions');
        DB::statement('DROP FUNCTION IF EXISTS review_checklist_version_guard()');

        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_review_fk');
        Schema::dropIfExists('review_checklist_responses');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_checklist_items');
        Schema::dropIfExists('review_checklist_versions');

        // Restaurar frontera temporal de 4E para rollback consistente.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                a approvals;
                version_request_id bigint;
                audit_request_id bigint;
                audit_stage text;
                audit_status text;
                audit_report_value jsonb;
                audit_source_version_id bigint;
            BEGIN
                SELECT * INTO a FROM approvals WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;
                SELECT d.request_id INTO version_request_id
                FROM document_versions dv JOIN documents d ON d.id = dv.document_id
                WHERE dv.id = a.version_id;
                IF version_request_id IS DISTINCT FROM a.request_id THEN
                    RAISE EXCEPTION 'APPROVAL_VERSION_REQUEST_MISMATCH';
                END IF;
                IF a.kind = 'human' THEN RAISE EXCEPTION 'HUMAN_APPROVAL_REVIEW_NOT_READY'; END IF;
                IF a.kind = 'ai' THEN
                    SELECT ae.request_id, ae.stage, ae.status, ae.audit_report,
                           NULLIF(ae.input_manifest->>'source_version_id', '')::bigint
                    INTO audit_request_id, audit_stage, audit_status, audit_report_value, audit_source_version_id
                    FROM ai_executions ae WHERE ae.id = a.ai_execution_id;
                    IF audit_request_id IS DISTINCT FROM a.request_id
                       OR audit_stage IS DISTINCT FROM 'audit'
                       OR audit_status IS DISTINCT FROM 'succeeded'
                       OR audit_report_value IS NULL
                       OR NOT audit_result_v1_is_valid(audit_report_value)
                       OR (audit_report_value->>'passed')::boolean IS DISTINCT FROM true
                       OR audit_source_version_id IS DISTINCT FROM a.version_id THEN
                        RAISE EXCEPTION 'AI_APPROVAL_AUDIT_MISMATCH';
                    END IF;
                END IF;
            END;
            $$;
        SQL);
    }
};
