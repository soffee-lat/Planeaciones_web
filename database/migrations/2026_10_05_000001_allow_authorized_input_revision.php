<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_commercial_integrity_check(target_id bigint) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE
                parent planning_requests;
                period subscription_periods;
                version plan_versions;
                segments jsonb;
                snapshot jsonb;
                input_revision_allowed boolean;
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND THEN RETURN; END IF;

                IF parent.commercial_authorized_at IS NULL THEN
                    IF parent.status NOT IN ('BORRADOR', 'ESPERANDO_INFORMACION', 'ESPERANDO_PAGO', 'CANCELADA') THEN
                        RAISE EXCEPTION 'PLANNING_COMMERCIAL_AUTHORIZATION_REQUIRED';
                    END IF;
                    RETURN;
                END IF;

                input_revision_allowed := parent.status = 'ESPERANDO_INFORMACION'
                    AND EXISTS (
                        SELECT 1
                        FROM request_blocks
                        WHERE request_id = parent.id
                          AND code = 'ai_quality_attention'
                          AND stage = 'audit'
                          AND resolved_at IS NULL
                          AND details->>'reason' = 'AI_CORRECTION_INPUT_REVISION_REQUIRED'
                    );

                SELECT * INTO period FROM subscription_periods WHERE id = parent.subscription_period_id;
                SELECT * INTO version FROM plan_versions WHERE id = parent.plan_version_id;
                snapshot := parent.calculation_snapshot;

                IF parent.input_snapshot IS NULL
                    OR parent.current_version_id IS NULL
                    OR (parent.curriculum_confirmed_at IS NULL AND NOT input_revision_allowed)
                    OR NOT EXISTS (
                        SELECT 1
                        FROM request_input_versions
                        WHERE id = parent.current_version_id
                          AND request_id = parent.id
                    )
                    OR parent.starts_on IS NULL
                    OR parent.ends_on IS NULL THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_INPUT';
                END IF;

                IF snapshot->'schema_version' IS DISTINCT FROM '1'::jsonb
                    OR snapshot#>'{subscription,id}' IS DISTINCT FROM to_jsonb(parent.subscription_id)
                    OR snapshot#>'{subscription,customer_id}' IS DISTINCT FROM to_jsonb(parent.owner_id)
                    OR snapshot#>'{subscription_period,id}' IS DISTINCT FROM to_jsonb(parent.subscription_period_id)
                    OR snapshot#>'{plan,id}' IS DISTINCT FROM to_jsonb(version.plan_id)
                    OR snapshot#>'{plan_version,id}' IS DISTINCT FROM to_jsonb(parent.plan_version_id)
                    OR snapshot#>'{plan_version,number}' IS DISTINCT FROM to_jsonb(version.number)
                    OR snapshot#>'{plan_version,checksum}' IS DISTINCT FROM to_jsonb(parent.plan_version_id::text::jsonb)
                    OR snapshot->'planning_days' IS DISTINCT FROM to_jsonb(parent.planning_days)
                    OR snapshot->'planning_units' IS DISTINCT FROM to_jsonb(parent.planning_units)
                    OR snapshot->'strategy' IS DISTINCT FROM to_jsonb(parent.calculation_strategy)
                    OR snapshot->'starts_on' IS DISTINCT FROM to_jsonb(parent.starts_on)
                    OR snapshot->'ends_on' IS DISTINCT FROM to_jsonb(parent.ends_on)
                    OR snapshot->'max_planning_days' IS DISTINCT FROM to_jsonb(version.max_planning_days)
                    OR snapshot->'entitlements' IS DISTINCT FROM period.entitlement_snapshot
                    OR period.entitlement_snapshot->'human_review_required' IS DISTINCT FROM to_jsonb(parent.human_review_required_snapshot)
                    OR period.entitlement_snapshot->'correction_limit' IS DISTINCT FROM to_jsonb(parent.correction_limit_snapshot)
                    OR period.entitlement_snapshot->'plan_version_id' IS DISTINCT FROM to_jsonb(parent.plan_version_id) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_SNAPSHOT';
                END IF;

                SELECT jsonb_agg(
                    jsonb_build_object(
                        'sequence', sequence,
                        'starts_on', starts_on,
                        'ends_on', ends_on,
                        'calendar_days', calendar_days,
                        'units', units
                    )
                    ORDER BY sequence
                )
                INTO segments
                FROM planning_request_segments
                WHERE planning_request_id = parent.id;

                IF segments IS NULL
                    OR segments IS DISTINCT FROM snapshot->'segments'
                    OR parent.planning_days <> parent.ends_on - parent.starts_on + 1
                    OR parent.planning_units <> ceil(parent.planning_days::numeric / version.max_planning_days)
                    OR (SELECT count(*) FROM planning_request_segments WHERE planning_request_id = parent.id) <> parent.planning_units
                    OR (SELECT sum(calendar_days) FROM planning_request_segments WHERE planning_request_id = parent.id) <> parent.planning_days
                    OR EXISTS (
                        SELECT 1
                        FROM planning_request_segments
                        WHERE planning_request_id = parent.id
                          AND (
                            calendar_days > version.max_planning_days
                            OR starts_on <> parent.starts_on + (sequence - 1) * version.max_planning_days
                            OR ends_on <> LEAST(parent.ends_on, parent.starts_on + sequence * version.max_planning_days - 1)
                          )
                    ) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_SEGMENTS';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM usage_reservations
                    WHERE planning_request_id = parent.id
                      AND subscription_period_id = parent.subscription_period_id
                      AND resource = 'planning'
                      AND quantity = parent.planning_units
                      AND status IN ('reserved', 'consumed')
                      AND operation_key = 'planning-request:' || parent.id || ':planning'
                ) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_PLANNING_RESERVATION_REQUIRED';
                END IF;

                IF parent.human_review_required_snapshot
                    AND NOT EXISTS (
                        SELECT 1
                        FROM usage_reservations
                        WHERE planning_request_id = parent.id
                          AND subscription_period_id = parent.subscription_period_id
                          AND resource = 'human_review'
                          AND quantity = parent.planning_units
                          AND status IN ('reserved', 'consumed')
                          AND operation_key = 'planning-request:' || parent.id || ':human-review'
                    ) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_HUMAN_RESERVATION_REQUIRED';
                END IF;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_commercial_integrity_check(target_id bigint) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE parent planning_requests; period subscription_periods; version plan_versions; segments jsonb; snapshot jsonb;
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND THEN RETURN; END IF;
                IF parent.commercial_authorized_at IS NULL THEN
                    IF parent.status NOT IN ('BORRADOR', 'ESPERANDO_INFORMACION', 'ESPERANDO_PAGO', 'CANCELADA') THEN
                        RAISE EXCEPTION 'PLANNING_COMMERCIAL_AUTHORIZATION_REQUIRED';
                    END IF;
                    RETURN;
                END IF;
                SELECT * INTO period FROM subscription_periods WHERE id = parent.subscription_period_id;
                SELECT * INTO version FROM plan_versions WHERE id = parent.plan_version_id;
                snapshot := parent.calculation_snapshot;
                IF parent.input_snapshot IS NULL OR parent.current_version_id IS NULL OR parent.curriculum_confirmed_at IS NULL
                    OR NOT EXISTS (SELECT 1 FROM request_input_versions WHERE id = parent.current_version_id AND request_id = parent.id)
                    OR parent.starts_on IS NULL OR parent.ends_on IS NULL THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_INPUT';
                END IF;
                IF snapshot->'schema_version' IS DISTINCT FROM '1'::jsonb
                    OR snapshot#>'{subscription,id}' IS DISTINCT FROM to_jsonb(parent.subscription_id)
                    OR snapshot#>'{subscription,customer_id}' IS DISTINCT FROM to_jsonb(parent.owner_id)
                    OR snapshot#>'{subscription_period,id}' IS DISTINCT FROM to_jsonb(parent.subscription_period_id)
                    OR snapshot#>'{plan,id}' IS DISTINCT FROM to_jsonb(version.plan_id)
                    OR snapshot#>'{plan_version,id}' IS DISTINCT FROM to_jsonb(parent.plan_version_id)
                    OR snapshot#>'{plan_version,number}' IS DISTINCT FROM to_jsonb(version.number)
                    OR snapshot#>'{plan_version,checksum}' IS DISTINCT FROM to_jsonb(version.checksum)
                    OR snapshot->'planning_days' IS DISTINCT FROM to_jsonb(parent.planning_days)
                    OR snapshot->'planning_units' IS DISTINCT FROM to_jsonb(parent.planning_units)
                    OR snapshot->'strategy' IS DISTINCT FROM to_jsonb(parent.calculation_strategy)
                    OR snapshot->'starts_on' IS DISTINCT FROM to_jsonb(parent.starts_on)
                    OR snapshot->'ends_on' IS DISTINCT FROM to_jsonb(parent.ends_on)
                    OR snapshot->'max_planning_days' IS DISTINCT FROM to_jsonb(version.max_planning_days)
                    OR snapshot->'entitlements' IS DISTINCT FROM period.entitlement_snapshot
                    OR period.entitlement_snapshot->'human_review_required' IS DISTINCT FROM to_jsonb(parent.human_review_required_snapshot)
                    OR period.entitlement_snapshot->'correction_limit' IS DISTINCT FROM to_jsonb(parent.correction_limit_snapshot)
                    OR period.entitlement_snapshot->'plan_version_id' IS DISTINCT FROM to_jsonb(parent.plan_version_id) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_SNAPSHOT';
                END IF;
                SELECT jsonb_agg(jsonb_build_object('sequence', sequence, 'starts_on', starts_on, 'ends_on', ends_on, 'calendar_days', calendar_days, 'units', units) ORDER BY sequence)
                    INTO segments FROM planning_request_segments WHERE planning_request_id = parent.id;
                IF segments IS NULL OR segments IS DISTINCT FROM snapshot->'segments'
                    OR parent.planning_days <> parent.ends_on - parent.starts_on + 1
                    OR parent.planning_units <> ceil(parent.planning_days::numeric / version.max_planning_days)
                    OR (SELECT count(*) FROM planning_request_segments WHERE planning_request_id = parent.id) <> parent.planning_units
                    OR (SELECT sum(calendar_days) FROM planning_request_segments WHERE planning_request_id = parent.id) <> parent.planning_days
                    OR EXISTS (SELECT 1 FROM planning_request_segments WHERE planning_request_id = parent.id AND
                        (calendar_days > version.max_planning_days OR starts_on <> parent.starts_on + (sequence - 1) * version.max_planning_days
                         OR ends_on <> LEAST(parent.ends_on, parent.starts_on + sequence * version.max_planning_days - 1))) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_SEGMENTS';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM usage_reservations WHERE planning_request_id = parent.id
                    AND subscription_period_id = parent.subscription_period_id AND resource = 'planning'
                    AND quantity = parent.planning_units AND status IN ('reserved', 'consumed')
                    AND operation_key = 'planning-request:' || parent.id || ':planning') THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_PLANNING_RESERVATION_REQUIRED';
                END IF;
                IF parent.human_review_required_snapshot AND NOT EXISTS (SELECT 1 FROM usage_reservations WHERE planning_request_id = parent.id
                    AND subscription_period_id = parent.subscription_period_id AND resource = 'human_review'
                    AND quantity = parent.planning_units AND status IN ('reserved', 'consumed')
                    AND operation_key = 'planning-request:' || parent.id || ':human-review') THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_HUMAN_RESERVATION_REQUIRED';
                END IF;
            END; $$;
        SQL);
    }
};
