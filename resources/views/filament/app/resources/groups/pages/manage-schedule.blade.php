<x-filament-panels::page>
    <div
        x-data="scheduleEditor({
            hasSchedule: @js($hasSchedule),
            dayStart: @js($dayStartsAt),
            dayEnd: @js($dayEndsAt),
            defaultDuration: @js($defaultBlockMinutes),
            fieldOptions: @js($fieldOptions),
            subjects: @js($subjectCatalog),
            printMeta: @js($printMeta),
            initialBlocks: @js($blocks)
        })"
        class="space-y-5"
    >
        <style>
            [x-cloak] { display: none !important; }

            .schedule-onboarding-wrap {
                max-width: 980px;
                margin: 1.25rem auto 0;
            }

            .schedule-onboarding-card {
                overflow: hidden;
                border: 1px solid #e5e7eb;
                border-radius: 24px;
                background: #ffffff;
                box-shadow:
                    0 1px 2px rgb(15 23 42 / .04),
                    0 12px 36px rgb(15 23 42 / .08);
                color: #0f172a;
            }

            .schedule-onboarding-hero {
                position: relative;
                padding: 30px 32px 26px;
                border-bottom: 1px solid #eef2f7;
                background:
                    radial-gradient(circle at 92% 18%, rgb(59 130 246 / .16), transparent 28%),
                    linear-gradient(135deg, #f8fbff 0%, #ffffff 62%);
            }

            .schedule-onboarding-kicker {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                margin-bottom: .85rem;
                padding: .38rem .7rem;
                border-radius: 999px;
                background: #eff6ff;
                color: #2563eb;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .055em;
                text-transform: uppercase;
            }

            .schedule-onboarding-title {
                margin: 0;
                max-width: 680px;
                color: #0f172a;
                font-size: clamp(1.65rem, 3vw, 2.15rem);
                line-height: 1.1;
                font-weight: 800;
                letter-spacing: -.035em;
            }

            .schedule-onboarding-copy {
                max-width: 680px;
                margin-top: .75rem;
                color: #64748b;
                font-size: .95rem;
                line-height: 1.55;
            }

            .schedule-onboarding-body {
                padding: 28px 32px 30px;
            }

            .schedule-step-label {
                display: flex;
                align-items: center;
                gap: .65rem;
                margin-bottom: 14px;
                color: #334155;
                font-size: .8rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }

            .schedule-step-number {
                display: inline-grid;
                width: 28px;
                height: 28px;
                place-items: center;
                border-radius: 9px;
                background: #eff6ff;
                color: #2563eb;
                font-size: .75rem;
                font-weight: 900;
            }

            .schedule-time-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
            }

            .schedule-time-card {
                display: grid;
                grid-template-columns: 42px minmax(0, 1fr);
                align-items: center;
                gap: 12px;
                min-height: 82px;
                padding: 14px 16px;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #f8fafc;
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
            }

            .schedule-time-card:focus-within {
                border-color: #60a5fa;
                box-shadow: 0 0 0 4px rgb(59 130 246 / .10);
                transform: translateY(-1px);
            }

            .schedule-time-icon {
                display: grid;
                width: 42px;
                height: 42px;
                place-items: center;
                border-radius: 12px;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                color: #2563eb;
                box-shadow: 0 2px 8px rgb(15 23 42 / .05);
            }

            .schedule-time-meta {
                display: block;
                margin-bottom: 2px;
                color: #64748b;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }

            .schedule-time-input {
                width: 100%;
                padding: 0;
                border: 0;
                outline: 0;
                background: transparent;
                color: #0f172a;
                font-size: 1.15rem;
                font-weight: 800;
                box-shadow: none;
            }

            .schedule-recess-card {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 18px;
                margin-top: 16px;
                padding: 17px 18px;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #ffffff;
            }

            .schedule-recess-title {
                color: #0f172a;
                font-size: .92rem;
                font-weight: 750;
            }

            .schedule-recess-copy {
                margin-top: 3px;
                color: #64748b;
                font-size: .78rem;
                line-height: 1.45;
            }

            .schedule-switch {
                position: relative;
                width: 46px;
                height: 26px;
                flex: 0 0 auto;
            }

            .schedule-switch input {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }

            .schedule-switch-track {
                position: absolute;
                inset: 0;
                border-radius: 999px;
                background: #cbd5e1;
                cursor: pointer;
                transition: background .2s ease;
            }

            .schedule-switch-track::after {
                content: '';
                position: absolute;
                top: 3px;
                left: 3px;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 4px rgb(15 23 42 / .22);
                transition: transform .2s ease;
            }

            .schedule-switch input:checked + .schedule-switch-track {
                background: #2563eb;
            }

            .schedule-switch input:checked + .schedule-switch-track::after {
                transform: translateX(20px);
            }

            .schedule-recess-times {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                margin-top: 12px;
                padding: 14px;
                border-radius: 14px;
                background: #f8fafc;
            }

            .schedule-mini-label {
                display: block;
                margin-bottom: 6px;
                color: #64748b;
                font-size: .7rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .04em;
            }

            .schedule-mini-input {
                width: 100%;
                padding: 9px 11px;
                border: 1px solid #dbe2ea;
                border-radius: 10px;
                background: #fff;
                color: #0f172a;
                font-weight: 700;
                outline: none;
            }

            .schedule-onboarding-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-top: 24px;
                padding-top: 22px;
                border-top: 1px solid #eef2f7;
            }

            .schedule-footer-note {
                color: #64748b;
                font-size: .78rem;
                line-height: 1.45;
            }

            .schedule-primary-action {
                min-width: 190px;
                padding: 12px 18px;
                border: 0;
                border-radius: 12px;
                background: linear-gradient(135deg, #2563eb, #3b82f6);
                color: #fff;
                font-size: .9rem;
                font-weight: 800;
                cursor: pointer;
                box-shadow: 0 8px 22px rgb(37 99 235 / .26);
                transition: transform .15s ease, box-shadow .15s ease;
            }

            .schedule-primary-action:hover {
                transform: translateY(-1px);
                box-shadow: 0 10px 28px rgb(37 99 235 / .32);
            }

            .schedule-secondary-action {
                padding: 11px 16px;
                border: 1px solid #dbe2ea;
                border-radius: 12px;
                background: transparent;
                color: #475569;
                font-size: .85rem;
                font-weight: 700;
                cursor: pointer;
            }

            .schedule-error {
                margin-top: 12px;
                padding: 10px 12px;
                border-radius: 10px;
                background: #fef2f2;
                color: #b91c1c;
                font-size: .8rem;
                font-weight: 700;
            }

            html.dark .schedule-onboarding-card,
            .dark .schedule-onboarding-card {
                border-color: rgb(255 255 255 / .09);
                background: #111827;
                color: #f8fafc;
                box-shadow: 0 18px 46px rgb(0 0 0 / .28);
            }

            html.dark .schedule-onboarding-hero,
            .dark .schedule-onboarding-hero {
                border-bottom-color: rgb(255 255 255 / .08);
                background:
                    radial-gradient(circle at 92% 18%, rgb(59 130 246 / .20), transparent 28%),
                    linear-gradient(135deg, #111827 0%, #0f172a 100%);
            }

            html.dark .schedule-onboarding-title,
            .dark .schedule-onboarding-title,
            html.dark .schedule-recess-title,
            .dark .schedule-recess-title {
                color: #f8fafc;
            }

            html.dark .schedule-onboarding-copy,
            .dark .schedule-onboarding-copy,
            html.dark .schedule-recess-copy,
            .dark .schedule-recess-copy,
            html.dark .schedule-footer-note,
            .dark .schedule-footer-note,
            html.dark .schedule-time-meta,
            .dark .schedule-time-meta,
            html.dark .schedule-mini-label,
            .dark .schedule-mini-label {
                color: #94a3b8;
            }

            html.dark .schedule-step-label,
            .dark .schedule-step-label {
                color: #cbd5e1;
            }

            html.dark .schedule-time-card,
            .dark .schedule-time-card {
                border-color: rgb(255 255 255 / .09);
                background: #0b1220;
            }

            html.dark .schedule-time-icon,
            .dark .schedule-time-icon {
                border-color: rgb(255 255 255 / .09);
                background: #111827;
                color: #60a5fa;
                box-shadow: none;
            }

            html.dark .schedule-time-input,
            .dark .schedule-time-input,
            html.dark .schedule-mini-input,
            .dark .schedule-mini-input {
                color: #f8fafc;
                color-scheme: dark;
            }

            html.dark .schedule-recess-card,
            .dark .schedule-recess-card {
                border-color: rgb(255 255 255 / .09);
                background: #0b1220;
            }

            html.dark .schedule-recess-times,
            .dark .schedule-recess-times {
                background: #111827;
            }

            html.dark .schedule-mini-input,
            .dark .schedule-mini-input {
                border-color: rgb(255 255 255 / .10);
                background: #0b1220;
            }

            html.dark .schedule-onboarding-footer,
            .dark .schedule-onboarding-footer {
                border-top-color: rgb(255 255 255 / .08);
            }

            html.dark .schedule-secondary-action,
            .dark .schedule-secondary-action {
                border-color: rgb(255 255 255 / .10);
                color: #cbd5e1;
            }

            @media (max-width: 720px) {
                .schedule-onboarding-wrap {
                    margin-top: .5rem;
                }
                .schedule-onboarding-hero,
                .schedule-onboarding-body {
                    padding-left: 18px;
                    padding-right: 18px;
                }
                .schedule-time-grid,
                .schedule-recess-times {
                    grid-template-columns: 1fr;
                }
                .schedule-onboarding-footer {
                    align-items: stretch;
                    flex-direction: column;
                }
                .schedule-primary-action,
                .schedule-secondary-action {
                    width: 100%;
                }
            }
            .schedule-canvas {
                background-image: repeating-linear-gradient(
                    to bottom,
                    transparent 0,
                    transparent calc(var(--half-hour) - 1px),
                    rgb(148 163 184 / .18) calc(var(--half-hour) - 1px),
                    rgb(148 163 184 / .18) var(--half-hour)
                );
            }
            .dark .schedule-canvas {
                background-image: repeating-linear-gradient(
                    to bottom,
                    transparent 0,
                    transparent calc(var(--half-hour) - 1px),
                    rgb(255 255 255 / .08) calc(var(--half-hour) - 1px),
                    rgb(255 255 255 / .08) var(--half-hour)
                );
            }

            /* Workspace del horario: estilos encapsulados para no depender de recompilar Tailwind. */
            .schedule-workspace {
                max-width: 1480px;
                margin: 0 auto;
            }

            .schedule-toolbar {
                display: flex;
                align-items: center;
                gap: 18px;
                padding: 18px 20px;
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                background: #ffffff;
                box-shadow: 0 8px 28px rgb(15 23 42 / .06);
            }

            .schedule-toolbar-main {
                min-width: 0;
                flex: 1;
            }

            .schedule-toolbar-eyebrow {
                color: #64748b;
                font-size: .68rem;
                font-weight: 800;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .schedule-toolbar-time {
                display: flex;
                align-items: center;
                gap: 9px;
                margin-top: 4px;
                color: #0f172a;
                font-size: 1.2rem;
                font-weight: 850;
                letter-spacing: -.02em;
            }

            .schedule-toolbar-arrow {
                color: #94a3b8;
                font-weight: 600;
            }

            .schedule-count-badge,
            .schedule-unsaved-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 10px;
                border-radius: 999px;
                font-size: .72rem;
                font-weight: 800;
                white-space: nowrap;
            }

            .schedule-count-badge {
                background: #eff6ff;
                color: #2563eb;
            }

            .schedule-unsaved-badge {
                background: #fff7ed;
                color: #c2410c;
            }

            .schedule-toolbar-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .schedule-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 40px;
                padding: 9px 14px;
                border-radius: 11px;
                font-size: .8rem;
                font-weight: 800;
                cursor: pointer;
                transition: transform .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
            }

            .schedule-button:hover {
                transform: translateY(-1px);
            }

            .schedule-button-secondary {
                border: 1px solid #dbe2ea;
                background: #ffffff;
                color: #475569;
            }

            .schedule-button-primary {
                border: 1px solid #2563eb;
                background: linear-gradient(135deg, #2563eb, #3b82f6);
                color: #ffffff;
                box-shadow: 0 7px 18px rgb(37 99 235 / .22);
            }

            .schedule-tip {
                display: flex;
                align-items: center;
                gap: 11px;
                margin-top: 12px;
                padding: 11px 14px;
                border: 1px solid #dbeafe;
                border-radius: 13px;
                background: #eff6ff;
                color: #1e40af;
                font-size: .78rem;
                line-height: 1.45;
            }

            .schedule-tip-icon {
                display: grid;
                width: 28px;
                height: 28px;
                flex: 0 0 auto;
                place-items: center;
                border-radius: 9px;
                background: #ffffff;
                color: #2563eb;
                font-size: 1.05rem;
                font-weight: 900;
                box-shadow: 0 2px 8px rgb(37 99 235 / .10);
            }

            .schedule-board {
                overflow: hidden;
                margin-top: 16px;
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                background: #ffffff;
                box-shadow: 0 12px 34px rgb(15 23 42 / .07);
            }

            .schedule-board-scroll {
                overflow-x: auto;
            }

            .schedule-board-inner {
                min-width: 980px;
            }

            .schedule-board-header {
                display: grid;
                grid-template-columns: 78px repeat(5, minmax(0, 1fr));
                border-bottom: 1px solid #e2e8f0;
                background: #f8fafc;
            }

            .schedule-time-head {
                display: grid;
                place-items: center;
                border-right: 1px solid #e2e8f0;
                color: #94a3b8;
                font-size: .66rem;
                font-weight: 800;
                letter-spacing: .05em;
                text-transform: uppercase;
            }

            .schedule-day-head {
                position: relative;
                padding: 13px 10px 12px;
                border-right: 1px solid #e2e8f0;
                text-align: center;
            }

            .schedule-day-head:last-child {
                border-right: 0;
            }

            .schedule-day-name {
                color: #0f172a;
                font-size: .86rem;
                font-weight: 850;
            }

            .schedule-day-add {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                margin-top: 5px;
                border: 0;
                background: transparent;
                color: #2563eb;
                font-size: .7rem;
                font-weight: 800;
                cursor: pointer;
            }

            .schedule-board-body {
                display: grid;
                grid-template-columns: 78px repeat(5, minmax(0, 1fr));
            }

            .schedule-time-column {
                position: relative;
                border-right: 1px solid #e2e8f0;
                background: #f8fafc;
            }

            .schedule-time-label {
                position: absolute;
                right: 11px;
                transform: translateY(-50%);
                color: #64748b;
                font-size: .68rem;
                font-weight: 750;
                font-variant-numeric: tabular-nums;
            }

            .schedule-day-column {
                position: relative;
                border-right: 1px solid #e2e8f0;
                cursor: crosshair;
                background-color: #ffffff;
                background-image:
                    repeating-linear-gradient(
                        to bottom,
                        transparent 0,
                        transparent calc(var(--half-hour) - 1px),
                        #edf2f7 calc(var(--half-hour) - 1px),
                        #edf2f7 var(--half-hour)
                    );
                transition: background-color .15s ease;
            }

            .schedule-day-column:last-child {
                border-right: 0;
            }

            .schedule-day-column:hover {
                background-color: #fbfdff;
            }

            .schedule-empty-hint {
                position: absolute;
                left: 50%;
                top: 50%;
                width: 130px;
                transform: translate(-50%, -50%);
                color: #94a3b8;
                font-size: .72rem;
                font-weight: 700;
                line-height: 1.4;
                text-align: center;
                pointer-events: none;
            }

            .schedule-empty-plus {
                display: grid;
                width: 32px;
                height: 32px;
                margin: 0 auto 7px;
                place-items: center;
                border: 1px dashed #cbd5e1;
                border-radius: 10px;
                color: #64748b;
                font-size: 1rem;
                background: rgb(255 255 255 / .8);
            }

            .schedule-block {
                position: absolute;
                left: 7px;
                right: 7px;
                z-index: 2;
                overflow: hidden;
                padding: 8px 9px;
                border-radius: 11px;
                text-align: left;
                cursor: pointer;
                box-shadow: 0 4px 12px rgb(15 23 42 / .08);
                transition: transform .12s ease, box-shadow .12s ease;
            }

            .schedule-block:hover {
                transform: translateY(-1px);
                box-shadow: 0 8px 18px rgb(15 23 42 / .12);
            }

            .schedule-block-title {
                overflow: hidden;
                color: inherit;
                font-size: .76rem;
                font-weight: 850;
                line-height: 1.15;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .schedule-block-time {
                margin-top: 3px;
                color: inherit;
                font-size: .64rem;
                font-weight: 650;
                opacity: .75;
                font-variant-numeric: tabular-nums;
            }

            .schedule-block-class {
                border: 1px solid #bfdbfe;
                background: linear-gradient(135deg, #eff6ff, #dbeafe);
                color: #1e3a8a;
            }

            .schedule-block-flexible {
                border: 1px solid #c4b5fd;
                background: linear-gradient(135deg, #f5f3ff, #ede9fe);
                color: #5b21b6;
            }

            .schedule-block-break {
                border: 1px dashed #cbd5e1;
                background: #f8fafc;
                color: #475569;
            }

            .schedule-block-external {
                border: 1px solid #fed7aa;
                background: linear-gradient(135deg, #fff7ed, #ffedd5);
                color: #9a3412;
            }

            .schedule-board-footer {
                display: flex;
                align-items: center;
                gap: 9px;
                margin-top: 12px;
                padding: 11px 14px;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                color: #64748b;
                font-size: .72rem;
                line-height: 1.45;
                background: rgb(248 250 252 / .8);
            }

            .schedule-mobile {
                display: none;
            }

            .schedule-mobile-tabs {
                display: flex;
                gap: 7px;
                overflow-x: auto;
                padding-bottom: 8px;
            }

            .schedule-mobile-tab {
                flex: 0 0 auto;
                padding: 8px 13px;
                border: 1px solid #dbe2ea;
                border-radius: 999px;
                background: #fff;
                color: #475569;
                font-size: .76rem;
                font-weight: 800;
                cursor: pointer;
            }

            .schedule-mobile-tab-active {
                border-color: #2563eb;
                background: #2563eb;
                color: #fff;
            }

            .schedule-mobile-board {
                overflow: hidden;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #fff;
            }

            .schedule-mobile-head {
                padding: 12px 14px;
                border-bottom: 1px solid #e2e8f0;
                color: #0f172a;
                font-size: .84rem;
                font-weight: 850;
                text-align: center;
                background: #f8fafc;
            }

            .schedule-mobile-grid {
                display: grid;
                grid-template-columns: 66px minmax(0,1fr);
            }

            html.dark .schedule-toolbar,
            .dark .schedule-toolbar,
            html.dark .schedule-board,
            .dark .schedule-board,
            html.dark .schedule-mobile-board,
            .dark .schedule-mobile-board {
                border-color: rgb(255 255 255 / .09);
                background: #111827;
                box-shadow: 0 12px 36px rgb(0 0 0 / .22);
            }

            html.dark .schedule-toolbar-eyebrow,
            .dark .schedule-toolbar-eyebrow,
            html.dark .schedule-time-label,
            .dark .schedule-time-label,
            html.dark .schedule-board-footer,
            .dark .schedule-board-footer {
                color: #94a3b8;
            }

            html.dark .schedule-toolbar-time,
            .dark .schedule-toolbar-time,
            html.dark .schedule-day-name,
            .dark .schedule-day-name,
            html.dark .schedule-mobile-head,
            .dark .schedule-mobile-head {
                color: #f8fafc;
            }

            html.dark .schedule-count-badge,
            .dark .schedule-count-badge {
                background: rgb(59 130 246 / .13);
                color: #93c5fd;
            }

            html.dark .schedule-unsaved-badge,
            .dark .schedule-unsaved-badge {
                background: rgb(249 115 22 / .12);
                color: #fdba74;
            }

            html.dark .schedule-button-secondary,
            .dark .schedule-button-secondary {
                border-color: rgb(255 255 255 / .10);
                background: #0b1220;
                color: #cbd5e1;
            }

            html.dark .schedule-tip,
            .dark .schedule-tip {
                border-color: rgb(59 130 246 / .18);
                background: rgb(59 130 246 / .09);
                color: #bfdbfe;
            }

            html.dark .schedule-tip-icon,
            .dark .schedule-tip-icon {
                background: #0b1220;
                color: #60a5fa;
                box-shadow: none;
            }

            html.dark .schedule-board-header,
            .dark .schedule-board-header,
            html.dark .schedule-time-column,
            .dark .schedule-time-column,
            html.dark .schedule-mobile-head,
            .dark .schedule-mobile-head {
                border-color: rgb(255 255 255 / .08);
                background: #0b1220;
            }

            html.dark .schedule-time-head,
            .dark .schedule-time-head,
            html.dark .schedule-day-head,
            .dark .schedule-day-head,
            html.dark .schedule-day-column,
            .dark .schedule-day-column {
                border-color: rgb(255 255 255 / .08);
            }

            html.dark .schedule-day-column,
            .dark .schedule-day-column {
                background-color: #111827;
                background-image:
                    repeating-linear-gradient(
                        to bottom,
                        transparent 0,
                        transparent calc(var(--half-hour) - 1px),
                        rgb(255 255 255 / .07) calc(var(--half-hour) - 1px),
                        rgb(255 255 255 / .07) var(--half-hour)
                    );
            }

            html.dark .schedule-day-column:hover,
            .dark .schedule-day-column:hover {
                background-color: #131d2e;
            }

            html.dark .schedule-empty-plus,
            .dark .schedule-empty-plus {
                border-color: rgb(255 255 255 / .14);
                background: #0b1220;
                color: #94a3b8;
            }

            html.dark .schedule-empty-hint,
            .dark .schedule-empty-hint {
                color: #64748b;
            }

            html.dark .schedule-block-class,
            .dark .schedule-block-class {
                border-color: rgb(96 165 250 / .32);
                background: linear-gradient(135deg, rgb(37 99 235 / .27), rgb(59 130 246 / .14));
                color: #dbeafe;
            }

            html.dark .schedule-block-flexible,
            .dark .schedule-block-flexible {
                border-color: rgb(167 139 250 / .30);
                background: linear-gradient(135deg, rgb(124 58 237 / .24), rgb(139 92 246 / .12));
                color: #ede9fe;
            }

            html.dark .schedule-block-break,
            .dark .schedule-block-break {
                border-color: rgb(148 163 184 / .30);
                background: rgb(148 163 184 / .08);
                color: #cbd5e1;
            }

            html.dark .schedule-block-external,
            .dark .schedule-block-external {
                border-color: rgb(251 146 60 / .30);
                background: linear-gradient(135deg, rgb(194 65 12 / .20), rgb(249 115 22 / .10));
                color: #fed7aa;
            }

            html.dark .schedule-board-footer,
            .dark .schedule-board-footer {
                border-color: rgb(255 255 255 / .08);
                background: rgb(255 255 255 / .025);
            }

            html.dark .schedule-mobile-tab,
            .dark .schedule-mobile-tab {
                border-color: rgb(255 255 255 / .10);
                background: #111827;
                color: #cbd5e1;
            }

            html.dark .schedule-mobile-tab-active,
            .dark .schedule-mobile-tab-active {
                border-color: #3b82f6;
                background: #2563eb;
                color: #fff;
            }

            @media (max-width: 900px) {
                .schedule-toolbar {
                    align-items: flex-start;
                    flex-direction: column;
                }

                .schedule-toolbar-actions {
                    width: 100%;
                }

                .schedule-toolbar-actions .schedule-button {
                    flex: 1;
                }

                .schedule-desktop {
                    display: none;
                }

                .schedule-mobile {
                    display: block;
                }
            }

            @media (max-width: 560px) {
                .schedule-toolbar {
                    padding: 15px;
                    border-radius: 15px;
                }

                .schedule-toolbar-time {
                    flex-wrap: wrap;
                    font-size: 1.05rem;
                }

                .schedule-toolbar-actions {
                    flex-direction: column;
                }

                .schedule-toolbar-actions .schedule-button {
                    width: 100%;
                }
            }


            /* Drawer de edición de bloque */
            .schedule-editor-shell {
                position: fixed;
                inset: 0;
                z-index: 60;
                display: flex;
                justify-content: flex-end;
            }

            .schedule-editor-backdrop {
                position: absolute;
                inset: 0;
                border: 0;
                background: rgb(2 6 23 / .64);
                backdrop-filter: blur(3px);
                cursor: default;
            }

            .schedule-editor-panel {
                position: relative;
                z-index: 1;
                display: flex;
                width: min(520px, 100vw);
                height: 100%;
                flex-direction: column;
                border-left: 1px solid #e2e8f0;
                background: #ffffff;
                color: #0f172a;
                box-shadow: -18px 0 48px rgb(15 23 42 / .18);
            }

            .schedule-editor-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                padding: 20px 22px 17px;
                border-bottom: 1px solid #e2e8f0;
                background:
                    radial-gradient(circle at 90% 10%, rgb(59 130 246 / .10), transparent 32%),
                    #ffffff;
            }

            .schedule-editor-kicker {
                color: #2563eb;
                font-size: .68rem;
                font-weight: 900;
                letter-spacing: .075em;
                text-transform: uppercase;
            }

            .schedule-editor-title {
                margin-top: 4px;
                color: #0f172a;
                font-size: 1.15rem;
                line-height: 1.25;
                font-weight: 850;
                letter-spacing: -.02em;
            }

            .schedule-editor-close {
                display: grid;
                width: 34px;
                height: 34px;
                flex: 0 0 auto;
                place-items: center;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                background: #f8fafc;
                color: #64748b;
                font-size: 1rem;
                cursor: pointer;
            }

            .schedule-editor-body {
                flex: 1;
                overflow-y: auto;
                padding: 20px 22px 28px;
            }

            .schedule-editor-section + .schedule-editor-section {
                margin-top: 22px;
                padding-top: 20px;
                border-top: 1px solid #eef2f7;
            }

            .schedule-editor-label {
                display: block;
                margin-bottom: 7px;
                color: #334155;
                font-size: .78rem;
                font-weight: 850;
            }

            .schedule-editor-help {
                margin-top: 5px;
                color: #64748b;
                font-size: .71rem;
                line-height: 1.45;
            }

            .schedule-editor-input,
            .schedule-editor-select,
            .schedule-editor-textarea {
                width: 100%;
                border: 1px solid #dbe2ea;
                border-radius: 12px;
                background: #f8fafc;
                color: #0f172a;
                outline: 0;
                transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
            }

            .schedule-editor-input {
                min-height: 43px;
                padding: 10px 12px;
                font-size: .9rem;
                font-weight: 700;
            }

            .schedule-editor-input-name {
                min-height: 48px;
                font-size: 1rem;
                font-weight: 800;
            }

            .schedule-editor-select {
                min-height: 42px;
                padding: 9px 11px;
                font-size: .82rem;
                font-weight: 700;
            }

            .schedule-editor-textarea {
                min-height: 76px;
                padding: 10px 12px;
                resize: vertical;
                font-size: .82rem;
                line-height: 1.45;
            }

            .schedule-editor-input:focus,
            .schedule-editor-select:focus,
            .schedule-editor-textarea:focus {
                border-color: #60a5fa;
                background: #ffffff;
                box-shadow: 0 0 0 3px rgb(59 130 246 / .10);
            }

            .schedule-editor-chip-row {
                display: flex;
                flex-wrap: wrap;
                gap: 7px;
                margin-top: 10px;
            }

            .schedule-editor-chip {
                padding: 7px 10px;
                border: 1px solid #dbe2ea;
                border-radius: 999px;
                background: #ffffff;
                color: #475569;
                font-size: .7rem;
                font-weight: 800;
                cursor: pointer;
                transition: border-color .15s ease, color .15s ease, background .15s ease;
            }

            .schedule-editor-chip:hover {
                border-color: #93c5fd;
                background: #eff6ff;
                color: #1d4ed8;
            }

            .schedule-editor-time-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .schedule-editor-segmented {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
            }

            .schedule-editor-segment {
                min-height: 40px;
                padding: 8px 9px;
                border: 1px solid #dbe2ea;
                border-radius: 11px;
                background: #ffffff;
                color: #64748b;
                font-size: .72rem;
                font-weight: 850;
                cursor: pointer;
            }

            .schedule-editor-segment-active {
                border-color: #3b82f6;
                background: #eff6ff;
                color: #1d4ed8;
                box-shadow: 0 0 0 2px rgb(59 130 246 / .08);
            }

            .schedule-editor-toggle-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                padding: 13px 14px;
                border: 1px solid #e2e8f0;
                border-radius: 13px;
                background: #f8fafc;
            }

            .schedule-editor-toggle-title {
                color: #334155;
                font-size: .78rem;
                font-weight: 850;
            }

            .schedule-editor-toggle-copy {
                margin-top: 2px;
                color: #64748b;
                font-size: .68rem;
                line-height: 1.4;
            }

            .schedule-editor-field-list {
                display: grid;
                gap: 7px;
                margin-top: 10px;
            }

            .schedule-editor-field {
                display: flex;
                align-items: center;
                gap: 9px;
                padding: 9px 10px;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                background: #ffffff;
                color: #475569;
                font-size: .74rem;
                font-weight: 700;
                cursor: pointer;
            }

            .schedule-editor-more {
                border: 0;
                background: transparent;
                color: #2563eb;
                font-size: .74rem;
                font-weight: 850;
                cursor: pointer;
            }

            .schedule-editor-advanced {
                margin-top: 11px;
                padding: 13px;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #f8fafc;
            }

            .schedule-editor-copy-days {
                display: flex;
                flex-wrap: wrap;
                gap: 7px;
                margin-top: 9px;
            }

            .schedule-editor-notice {
                margin-top: 10px;
                padding: 9px 10px;
                border: 1px solid #fed7aa;
                border-radius: 10px;
                background: #fff7ed;
                color: #9a3412;
                font-size: .7rem;
                font-weight: 750;
                line-height: 1.4;
            }

            .schedule-editor-day-button-conflict {
                border-color: #e2e8f0;
                background: #f8fafc;
                color: #94a3b8;
                opacity: .55;
                cursor: not-allowed;
            }

            .schedule-editor-day-button-selected {
                border-color: #3b82f6;
                background: #eff6ff;
                color: #1d4ed8;
                box-shadow: 0 0 0 2px rgb(59 130 246 / .08);
            }

            html.dark .schedule-editor-day-button-selected,
            .dark .schedule-editor-day-button-selected {
                border-color: #3b82f6;
                background: rgb(59 130 246 / .14);
                color: #bfdbfe;
            }

            html.dark .schedule-editor-notice,
            .dark .schedule-editor-notice {
                border-color: rgb(251 146 60 / .25);
                background: rgb(249 115 22 / .10);
                color: #fdba74;
            }

            .schedule-editor-day-button {
                min-width: 42px;
                padding: 7px 9px;
                border: 1px solid #dbe2ea;
                border-radius: 9px;
                background: #fff;
                color: #475569;
                font-size: .68rem;
                font-weight: 850;
                cursor: pointer;
            }

            .schedule-editor-day-button:disabled {
                opacity: .32;
                cursor: not-allowed;
            }

            .schedule-editor-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 18px;
                border-top: 1px solid #e2e8f0;
                background: #ffffff;
                box-shadow: 0 -10px 24px rgb(15 23 42 / .04);
            }

            .schedule-editor-danger,
            .schedule-editor-done {
                min-height: 40px;
                padding: 9px 14px;
                border-radius: 10px;
                font-size: .78rem;
                font-weight: 850;
                cursor: pointer;
            }

            .schedule-editor-danger {
                border: 1px solid #fecaca;
                background: #fff1f2;
                color: #be123c;
            }

            .schedule-editor-done {
                min-width: 110px;
                border: 1px solid #2563eb;
                background: linear-gradient(135deg, #2563eb, #3b82f6);
                color: #fff;
                box-shadow: 0 7px 18px rgb(37 99 235 / .20);
            }

            html.dark .schedule-editor-panel,
            .dark .schedule-editor-panel {
                border-left-color: rgb(255 255 255 / .09);
                background: #0f172a;
                color: #f8fafc;
                box-shadow: -20px 0 52px rgb(0 0 0 / .38);
            }

            html.dark .schedule-editor-header,
            .dark .schedule-editor-header {
                border-bottom-color: rgb(255 255 255 / .08);
                background:
                    radial-gradient(circle at 90% 10%, rgb(59 130 246 / .14), transparent 32%),
                    #111827;
            }

            html.dark .schedule-editor-title,
            .dark .schedule-editor-title,
            html.dark .schedule-editor-label,
            .dark .schedule-editor-label,
            html.dark .schedule-editor-toggle-title,
            .dark .schedule-editor-toggle-title {
                color: #f8fafc;
            }

            html.dark .schedule-editor-help,
            .dark .schedule-editor-help,
            html.dark .schedule-editor-toggle-copy,
            .dark .schedule-editor-toggle-copy {
                color: #94a3b8;
            }

            html.dark .schedule-editor-close,
            .dark .schedule-editor-close {
                border-color: rgb(255 255 255 / .10);
                background: #0b1220;
                color: #94a3b8;
            }

            html.dark .schedule-editor-section + .schedule-editor-section,
            .dark .schedule-editor-section + .schedule-editor-section {
                border-top-color: rgb(255 255 255 / .07);
            }

            html.dark .schedule-editor-input,
            .dark .schedule-editor-input,
            html.dark .schedule-editor-select,
            .dark .schedule-editor-select,
            html.dark .schedule-editor-textarea,
            .dark .schedule-editor-textarea {
                border-color: rgb(255 255 255 / .10);
                background: #0b1220;
                color: #f8fafc;
                color-scheme: dark;
            }

            html.dark .schedule-editor-input:focus,
            .dark .schedule-editor-input:focus,
            html.dark .schedule-editor-select:focus,
            .dark .schedule-editor-select:focus,
            html.dark .schedule-editor-textarea:focus,
            .dark .schedule-editor-textarea:focus {
                border-color: #3b82f6;
                background: #111827;
            }

            html.dark .schedule-editor-chip,
            .dark .schedule-editor-chip,
            html.dark .schedule-editor-segment,
            .dark .schedule-editor-segment,
            html.dark .schedule-editor-field,
            .dark .schedule-editor-field,
            html.dark .schedule-editor-day-button,
            .dark .schedule-editor-day-button {
                border-color: rgb(255 255 255 / .10);
                background: #111827;
                color: #cbd5e1;
            }

            html.dark .schedule-editor-chip:hover,
            .dark .schedule-editor-chip:hover {
                border-color: rgb(96 165 250 / .35);
                background: rgb(59 130 246 / .10);
                color: #bfdbfe;
            }

            html.dark .schedule-editor-segment-active,
            .dark .schedule-editor-segment-active {
                border-color: #3b82f6;
                background: rgb(59 130 246 / .12);
                color: #bfdbfe;
            }

            html.dark .schedule-editor-toggle-card,
            .dark .schedule-editor-toggle-card,
            html.dark .schedule-editor-advanced,
            .dark .schedule-editor-advanced {
                border-color: rgb(255 255 255 / .08);
                background: #111827;
            }

            html.dark .schedule-editor-footer,
            .dark .schedule-editor-footer {
                border-top-color: rgb(255 255 255 / .08);
                background: #111827;
                box-shadow: 0 -12px 26px rgb(0 0 0 / .16);
            }

            html.dark .schedule-editor-danger,
            .dark .schedule-editor-danger {
                border-color: rgb(251 113 133 / .22);
                background: rgb(225 29 72 / .10);
                color: #fda4af;
            }

            @media (max-width: 620px) {
                .schedule-editor-panel {
                    width: 100vw;
                }

                .schedule-editor-header,
                .schedule-editor-body {
                    padding-left: 16px;
                    padding-right: 16px;
                }

                .schedule-editor-segmented {
                    grid-template-columns: 1fr;
                }
            }


            /* Catálogo de materias y color por bloque */
            .schedule-subject-chip {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 7px 10px;
                border: 1px solid rgb(var(--subject-rgb) / .36);
                border-radius: 999px;
                background: rgb(var(--subject-rgb) / .10);
                color: inherit;
                font-size: .7rem;
                font-weight: 850;
                cursor: pointer;
                transition: background .15s ease, border-color .15s ease, transform .15s ease;
            }

            .schedule-subject-chip:hover {
                transform: translateY(-1px);
                background: rgb(var(--subject-rgb) / .16);
                border-color: rgb(var(--subject-rgb) / .60);
            }

            .schedule-subject-chip-selected {
                background: rgb(var(--subject-rgb) / .24);
                border-color: rgb(var(--subject-rgb) / .78);
                box-shadow: 0 0 0 2px rgb(var(--subject-rgb) / .12);
            }

            .schedule-subject-dot {
                width: 9px;
                height: 9px;
                flex: 0 0 auto;
                border-radius: 999px;
                background: rgb(var(--subject-rgb));
                box-shadow: 0 0 0 3px rgb(var(--subject-rgb) / .12);
            }

            .schedule-subject-origin {
                margin-left: 3px;
                padding: 2px 6px;
                border-radius: 999px;
                background: rgb(148 163 184 / .12);
                color: #94a3b8;
                font-size: .56rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .04em;
            }

            .schedule-block-colored {
                background: linear-gradient(
                    135deg,
                    rgb(var(--subject-rgb) / .22),
                    rgb(var(--subject-rgb) / .11)
                );
                border-color: rgb(var(--subject-rgb) / .55);
                color: #172033;
            }

            .schedule-block-specialist {
                box-shadow:
                    inset 4px 0 0 #f59e0b,
                    0 4px 12px rgb(15 23 42 / .08);
            }

            .schedule-block-flexible-mark {
                box-shadow:
                    inset 0 -3px 0 #a78bfa,
                    0 4px 12px rgb(15 23 42 / .08);
            }

            .schedule-block-unplanned {
                opacity: .70;
                border-style: dashed;
            }

            .schedule-block-special-badge {
                display: inline-flex;
                align-items: center;
                width: fit-content;
                margin-top: 4px;
                padding: 2px 6px;
                border-radius: 999px;
                background: rgb(255 255 255 / .72);
                color: inherit;
                font-size: .56rem;
                font-weight: 900;
                letter-spacing: .02em;
            }

            html.dark .schedule-block-colored,
            .dark .schedule-block-colored {
                background: linear-gradient(
                    135deg,
                    rgb(var(--subject-rgb) / .30),
                    rgb(var(--subject-rgb) / .15)
                );
                border-color: rgb(var(--subject-rgb) / .64);
                color: #f8fafc;
            }

            html.dark .schedule-block-special-badge,
            .dark .schedule-block-special-badge {
                background: rgb(2 6 23 / .48);
                color: #f8fafc;
            }

            .schedule-catalog-shell {
                position: fixed;
                inset: 0;
                z-index: 65;
                display: flex;
                justify-content: flex-end;
            }

            .schedule-catalog-panel {
                position: relative;
                z-index: 1;
                display: flex;
                width: min(460px, 100vw);
                height: 100%;
                flex-direction: column;
                border-left: 1px solid #e2e8f0;
                background: #fff;
                color: #0f172a;
                box-shadow: -20px 0 52px rgb(15 23 42 / .18);
            }

            .schedule-catalog-header {
                padding: 20px 22px 17px;
                border-bottom: 1px solid #e2e8f0;
            }

            .schedule-catalog-title {
                color: #0f172a;
                font-size: 1.1rem;
                font-weight: 850;
            }

            .schedule-catalog-copy {
                margin-top: 4px;
                color: #64748b;
                font-size: .73rem;
                line-height: 1.45;
            }

            .schedule-catalog-body {
                flex: 1;
                overflow-y: auto;
                padding: 16px 20px 24px;
            }

            .schedule-catalog-list {
                display: grid;
                gap: 9px;
            }

            .schedule-catalog-row {
                display: grid;
                grid-template-columns: 18px minmax(0,1fr) auto;
                align-items: center;
                gap: 10px;
                padding: 11px 12px;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #f8fafc;
            }

            .schedule-catalog-swatch {
                width: 14px;
                height: 14px;
                border-radius: 5px;
                background: var(--subject-color);
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--subject-color) 18%, transparent);
            }

            .schedule-catalog-name {
                color: #334155;
                font-size: .78rem;
                font-weight: 850;
            }

            .schedule-catalog-meta {
                margin-top: 2px;
                color: #94a3b8;
                font-size: .62rem;
                font-weight: 700;
            }

            .schedule-catalog-color {
                width: 38px;
                height: 30px;
                padding: 2px;
                border: 1px solid #dbe2ea;
                border-radius: 8px;
                background: transparent;
                cursor: pointer;
            }

            .schedule-catalog-create {
                padding: 16px 20px 20px;
                border-top: 1px solid #e2e8f0;
                background: #fff;
            }

            .schedule-catalog-create-grid {
                display: grid;
                grid-template-columns: minmax(0,1fr) 54px;
                gap: 9px;
            }

            html.dark .schedule-catalog-panel,
            .dark .schedule-catalog-panel,
            html.dark .schedule-catalog-create,
            .dark .schedule-catalog-create {
                border-color: rgb(255 255 255 / .09);
                background: #0f172a;
                color: #f8fafc;
            }

            html.dark .schedule-catalog-header,
            .dark .schedule-catalog-header,
            html.dark .schedule-catalog-create,
            .dark .schedule-catalog-create {
                border-color: rgb(255 255 255 / .08);
            }

            html.dark .schedule-catalog-title,
            .dark .schedule-catalog-title,
            html.dark .schedule-catalog-name,
            .dark .schedule-catalog-name {
                color: #f8fafc;
            }

            html.dark .schedule-catalog-copy,
            .dark .schedule-catalog-copy,
            html.dark .schedule-catalog-meta,
            .dark .schedule-catalog-meta {
                color: #94a3b8;
            }

            html.dark .schedule-catalog-row,
            .dark .schedule-catalog-row {
                border-color: rgb(255 255 255 / .08);
                background: #111827;
            }

            html.dark .schedule-catalog-color,
            .dark .schedule-catalog-color {
                border-color: rgb(255 255 255 / .12);
            }


            .schedule-view-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 10px;
                border-radius: 999px;
                background: rgb(16 185 129 / .10);
                color: #34d399;
                font-size: .7rem;
                font-weight: 850;
            }

            .schedule-day-column-readonly {
                cursor: default;
            }

            .schedule-day-column-readonly:hover {
                background-color: inherit;
            }

            .schedule-print-dialog-shell {
                position: fixed;
                inset: 0;
                z-index: 70;
                display: grid;
                place-items: center;
                padding: 20px;
            }

            .schedule-print-dialog {
                position: relative;
                z-index: 1;
                width: min(440px, 100%);
                overflow: hidden;
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                background: #fff;
                color: #0f172a;
                box-shadow: 0 24px 70px rgb(2 6 23 / .28);
            }

            .schedule-print-dialog-head {
                padding: 20px 22px 14px;
                border-bottom: 1px solid #e2e8f0;
            }

            .schedule-print-dialog-title {
                font-size: 1.05rem;
                font-weight: 850;
            }

            .schedule-print-dialog-copy {
                margin-top: 4px;
                color: #64748b;
                font-size: .76rem;
                line-height: 1.45;
            }

            .schedule-print-options {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                padding: 18px 22px;
            }

            .schedule-print-option {
                padding: 14px;
                border: 1px solid #dbe2ea;
                border-radius: 13px;
                background: #f8fafc;
                color: #475569;
                text-align: left;
                cursor: pointer;
            }

            .schedule-print-option-active {
                border-color: #3b82f6;
                background: #eff6ff;
                color: #1d4ed8;
                box-shadow: 0 0 0 2px rgb(59 130 246 / .08);
            }

            .schedule-print-option strong {
                display: block;
                font-size: .82rem;
            }

            .schedule-print-option span {
                display: block;
                margin-top: 3px;
                font-size: .68rem;
                line-height: 1.35;
                opacity: .78;
            }

            .schedule-print-dialog-actions {
                display: flex;
                justify-content: flex-end;
                gap: 9px;
                padding: 0 22px 20px;
            }

            html.dark .schedule-print-dialog,
            .dark .schedule-print-dialog {
                border-color: rgb(255 255 255 / .10);
                background: #0f172a;
                color: #f8fafc;
            }

            html.dark .schedule-print-dialog-head,
            .dark .schedule-print-dialog-head {
                border-color: rgb(255 255 255 / .08);
            }

            html.dark .schedule-print-dialog-copy,
            .dark .schedule-print-dialog-copy {
                color: #94a3b8;
            }

            html.dark .schedule-print-option,
            .dark .schedule-print-option {
                border-color: rgb(255 255 255 / .10);
                background: #111827;
                color: #cbd5e1;
            }

            html.dark .schedule-print-option-active,
            .dark .schedule-print-option-active {
                border-color: #3b82f6;
                background: rgb(59 130 246 / .12);
                color: #bfdbfe;
            }

            .schedule-print-sheet {
                display: none;
            }

            @media print {
                @page {
                    size: A4 landscape;
                    margin: 9mm;
                }

                html,
                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100% !important;
                    height: auto !important;
                    overflow: visible !important;
                    background: #fff !important;
                }

                body * {
                    visibility: hidden !important;
                }

                .schedule-print-sheet,
                .schedule-print-sheet * {
                    visibility: visible !important;
                }

                /* Ocultar del flujo todo lo que no se imprime evita páginas extra en blanco. */
                .fi-sidebar,
                .fi-topbar,
                .fi-header,
                .schedule-onboarding-wrap,
                .schedule-workspace,
                .schedule-editor-shell,
                .schedule-catalog-shell,
                .schedule-print-dialog-shell {
                    display: none !important;
                }

                .schedule-print-sheet {
                    display: block !important;
                    position: static !important;
                    width: 100% !important;
                    height: auto !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: visible !important;
                    background: #fff !important;
                    color: #000 !important;
                    font-family: Arial, sans-serif;
                    break-inside: avoid;
                    page-break-inside: avoid;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }

                .schedule-print-header {
                    display: flex;
                    align-items: flex-end;
                    justify-content: space-between;
                    gap: 20px;
                    margin-bottom: 10px;
                    padding-bottom: 8px;
                    border-bottom: 2px solid #111;
                }

                .schedule-print-title {
                    margin: 0;
                    font-size: 18pt;
                    font-weight: 800;
                }

                .schedule-print-meta {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0,1fr));
                    gap: 3px 24px;
                    margin-top: 5px;
                    font-size: 8.5pt;
                }

                .schedule-print-meta strong {
                    font-weight: 800;
                }

                .schedule-print-jornada {
                    font-size: 9pt;
                    font-weight: 700;
                    white-space: nowrap;
                }

                .schedule-print-board {
                    overflow: hidden;
                    border: 1px solid #111;
                }

                .schedule-print-head,
                .schedule-print-body {
                    display: grid;
                    grid-template-columns: 58px repeat(5, minmax(0,1fr));
                }

                .schedule-print-head > div {
                    padding: 5px 4px;
                    border-right: 1px solid #111;
                    border-bottom: 1px solid #111;
                    font-size: 8pt;
                    font-weight: 800;
                    text-align: center;
                    background: #f3f4f6;
                }

                .schedule-print-time-column {
                    position: relative;
                    border-right: 1px solid #111;
                    background: #fff;
                }

                .schedule-print-time-label {
                    position: absolute;
                    right: 5px;
                    transform: translateY(-50%);
                    font-size: 6.8pt;
                    font-weight: 700;
                }

                .schedule-print-day-column {
                    position: relative;
                    border-right: 1px solid #111;
                    background-image:
                        repeating-linear-gradient(
                            to bottom,
                            transparent 0,
                            transparent calc(var(--half-hour) - 1px),
                            #cbd5e1 calc(var(--half-hour) - 1px),
                            #cbd5e1 var(--half-hour)
                        );
                }

                .schedule-print-day-column:last-child {
                    border-right: 0;
                }

                .schedule-print-block {
                    position: absolute;
                    left: 3px;
                    right: 3px;
                    overflow: hidden;
                    padding: 4px 5px;
                    border: 1px solid rgb(var(--subject-rgb, 100 116 139) / .75);
                    border-radius: 5px;
                    background: rgb(var(--subject-rgb, 100 116 139) / .20);
                    color: #111;
                    font-size: 6.7pt;
                    line-height: 1.15;
                }

                .schedule-print-block-title {
                    font-weight: 800;
                }

                .schedule-print-block-time,
                .schedule-print-block-badge {
                    margin-top: 1px;
                    font-size: 6pt;
                    font-weight: 600;
                }

                .schedule-print-block-badge {
                    font-weight: 800;
                }

                .schedule-print-bw .schedule-print-head > div,
                .schedule-print-bw .schedule-print-time-column,
                .schedule-print-bw .schedule-print-day-column,
                .schedule-print-bw .schedule-print-block {
                    background: #fff !important;
                    background-image: none !important;
                    color: #000 !important;
                    border-color: #000 !important;
                    box-shadow: none !important;
                }

                .schedule-print-bw .schedule-print-day-column {
                    background-image:
                        repeating-linear-gradient(
                            to bottom,
                            transparent 0,
                            transparent calc(var(--half-hour) - 1px),
                            #999 calc(var(--half-hour) - 1px),
                            #999 var(--half-hour)
                        ) !important;
                }

                .schedule-print-bw .schedule-print-block {
                    border-radius: 0;
                }
            }

        </style>

        <div x-show="setupOpen" x-cloak class="schedule-onboarding-wrap">
            <section class="schedule-onboarding-card">
                <div class="schedule-onboarding-hero">
                    <div class="schedule-onboarding-kicker">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 3v3M17 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Configuración inicial
                    </div>

                    <h2 class="schedule-onboarding-title" x-text="firstSetup ? 'Armemos tu semana escolar' : 'Ajusta tu jornada'"></h2>
                    <p class="schedule-onboarding-copy">
                        Sólo necesitamos tu hora de entrada y salida. Después podrás acomodar clases, talleres y espacios especiales directamente sobre el horario.
                    </p>
                </div>

                <div class="schedule-onboarding-body">
                    <div class="schedule-step-label">
                        <span class="schedule-step-number">1</span>
                        Define tu jornada
                    </div>

                    <div class="schedule-time-grid">
                        <label class="schedule-time-card">
                            <span class="schedule-time-icon">
                                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span>
                                <span class="schedule-time-meta">Entrada</span>
                                <input x-model="dayStart" type="time" class="schedule-time-input" />
                            </span>
                        </label>

                        <label class="schedule-time-card">
                            <span class="schedule-time-icon">
                                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span>
                                <span class="schedule-time-meta">Salida</span>
                                <input x-model="dayEnd" type="time" class="schedule-time-input" />
                            </span>
                        </label>
                    </div>

                    <div x-show="firstSetup" class="schedule-recess-card">
                        <div style="flex:1;min-width:0">
                            <div class="schedule-step-label" style="margin-bottom:6px">
                                <span class="schedule-step-number">2</span>
                                Recreo
                            </div>
                            <div class="schedule-recess-title">¿Tu grupo tiene un recreo habitual?</div>
                            <div class="schedule-recess-copy">Puedes agregarlo de una vez de lunes a viernes. Después podrás cambiarlo por día.</div>

                            <div x-show="recessEnabled" x-cloak class="schedule-recess-times">
                                <label>
                                    <span class="schedule-mini-label">Empieza</span>
                                    <input x-model="recessStart" type="time" class="schedule-mini-input" />
                                </label>
                                <label>
                                    <span class="schedule-mini-label">Termina</span>
                                    <input x-model="recessEnd" type="time" class="schedule-mini-input" />
                                </label>
                            </div>
                        </div>

                        <label class="schedule-switch" aria-label="Agregar recreo">
                            <input x-model="recessEnabled" type="checkbox" />
                            <span class="schedule-switch-track"></span>
                        </label>
                    </div>

                    <p x-show="setupError" x-cloak x-text="setupError" class="schedule-error"></p>

                    <div class="schedule-onboarding-footer">
                        <div class="schedule-footer-note">
                            <strong style="color:inherit">No necesitas definir materias todavía.</strong><br>
                            Primero creamos la estructura de tu semana y después acomodas cada bloque visualmente.
                        </div>

                        <div style="display:flex;gap:10px;align-items:center">
                            <button
                                x-show="!firstSetup"
                                type="button"
                                class="schedule-secondary-action"
                                x-on:click="setupOpen = false"
                            >Cancelar</button>

                            <button
                                type="button"
                                class="schedule-primary-action"
                                x-on:click="applySetup()"
                            >
                                <span x-text="firstSetup ? 'Crear mi horario' : 'Aplicar jornada'"></span>
                                <span aria-hidden="true" style="margin-left:8px">→</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <template x-if="started">
            <div class="schedule-workspace">
                <section class="schedule-toolbar">
                    <div class="schedule-toolbar-main">
                        <div class="schedule-toolbar-eyebrow">Horario habitual</div>
                        <div class="schedule-toolbar-time">
                            <span x-text="dayStart"></span>
                            <span class="schedule-toolbar-arrow">→</span>
                            <span x-text="dayEnd"></span>
                            <span class="schedule-count-badge">
                                <span>●</span>
                                <span x-text="planableCount()"></span>
                                <span>planeables</span>
                            </span>
                            <span x-show="dirty" x-cloak class="schedule-unsaved-badge">Cambios sin guardar</span>
                        </div>
                    </div>

                    <div class="schedule-toolbar-actions">
                        <template x-if="!editMode && hasSavedSchedule">
                            <div style="display:flex;gap:10px;align-items:center">
                                <button type="button" class="schedule-button schedule-button-secondary" x-on:click="showPrintDialog = true">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v6H7v-6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    </svg>
                                    Imprimir
                                </button>
                                <button type="button" class="schedule-button schedule-button-primary" x-on:click="beginEdit()">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m4 20 4.2-1 10.6-10.6a2 2 0 0 0-2.8-2.8L5.4 16.2 4 20Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                    </svg>
                                    Editar
                                </button>
                            </div>
                        </template>

                        <template x-if="editMode">
                            <div style="display:flex;gap:10px;align-items:center">
                                <button type="button" class="schedule-button schedule-button-secondary" x-on:click="showSubjectCatalog = true">
                                    <span class="schedule-subject-dot" style="--subject-rgb:59 130 246"></span>
                                    Materias
                                </button>
                                <button type="button" class="schedule-button schedule-button-secondary" x-on:click="setupOpen = true">
                                    <span aria-hidden="true">◷</span>
                                    Ajustar jornada
                                </button>
                                <button type="button" class="schedule-button schedule-button-primary" x-on:click="save($wire)">
                                    <span aria-hidden="true">💾</span>
                                    <span x-text="hasSavedSchedule ? 'Guardar cambios' : 'Guardar horario'"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </section>

                <div x-show="editMode" x-cloak class="schedule-tip">
                    <span class="schedule-tip-icon">＋</span>
                    <div>
                        <strong>Toca cualquier espacio para agregar una clase.</strong>
                        La hora se toma automáticamente del lugar donde hagas clic y podrás ajustarla antes de guardar.
                    </div>
                </div>

                <section class="schedule-desktop schedule-board">
                    <div class="schedule-board-scroll">
                        <div class="schedule-board-inner">
                            <div class="schedule-board-header">
                                <div class="schedule-time-head">Hora</div>
                                <template x-for="day in days" :key="'head-' + day.value">
                                    <div class="schedule-day-head">
                                        <div class="schedule-day-name" x-text="day.label"></div>
                                        <button x-show="editMode" x-cloak type="button" class="schedule-day-add" x-on:click="addBlock(day.value)">
                                            <span>＋</span> Agregar
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="schedule-board-body" :style="'height:' + gridHeight() + 'px'">
                                <div class="schedule-time-column">
                                    <template x-for="mark in timeMarks()" :key="'time-' + mark.minutes">
                                        <span
                                            class="schedule-time-label"
                                            :style="'top:' + mark.top + 'px'"
                                            x-text="mark.label"
                                        ></span>
                                    </template>
                                </div>

                                <template x-for="day in days" :key="'column-' + day.value">
                                    <div
                                        class="schedule-day-column"
                                        :class="!editMode ? 'schedule-day-column-readonly' : ''"
                                        :style="'--half-hour:' + halfHourPixels() + 'px'"
                                        x-on:click="editMode && addBlockAt(day.value, $event)"
                                    >
                                        <div x-show="blocksFor(day.value).length === 0" class="schedule-empty-hint">
                                            <span class="schedule-empty-plus">＋</span>
                                            Haz clic para agregar un bloque
                                        </div>

                                        <template x-for="block in blocksFor(day.value)" :key="block._key">
                                            <button
                                                type="button"
                                                x-on:click.stop="editMode && editBlock(block)"
                                                class="schedule-block"
                                                :class="blockClasses(block)"
                                                :style="blockStyle(block)"
                                            >
                                                <div class="schedule-block-title" x-text="block.subject_name_snapshot || block.label"></div>
                                                <div x-show="blockBadge(block)" class="schedule-block-special-badge" x-text="blockBadge(block)"></div>
                                                <div class="schedule-block-time">
                                                    <span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span>
                                                </div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="schedule-mobile">
                    <div class="schedule-mobile-tabs">
                        <template x-for="day in days" :key="'tab-' + day.value">
                            <button
                                type="button"
                                class="schedule-mobile-tab"
                                :class="activeDay === day.value ? 'schedule-mobile-tab-active' : ''"
                                x-on:click="activeDay = day.value"
                                x-text="day.short"
                            ></button>
                        </template>
                    </div>

                    <div class="schedule-mobile-board">
                        <div class="schedule-mobile-head" x-text="dayName(activeDay)"></div>
                        <div class="schedule-mobile-grid" :style="'height:' + gridHeight() + 'px'">
                            <div class="schedule-time-column">
                                <template x-for="mark in timeMarks()" :key="'mobile-time-' + mark.minutes">
                                    <span
                                        class="schedule-time-label"
                                        :style="'top:' + mark.top + 'px'"
                                        x-text="mark.label"
                                    ></span>
                                </template>
                            </div>
                            <div
                                class="schedule-day-column"
                                :class="!editMode ? 'schedule-day-column-readonly' : ''"
                                :style="'--half-hour:' + halfHourPixels() + 'px'"
                                x-on:click="editMode && addBlockAt(activeDay, $event)"
                            >
                                <div x-show="blocksFor(activeDay).length === 0" class="schedule-empty-hint">
                                    <span class="schedule-empty-plus">＋</span>
                                    Toca aquí para agregar un bloque
                                </div>

                                <template x-for="block in blocksFor(activeDay)" :key="'mobile-' + block._key">
                                    <button
                                        type="button"
                                        x-on:click.stop="editMode && editBlock(block)"
                                        class="schedule-block"
                                        :class="blockClasses(block)"
                                        :style="blockStyle(block)"
                                    >
                                        <div class="schedule-block-title" x-text="block.subject_name_snapshot || block.label"></div>
                                        <div x-show="blockBadge(block)" class="schedule-block-special-badge" x-text="blockBadge(block)"></div>
                                        <div class="schedule-block-time"><span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span></div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="schedule-board-footer">
                    <span aria-hidden="true">ℹ</span>
                    <span>
                        Al confirmar una planeación se congela esta versión del horario. Los bloques que no incluyas en tu planeación conservan su espacio, pero la IA no genera actividades para ellos.
                    </span>
                </div>
            </div>
        </template>

        <div x-show="editing" x-cloak class="schedule-editor-shell" aria-modal="true" role="dialog">
            <button type="button" class="schedule-editor-backdrop" x-on:click="closeEditor()" aria-label="Cerrar editor"></button>

            <aside class="schedule-editor-panel">
                <header class="schedule-editor-header">
                    <div>
                        <div class="schedule-editor-kicker">
                            <span x-text="dayName(editing?.day_of_week)"></span>
                            · <span x-text="editing?.starts_at"></span>–<span x-text="editing?.ends_at"></span>
                        </div>
                        <h3 class="schedule-editor-title">Editar bloque del horario</h3>
                    </div>
                    <button type="button" class="schedule-editor-close" x-on:click="closeEditor()" aria-label="Cerrar">✕</button>
                </header>

                <div class="schedule-editor-body">
                    <section class="schedule-editor-section">
                        <label class="schedule-editor-label">Materia</label>
                        <p class="schedule-editor-help">Las materias oficiales vienen del currículo del grupo. También puedes crear materias propias para tu escuela.</p>

                        <div class="schedule-editor-chip-row">
                            <template x-for="subject in subjects" :key="'subject-' + subject.id">
                                <button
                                    type="button"
                                    class="schedule-subject-chip"
                                    :class="Number(editing?.group_subject_id) === Number(subject.id) ? 'schedule-subject-chip-selected' : ''"
                                    :style="subjectChipStyle(subject)"
                                    x-on:click="applySubject(subject)"
                                >
                                    <span class="schedule-subject-dot"></span>
                                    <span x-text="subject.name"></span>
                                    <span x-show="subject.origin === 'official'" class="schedule-subject-origin">Oficial</span>
                                </button>
                            </template>

                            <button type="button" class="schedule-editor-chip" x-on:click="showSubjectCreator = !showSubjectCreator">＋ Nueva materia</button>
                        </div>

                        <div x-show="showSubjectCreator" x-cloak class="schedule-editor-advanced">
                            <label class="schedule-editor-label">Nombre de la nueva materia</label>
                            <input x-model="subjectForm.name" type="text" class="schedule-editor-input" placeholder="Ej. Robótica" />
                            <div style="display:grid;grid-template-columns:1fr 54px;gap:9px;margin-top:9px">
                                <button type="button" class="schedule-editor-done" x-on:click="createCatalogSubject($wire, true)">Agregar materia</button>
                                <input x-model="subjectForm.color" type="color" class="schedule-catalog-color" style="width:54px;height:40px" />
                            </div>
                        </div>

                        <div style="margin-top:14px">
                            <label class="schedule-editor-label">Espacios especiales</label>
                            <div class="schedule-editor-chip-row">
                                <button type="button" class="schedule-editor-chip" x-on:click="usePreset('Recreo')">Recreo</button>
                                <button type="button" class="schedule-editor-chip" x-on:click="usePreset('Flexible')">Flexible</button>
                            </div>
                        </div>
                    </section>

                    <section class="schedule-editor-section">
                        <label class="schedule-editor-label">Horario</label>
                        <div class="schedule-editor-time-grid">
                            <label>
                                <span class="schedule-mini-label">Empieza</span>
                                <input x-model="editing.starts_at" x-on:change="dirty = true" type="time" class="schedule-editor-input" />
                            </label>
                            <label>
                                <span class="schedule-mini-label">Termina</span>
                                <input x-model="editing.ends_at" x-on:change="dirty = true" type="time" class="schedule-editor-input" />
                            </label>
                        </div>
                    </section>

                    <section class="schedule-editor-section">
                        <label class="schedule-editor-label">¿Quién la imparte?</label>
                        <div class="schedule-editor-segmented">
                            <button
                                type="button"
                                class="schedule-editor-segment"
                                :class="editing?.responsibility === 'main_teacher' ? 'schedule-editor-segment-active' : ''"
                                x-on:click="setResponsibility('main_teacher')"
                            >Yo</button>
                            <button
                                type="button"
                                class="schedule-editor-segment"
                                :class="editing?.responsibility === 'specialist' ? 'schedule-editor-segment-active' : ''"
                                x-on:click="setResponsibility('specialist')"
                            >Otro profesor</button>
                            <button
                                type="button"
                                class="schedule-editor-segment"
                                :class="editing?.responsibility === 'shared' ? 'schedule-editor-segment-active' : ''"
                                x-on:click="setResponsibility('shared')"
                            >Compartida</button>
                        </div>
                    </section>

                    <section class="schedule-editor-section">
                        <label class="schedule-editor-toggle-card">
                            <span>
                                <span class="schedule-editor-toggle-title">Incluir en mis planeaciones</span>
                                <span class="schedule-editor-toggle-copy">Si lo desactivas, conserva el espacio en tu jornada pero la IA no genera una actividad.</span>
                            </span>
                            <span class="schedule-switch">
                                <input x-model="editing.include_in_planning" x-on:change="dirty = true" type="checkbox" />
                                <span class="schedule-switch-track"></span>
                            </span>
                        </label>
                    </section>

                    <section x-show="fieldOptions.length > 0" class="schedule-editor-section">
                        <label class="schedule-editor-label">Relación curricular <span style="font-weight:600;color:#94a3b8">(opcional)</span></label>
                        <p class="schedule-editor-help">Sólo selecciónala si quieres reservar este bloque para uno o más campos formativos.</p>

                        <div class="schedule-editor-field-list">
                            <template x-for="field in fieldOptions" :key="'field-' + field.code">
                                <label class="schedule-editor-field">
                                    <input type="checkbox" :checked="hasField(field.code)" x-on:change="toggleField(field.code)" />
                                    <span x-text="field.name"></span>
                                </label>
                            </template>
                        </div>
                    </section>

                    <section class="schedule-editor-section">
                        <button type="button" class="schedule-editor-more" x-on:click="showAdvanced = !showAdvanced">
                            <span x-text="showAdvanced ? '− Ocultar opciones' : '+ Más opciones'"></span>
                        </button>

                        <div x-show="showAdvanced" x-cloak class="schedule-editor-advanced">
                            <label class="schedule-editor-label">Tipo de bloque</label>
                            <select x-model="editing.block_type" x-on:change="dirty = true" class="schedule-editor-select">
                                <option value="class">Clase / espacio académico</option>
                                <option value="flexible">Flexible</option>
                                <option value="specialist">Clase con especialista</option>
                                <option value="activity">Actividad / taller</option>
                                <option value="break">Recreo / descanso</option>
                                <option value="unavailable">No disponible</option>
                            </select>

                            <label class="schedule-editor-toggle-card" style="margin-top:10px">
                                <span>
                                    <span class="schedule-editor-toggle-title">Contenido flexible</span>
                                    <span class="schedule-editor-toggle-copy">Permite que la IA decida qué contenido colocar aquí.</span>
                                </span>
                                <span class="schedule-switch">
                                    <input x-model="editing.is_flexible" x-on:change="dirty = true" type="checkbox" />
                                    <span class="schedule-switch-track"></span>
                                </span>
                            </label>

                            <label style="display:block;margin-top:10px">
                                <span class="schedule-editor-label">Nota opcional</span>
                                <textarea x-model="editing.notes" x-on:input="dirty = true" rows="2" class="schedule-editor-textarea" placeholder="Ej. La imparte la maestra de computación"></textarea>
                            </label>
                        </div>
                    </section>

                    <section class="schedule-editor-section">
                        <label class="schedule-editor-label">Copiar a otro día</label>
                        <div class="schedule-editor-copy-days">
                            <template x-for="day in days" :key="'copy-' + day.value">
                                <button
                                    type="button"
                                    class="schedule-editor-day-button"
                                    :class="{
                                        'schedule-editor-day-button-selected': copyTargetSelected(day.value),
                                        'schedule-editor-day-button-conflict': copyTargetConflict(day.value),
                                    }"
                                    :disabled="Number(editing?.day_of_week) === Number(day.value) || copyTargetConflict(day.value)"
                                    x-on:click="toggleCopyEditingTo(day.value)"
                                    :title="
                                        copyTargetSelected(day.value)
                                            ? 'Quitar de ' + day.label
                                            : (copyTargetConflict(day.value)
                                                ? 'Hay otra clase ocupando este horario'
                                                : 'Copiar a ' + day.label)
                                    "
                                >
                                    <span x-text="copyTargetSelected(day.value) ? '✓ ' + day.short : day.short"></span>
                                </button>
                            </template>
                        </div>
                        <div x-show="editorNotice" x-cloak class="schedule-editor-notice" x-text="editorNotice"></div>
                    </section>
                </div>

                <footer class="schedule-editor-footer">
                    <button type="button" class="schedule-editor-danger" x-on:click="removeEditing()">Eliminar</button>
                    <button type="button" class="schedule-editor-done" x-on:click="closeEditor()">Listo</button>
                </footer>
            </aside>
        </div>

        <div x-show="showPrintDialog" x-cloak class="schedule-print-dialog-shell" aria-modal="true" role="dialog">
            <button type="button" class="schedule-editor-backdrop" x-on:click="showPrintDialog = false" aria-label="Cerrar impresión"></button>
            <section class="schedule-print-dialog">
                <div class="schedule-print-dialog-head">
                    <div class="schedule-print-dialog-title">Imprimir horario</div>
                    <div class="schedule-print-dialog-copy">Elige cómo quieres imprimirlo. La versión en blanco y negro elimina todos los colores y deja únicamente la tabla.</div>
                </div>
                <div class="schedule-print-options">
                    <button type="button" class="schedule-print-option" :class="printMode === 'color' ? 'schedule-print-option-active' : ''" x-on:click="printMode = 'color'">
                        <strong>● A color</strong>
                        <span>Conserva los colores de cada materia y los distintivos.</span>
                    </button>
                    <button type="button" class="schedule-print-option" :class="printMode === 'bw' ? 'schedule-print-option-active' : ''" x-on:click="printMode = 'bw'">
                        <strong>Blanco y negro</strong>
                        <span>Sin rellenos ni colores; sólo estructura, textos y tabla.</span>
                    </button>
                </div>
                <div class="schedule-print-dialog-actions">
                    <button type="button" class="schedule-button schedule-button-secondary" x-on:click="showPrintDialog = false">Cancelar</button>
                    <button type="button" class="schedule-button schedule-button-primary" x-on:click="printSchedule()">Imprimir</button>
                </div>
            </section>
        </div>

        <section
            class="schedule-print-sheet"
            :class="printMode === 'bw' ? 'schedule-print-bw' : 'schedule-print-color'"
        >
            <header class="schedule-print-header">
                <div>
                    <h1 class="schedule-print-title">Horario semanal</h1>
                    <div class="schedule-print-meta">
                        <div><strong>Escuela:</strong> <span x-text="printMeta.school || '—'"></span></div>
                        <div><strong>Maestro(a):</strong> <span x-text="printMeta.teacher || '—'"></span></div>
                        <div><strong>Grado:</strong> <span x-text="printMeta.grade || '—'"></span></div>
                        <div><strong>Grupo:</strong> <span x-text="printMeta.group || '—'"></span></div>
                        <div><strong>Ciclo escolar:</strong> <span x-text="printMeta.school_year || '—'"></span></div>
                    </div>
                </div>
                <div class="schedule-print-jornada">Horario: <span x-text="dayStart"></span>–<span x-text="dayEnd"></span></div>
            </header>

            <div class="schedule-print-board">
                <div class="schedule-print-head">
                    <div>Hora</div>
                    <template x-for="day in days" :key="'print-head-' + day.value">
                        <div x-text="day.label"></div>
                    </template>
                </div>
                <div class="schedule-print-body" :style="'height:' + printGridHeight() + 'px'">
                    <div class="schedule-print-time-column">
                        <template x-for="mark in printTimeMarks()" :key="'print-time-' + mark.minutes">
                            <span class="schedule-print-time-label" :style="'top:' + mark.top + 'px'" x-text="mark.label"></span>
                        </template>
                    </div>
                    <template x-for="day in days" :key="'print-day-' + day.value">
                        <div class="schedule-print-day-column" :style="'--half-hour:' + printHalfHourPixels() + 'px'">
                            <template x-for="block in blocksFor(day.value)" :key="'print-block-' + block._key">
                                <div class="schedule-print-block" :style="printBlockStyle(block)">
                                    <div class="schedule-print-block-title" x-text="block.subject_name_snapshot || block.label"></div>
                                    <div x-show="blockBadge(block)" class="schedule-print-block-badge" x-text="blockBadge(block)"></div>
                                    <div class="schedule-print-block-time"><span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span></div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <div x-show="showSubjectCatalog" x-cloak class="schedule-catalog-shell" aria-modal="true" role="dialog">
            <button type="button" class="schedule-editor-backdrop" x-on:click="showSubjectCatalog = false" aria-label="Cerrar catálogo"></button>

            <aside class="schedule-catalog-panel">
                <header class="schedule-catalog-header">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">
                        <div>
                            <div class="schedule-editor-kicker">Colores del horario</div>
                            <div class="schedule-catalog-title">Catálogo de materias</div>
                            <div class="schedule-catalog-copy">Las oficiales vienen del currículo del grupo. Puedes cambiar sus colores y agregar materias propias.</div>
                        </div>
                        <button type="button" class="schedule-editor-close" x-on:click="showSubjectCatalog = false">✕</button>
                    </div>
                </header>

                <div class="schedule-catalog-body">
                    <div class="schedule-catalog-list">
                        <template x-for="subject in subjects" :key="'catalog-' + subject.id">
                            <div class="schedule-catalog-row">
                                <span class="schedule-catalog-swatch" :style="'--subject-color:' + subject.color"></span>
                                <div>
                                    <div class="schedule-catalog-name" x-text="subject.name"></div>
                                    <div class="schedule-catalog-meta" x-text="subject.origin === 'official' ? 'Materia oficial del currículo' : 'Materia personalizada'"></div>
                                </div>
                                <input
                                    type="color"
                                    class="schedule-catalog-color"
                                    :value="subject.color"
                                    x-on:change="updateCatalogColor(subject, $event.target.value, $wire)"
                                    :aria-label="'Color de ' + subject.name"
                                />
                            </div>
                        </template>
                    </div>
                </div>

                <footer class="schedule-catalog-create">
                    <label class="schedule-editor-label">Agregar materia</label>
                    <div class="schedule-catalog-create-grid">
                        <input x-model="subjectForm.name" type="text" class="schedule-editor-input" placeholder="Ej. Robótica, Música, Religión..." />
                        <input x-model="subjectForm.color" type="color" class="schedule-catalog-color" style="width:54px;height:43px" />
                    </div>
                    <button type="button" class="schedule-editor-done" style="width:100%;margin-top:10px" x-on:click="createCatalogSubject($wire, false)">Agregar al catálogo</button>
                    <div x-show="subjectCatalogNotice" x-cloak class="schedule-editor-notice" x-text="subjectCatalogNotice"></div>
                </footer>
            </aside>
        </div>

    <script>
        function scheduleEditor(config) {
            return {
                days: [
                    { value: 1, label: 'Lunes', short: 'Lun' },
                    { value: 2, label: 'Martes', short: 'Mar' },
                    { value: 3, label: 'Miércoles', short: 'Mié' },
                    { value: 4, label: 'Jueves', short: 'Jue' },
                    { value: 5, label: 'Viernes', short: 'Vie' },
                ],
                fieldOptions: config.fieldOptions || [],
                subjects: config.subjects || [],
                printMeta: config.printMeta || {},
                activeDay: 1,
                dayStart: config.dayStart || '08:00',
                dayEnd: config.dayEnd || '12:30',
                defaultDuration: Number(config.defaultDuration || 50),
                blocks: (config.initialBlocks || []).map((b, i) => ({ ...b, _key: 'saved-' + i + '-' + Date.now() })),
                started: Boolean(config.hasSchedule),
                hasSavedSchedule: Boolean(config.hasSchedule),
                editMode: !config.hasSchedule,
                firstSetup: !config.hasSchedule,
                setupOpen: !config.hasSchedule,
                setupError: '',
                recessEnabled: false,
                recessStart: '10:00',
                recessEnd: '10:30',
                editing: null,
                showAdvanced: false,
                showSubjectCatalog: false,
                showSubjectCreator: false,
                showPrintDialog: false,
                printMode: 'color',
                subjectCatalogNotice: '',
                subjectForm: { name: '', color: '#0EA5E9' },
                editorNotice: '',
                dirty: false,
                pxPerMinute: 1.45,

                beginEdit() {
                    this.editMode = true;
                },

                printSchedule() {
                    this.showPrintDialog = false;
                    setTimeout(() => window.print(), 50);
                },

                printGridHeight() {
                    return Math.max(360, (this.toMinutes(this.dayEnd) - this.toMinutes(this.dayStart)) * 1.18);
                },

                printHalfHourPixels() {
                    return 30 * 1.18;
                },

                printTimeMarks() {
                    const start = this.toMinutes(this.dayStart);
                    const end = this.toMinutes(this.dayEnd);
                    const marks = [];
                    for (let m = start; m <= end; m += 30) {
                        marks.push({
                            minutes: m,
                            label: this.fromMinutes(m),
                            top: (m - start) * 1.18,
                        });
                    }
                    return marks;
                },

                printBlockStyle(block) {
                    const start = this.toMinutes(this.dayStart);
                    const top = Math.max(0, (this.toMinutes(block.starts_at) - start) * 1.18);
                    const height = Math.max(22, this.duration(block.starts_at, block.ends_at) * 1.18);
                    const subject = this.findSubject(block.group_subject_id);
                    const color = block.subject_color_snapshot || subject?.color || '#64748B';
                    const rgb = this.hexToRgb(color) || { r: 100, g: 116, b: 139 };

                    return 'top:' + top + 'px;height:' + height + 'px;--subject-rgb:' + rgb.r + ' ' + rgb.g + ' ' + rgb.b;
                },

                applySetup() {
                    this.setupError = '';
                    const start = this.toMinutes(this.dayStart);
                    const end = this.toMinutes(this.dayEnd);
                    if (end <= start) {
                        this.setupError = 'La hora de salida debe ser posterior a la entrada.';
                        return;
                    }

                    if (this.firstSetup && this.recessEnabled) {
                        const rs = this.toMinutes(this.recessStart);
                        const re = this.toMinutes(this.recessEnd);
                        if (re <= rs || rs < start || re > end) {
                            this.setupError = 'El recreo debe quedar dentro de la jornada.';
                            return;
                        }
                        for (const day of this.days) {
                            this.blocks.push(this.newBlock(day.value, this.recessStart, this.recessEnd, {
                                label: 'Recreo',
                                block_type: 'break',
                                responsibility: 'external',
                                include_in_planning: false,
                                is_flexible: false,
                            }));
                        }
                    }

                    this.started = true;
                    this.editMode = true;
                    this.firstSetup = false;
                    this.setupOpen = false;
                    this.dirty = true;
                    this.normalize();
                },

                blocksFor(day) {
                    return this.blocks
                        .filter(b => Number(b.day_of_week) === Number(day))
                        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)));
                },

                dayName(day) {
                    return this.days.find(d => Number(d.value) === Number(day))?.label || '';
                },

                planableCount() {
                    return this.blocks.filter(b => Boolean(b.include_in_planning)).length;
                },

                gridHeight() {
                    return Math.max(420, (this.toMinutes(this.dayEnd) - this.toMinutes(this.dayStart)) * this.pxPerMinute);
                },

                halfHourPixels() {
                    return 30 * this.pxPerMinute;
                },

                timeMarks() {
                    const start = this.toMinutes(this.dayStart);
                    const end = this.toMinutes(this.dayEnd);
                    const marks = [];
                    for (let m = start; m <= end; m += 30) {
                        marks.push({
                            minutes: m,
                            label: this.fromMinutes(m),
                            top: (m - start) * this.pxPerMinute,
                        });
                    }
                    return marks;
                },

                blockStyle(block) {
                    const start = this.toMinutes(this.dayStart);
                    const top = Math.max(0, (this.toMinutes(block.starts_at) - start) * this.pxPerMinute);
                    const height = Math.max(28, this.duration(block.starts_at, block.ends_at) * this.pxPerMinute);
                    const subject = this.findSubject(block.group_subject_id);
                    const color = block.subject_color_snapshot || subject?.color || null;
                    const rgb = this.hexToRgb(color);

                    return 'top:' + top + 'px;height:' + height + 'px'
                        + (rgb ? ';--subject-rgb:' + rgb.r + ' ' + rgb.g + ' ' + rgb.b : '');
                },

                blockClasses(block) {
                    if (block.block_type === 'break') {
                        return 'schedule-block-break';
                    }

                    const hasColor = Boolean(block.subject_color_snapshot || this.findSubject(block.group_subject_id)?.color);
                    const classes = ['schedule-block-class'];
                    if (hasColor) classes.push('schedule-block-colored');
                    if (block.responsibility === 'specialist' || block.responsibility === 'external') classes.push('schedule-block-specialist');
                    if (block.is_flexible || block.block_type === 'flexible') classes.push('schedule-block-flexible-mark');
                    if (!block.include_in_planning) classes.push('schedule-block-unplanned');

                    return classes.join(' ');
                },

                blockBadge(block) {
                    if (block.block_type === 'break') return 'Recreo';
                    if (block.responsibility === 'specialist' || block.responsibility === 'external') return 'Otro profesor';
                    if (block.responsibility === 'shared') return 'Compartida';
                    if (!block.include_in_planning) return 'Fuera de planeación';
                    if (block.is_flexible || block.block_type === 'flexible') return 'Flexible';
                    return '';
                },

                hexToRgb(hex) {
                    const normalized = String(hex || '').replace('#', '').trim();
                    if (!/^[0-9A-Fa-f]{6}$/.test(normalized)) return null;
                    const value = parseInt(normalized, 16);
                    return {
                        r: (value >> 16) & 255,
                        g: (value >> 8) & 255,
                        b: value & 255,
                    };
                },

                addBlock(day) {
                    const slot = this.findFirstAvailableSlot(day, this.defaultDuration);
                    if (!slot) return;

                    this.openNewBlock(day, slot.start);
                },

                findFirstAvailableSlot(day, preferredDuration = 50) {
                    const dayStart = this.toMinutes(this.dayStart);
                    const dayEnd = this.toMinutes(this.dayEnd);
                    const blocks = this.blocksFor(day)
                        .map(block => ({
                            start: this.toMinutes(block.starts_at),
                            end: this.toMinutes(block.ends_at),
                        }))
                        .sort((a, b) => a.start - b.start);

                    const gaps = [];
                    let cursor = dayStart;

                    for (const block of blocks) {
                        if (block.end <= cursor) continue;

                        if (block.start > cursor) {
                            gaps.push({
                                start: cursor,
                                end: Math.min(block.start, dayEnd),
                            });
                        }

                        cursor = Math.max(cursor, block.end);
                        if (cursor >= dayEnd) break;
                    }

                    if (cursor < dayEnd) {
                        gaps.push({ start: cursor, end: dayEnd });
                    }

                    // Primero buscamos un hueco donde quepa la duración habitual completa.
                    const fullGap = gaps.find(gap => (gap.end - gap.start) >= preferredDuration);
                    if (fullGap) {
                        return {
                            start: this.fromMinutes(fullGap.start),
                            end: this.fromMinutes(fullGap.start + preferredDuration),
                        };
                    }

                    // Si no cabe completa, aprovechamos el primer hueco útil de al menos 15 min.
                    const partialGap = gaps.find(gap => (gap.end - gap.start) >= 15);
                    if (partialGap) {
                        return {
                            start: this.fromMinutes(partialGap.start),
                            end: this.fromMinutes(partialGap.end),
                        };
                    }

                    return null;
                },

                addBlockAt(day, event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const fraction = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
                    const startMinutes = this.toMinutes(this.dayStart);
                    const endMinutes = this.toMinutes(this.dayEnd);
                    let target = startMinutes + ((endMinutes - startMinutes) * fraction);
                    target = Math.round(target / 5) * 5;
                    target = Math.max(startMinutes, Math.min(endMinutes - 15, target));
                    this.openNewBlock(day, this.fromMinutes(target));
                },

                openNewBlock(day, start) {
                    const startMinutes = this.toMinutes(start);
                    const dayEndMinutes = this.toMinutes(this.dayEnd);
                    const existing = this.blocksFor(day);

                    // Si el punto seleccionado ya está dentro de otro bloque,
                    // abrimos ese bloque en vez de crear uno superpuesto.
                    const occupied = existing.find(block => {
                        const blockStart = this.toMinutes(block.starts_at);
                        const blockEnd = this.toMinutes(block.ends_at);
                        return startMinutes >= blockStart && startMinutes < blockEnd;
                    });
                    if (occupied) {
                        this.editBlock(occupied);
                        return;
                    }

                    let desiredEnd = Math.min(startMinutes + this.defaultDuration, dayEndMinutes);
                    const nextBlock = existing
                        .filter(block => this.toMinutes(block.starts_at) > startMinutes)
                        .sort((a, b) => this.toMinutes(a.starts_at) - this.toMinutes(b.starts_at))[0];

                    if (nextBlock) {
                        desiredEnd = Math.min(desiredEnd, this.toMinutes(nextBlock.starts_at));
                    }

                    if ((desiredEnd - startMinutes) < 15) {
                        return;
                    }

                    const end = this.fromMinutes(desiredEnd);
                    if (this.hasOverlap(day, start, end)) {
                        return;
                    }

                    const block = this.newBlock(day, start, end);
                    this.blocks.push(block);
                    this.editBlock(block);
                    this.dirty = true;
                    this.normalize();
                },

                newBlock(day, start, end, overrides = {}) {
                    return {
                        _key: 'block-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                        day_of_week: Number(day),
                        sequence: this.blocksFor(day).length + 1,
                        starts_at: start,
                        ends_at: end,
                        label: 'Nueva clase',
                        group_subject_id: null,
                        subject_name_snapshot: null,
                        subject_color_snapshot: null,
                        block_type: 'class',
                        responsibility: 'main_teacher',
                        include_in_planning: true,
                        is_flexible: false,
                        field_codes: [],
                        notes: null,
                        ...overrides,
                    };
                },

                editBlock(block) {
                    this.editing = block;
                    this.showAdvanced = false;
                    this.editorNotice = '';
                },

                closeEditor() {
                    if (this.editing && !String(this.editing.label || '').trim()) {
                        this.editing.label = 'Nueva clase';
                    }
                    this.editing = null;
                    this.showAdvanced = false;
                    this.editorNotice = '';
                    this.normalize();
                },

                useField(field) {
                    if (!this.editing) return;
                    this.editing.label = field.name;
                    this.editing.field_codes = [field.code];
                    this.editing.block_type = 'class';
                    this.editing.include_in_planning = true;
                    this.dirty = true;
                },

                usePreset(label) {
                    if (!this.editing) return;
                    this.editing.label = label;
                    this.editing.group_subject_id = null;
                    this.editing.subject_name_snapshot = null;
                    this.editing.subject_color_snapshot = null;
                    this.editing.field_codes = [];

                    if (label === 'Recreo') {
                        this.editing.block_type = 'break';
                        this.editing.responsibility = 'external';
                        this.editing.include_in_planning = false;
                        this.editing.is_flexible = false;
                    } else if (label === 'Flexible') {
                        this.editing.block_type = 'flexible';
                        this.editing.responsibility = 'main_teacher';
                        this.editing.include_in_planning = true;
                        this.editing.is_flexible = true;
                    } else {
                        this.editing.block_type = 'class';
                    }

                    this.dirty = true;
                },

                findSubject(subjectId) {
                    return this.subjects.find(subject => Number(subject.id) === Number(subjectId)) || null;
                },

                applySubject(subject) {
                    if (!this.editing || !subject) return;

                    this.editing.group_subject_id = Number(subject.id);
                    this.editing.subject_name_snapshot = subject.name;
                    this.editing.subject_color_snapshot = subject.color;
                    this.editing.label = subject.name;
                    this.editing.block_type = 'class';
                    this.editing.include_in_planning = true;

                    if (subject.curriculum_field_code) {
                        this.editing.field_codes = [subject.curriculum_field_code];
                    } else {
                        this.editing.field_codes = [];
                    }

                    this.dirty = true;
                    this.editorNotice = '';
                },

                subjectChipStyle(subject) {
                    const rgb = this.hexToRgb(subject?.color);
                    return rgb ? '--subject-rgb:' + rgb.r + ' ' + rgb.g + ' ' + rgb.b : '--subject-rgb:59 130 246';
                },

                async createCatalogSubject(wire, applyToEditing = false) {
                    this.subjectCatalogNotice = '';
                    const name = String(this.subjectForm.name || '').trim();
                    if (!name) {
                        this.subjectCatalogNotice = 'Escribe un nombre para la materia.';
                        return;
                    }

                    try {
                        const subject = await wire.createSubject({
                            name,
                            color: this.subjectForm.color || '#0EA5E9',
                        });

                        this.subjects = this.subjects
                            .filter(item => Number(item.id) !== Number(subject.id))
                            .concat([subject])
                            .sort((a, b) => {
                                if (a.origin !== b.origin) return a.origin === 'official' ? -1 : 1;
                                return a.name.localeCompare(b.name);
                            });

                        if (applyToEditing && this.editing) {
                            this.applySubject(subject);
                            this.showSubjectCreator = false;
                        }

                        this.subjectForm = { name: '', color: '#0EA5E9' };
                        this.subjectCatalogNotice = 'Materia agregada al catálogo.';
                    } catch (error) {
                        this.subjectCatalogNotice = 'No se pudo agregar la materia. Revisa que el nombre no esté repetido.';
                    }
                },

                async updateCatalogColor(subject, color, wire) {
                    try {
                        const updated = await wire.updateSubjectColor(Number(subject.id), color);
                        const index = this.subjects.findIndex(item => Number(item.id) === Number(updated.id));
                        if (index >= 0) this.subjects[index] = updated;

                        this.blocks.forEach(block => {
                            if (Number(block.group_subject_id) === Number(updated.id)) {
                                block.subject_name_snapshot = updated.name;
                                block.subject_color_snapshot = updated.color;
                                block.label = updated.name;
                            }
                        });

                        this.dirty = true;
                        this.subjectCatalogNotice = 'Color actualizado. Guarda el horario para congelar el cambio.';
                    } catch (error) {
                        this.subjectCatalogNotice = 'No se pudo actualizar el color.';
                    }
                },

                setResponsibility(value) {
                    if (!this.editing) return;
                    this.editing.responsibility = value;
                    this.dirty = true;
                },

                selectedButtonClass() {
                    return 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300';
                },

                normalButtonClass() {
                    return 'border-gray-200 bg-white text-gray-600 dark:border-white/10 dark:bg-gray-950 dark:text-gray-300';
                },

                hasField(code) {
                    return Array.isArray(this.editing?.field_codes) && this.editing.field_codes.includes(code);
                },

                toggleField(code) {
                    if (!this.editing) return;
                    const fields = Array.isArray(this.editing.field_codes) ? [...this.editing.field_codes] : [];
                    const index = fields.indexOf(code);
                    if (index >= 0) fields.splice(index, 1);
                    else fields.push(code);
                    this.editing.field_codes = fields;
                    this.dirty = true;
                },

                hasOverlap(day, start, end, ignoreKey = null) {
                    const startMinutes = this.toMinutes(start);
                    const endMinutes = this.toMinutes(end);

                    return this.blocksFor(day).some(block => {
                        if (ignoreKey && block._key === ignoreKey) return false;

                        const blockStart = this.toMinutes(block.starts_at);
                        const blockEnd = this.toMinutes(block.ends_at);

                        return startMinutes < blockEnd && endMinutes > blockStart;
                    });
                },

                sameCopySignature(a, b) {
                    if (!a || !b) return false;

                    const fieldsA = Array.isArray(a.field_codes) ? [...a.field_codes].sort() : [];
                    const fieldsB = Array.isArray(b.field_codes) ? [...b.field_codes].sort() : [];

                    return String(a.starts_at) === String(b.starts_at)
                        && String(a.ends_at) === String(b.ends_at)
                        && String(a.label ?? '') === String(b.label ?? '')
                        && String(a.block_type ?? '') === String(b.block_type ?? '')
                        && String(a.responsibility ?? '') === String(b.responsibility ?? '')
                        && Boolean(a.include_in_planning) === Boolean(b.include_in_planning)
                        && Boolean(a.is_flexible) === Boolean(b.is_flexible)
                        && JSON.stringify(fieldsA) === JSON.stringify(fieldsB)
                        && String(a.notes ?? '') === String(b.notes ?? '');
                },

                equivalentCopyTargets(day) {
                    if (!this.editing || Number(day) === Number(this.editing.day_of_week)) {
                        return [];
                    }

                    return this.blocksFor(day).filter(block => this.sameCopySignature(this.editing, block));
                },

                copyTargetSelected(day) {
                    return this.equivalentCopyTargets(day).length > 0;
                },

                copyTargetConflict(day) {
                    if (!this.editing || Number(day) === Number(this.editing.day_of_week)) {
                        return false;
                    }

                    const equivalentKeys = new Set(this.equivalentCopyTargets(day).map(block => block._key));
                    const startMinutes = this.toMinutes(this.editing.starts_at);
                    const endMinutes = this.toMinutes(this.editing.ends_at);

                    return this.blocksFor(day).some(block => {
                        if (equivalentKeys.has(block._key)) return false;

                        const blockStart = this.toMinutes(block.starts_at);
                        const blockEnd = this.toMinutes(block.ends_at);

                        return startMinutes < blockEnd && endMinutes > blockStart;
                    });
                },

                toggleCopyEditingTo(day) {
                    if (!this.editing || Number(day) === Number(this.editing.day_of_week)) return;

                    const existingCopies = this.equivalentCopyTargets(day);

                    if (existingCopies.length > 0) {
                        const keys = new Set(existingCopies.map(block => block._key));
                        this.blocks = this.blocks.filter(block => !keys.has(block._key));
                        this.editorNotice = 'Se quitó este bloque de ' + this.dayName(day) + '.';
                        this.dirty = true;
                        this.normalize();
                        return;
                    }

                    if (this.copyTargetConflict(day)) {
                        this.editorNotice = 'Ese día ya tiene otra clase ocupando ' + this.editing.starts_at + '–' + this.editing.ends_at + '.';
                        return;
                    }

                    const copy = JSON.parse(JSON.stringify(this.editing));
                    copy._key = 'copy-' + Date.now() + '-' + Math.random().toString(16).slice(2);
                    copy.day_of_week = Number(day);
                    copy.sequence = this.blocksFor(day).length + 1;
                    this.blocks.push(copy);
                    this.editorNotice = 'Bloque copiado a ' + this.dayName(day) + '.';
                    this.dirty = true;
                    this.normalize();
                },

                removeEditing() {
                    if (!this.editing) return;
                    const key = this.editing._key;
                    this.blocks = this.blocks.filter(b => b._key !== key);
                    this.editing = null;
                    this.showAdvanced = false;
                    this.dirty = true;
                    this.normalize();
                },

                normalize() {
                    for (const day of this.days) {
                        this.blocksFor(day.value).forEach((block, index) => block.sequence = index + 1);
                    }
                },

                duration(start, end) {
                    return Math.max(1, this.toMinutes(end) - this.toMinutes(start));
                },

                toMinutes(time) {
                    const [h, m] = String(time || '00:00').split(':').map(Number);
                    return (h * 60) + m;
                },

                fromMinutes(total) {
                    total = Math.max(0, Math.min(23 * 60 + 59, Math.round(total)));
                    return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
                },

                addMinutes(time, minutes) {
                    return this.fromMinutes(this.toMinutes(time) + Number(minutes || 0));
                },

                save(wire) {
                    for (const day of this.days) {
                        const blocks = this.blocksFor(day.value);
                        for (let i = 0; i < blocks.length; i++) {
                            for (let j = i + 1; j < blocks.length; j++) {
                                if (this.hasOverlap(day.value, blocks[i].starts_at, blocks[i].ends_at, blocks[i]._key)
                                    && this.toMinutes(blocks[j].starts_at) < this.toMinutes(blocks[i].ends_at)
                                    && this.toMinutes(blocks[j].ends_at) > this.toMinutes(blocks[i].starts_at)) {
                                    this.editBlock(blocks[i]);
                                    this.editorNotice = 'Hay dos bloques superpuestos en ' + day.label + '. Ajusta sus horarios antes de guardar.';
                                    return;
                                }
                            }
                        }
                    }

                    this.normalize();
                    const clean = this.blocks.map(({ _key, ...block }) => block);
                    wire.set('dayStartsAt', this.dayStart);
                    wire.set('dayEndsAt', this.dayEnd);
                    wire.set('blocks', clean).then(() => wire.saveSchedule()).then(() => {
                        this.dirty = false;
                        this.firstSetup = false;
                        this.hasSavedSchedule = true;
                        this.editMode = false;
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
