{{--
  The default report template.

  Blocks are toggled by the tenant's report template (FR-RPT-2), so a kids report and an adult
  certification report differ by configuration rather than by a code branch. Styling is
  deliberately conservative: this renders through a pure-PHP PDF engine by default, so it uses
  tables and inline styles rather than modern layout.
--}}
<!doctype html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $snapshot['learner_name'] }} — {{ $snapshot['period']['label'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a2332; line-height: 1.5; }
        .head { border-bottom: 2px solid {{ $accent }}; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-size: 15px; font-weight: bold; }
        .sub { font-size: 10px; color: #6b7280; margin-top: 2px; }
        h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: {{ $accent }};
             margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        .stats td { border: 1px solid #e5e7eb; padding: 7px 9px; width: 25%; vertical-align: top; }
        .stats .k { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
        .stats .v { font-size: 13px; font-weight: bold; }
        .work th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280;
                   border-bottom: 1px solid #e5e7eb; padding: 5px 4px; }
        .work td { border-bottom: 1px solid #f1f1ef; padding: 5px 4px; }
        ul { margin: 4px 0; padding-left: 16px; }
        .note { border-left: 2px solid {{ $accent }}; background: #faf7f2; padding: 8px 11px; margin-bottom: 6px; }
        .note .cat { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #92400e; }
        .foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e5e7eb;
                font-size: 8px; color: #9ca3af; text-align: center; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>

<div class="head">
    <div class="brand">{{ $snapshot['brand_name'] }}</div>
    <div class="sub">{{ $snapshot['course_name'] }} · {{ $snapshot['batch_name'] }}
        · {{ $snapshot['period']['label'] }}</div>
</div>

<p><strong>Dear {{ $recipientName }},</strong></p>
<p>Here is how <strong>{{ $snapshot['learner_name'] }}</strong> got on this period.</p>

@if ($template->shows('summary'))
    <h2>Summary</h2>
    <table class="stats">
        <tr>
            <td>
                <div class="k">Attendance</div>
                <div class="v">
                    @if ($snapshot['attendance']['percentage'] === null)
                        —
                    @else
                        {{ $snapshot['attendance']['percentage'] }}%
                    @endif
                </div>
                <div class="k">{{ $snapshot['attendance']['attended'] }} of
                    {{ $snapshot['attendance']['counted'] }} sessions</div>
            </td>
            <td>
                <div class="k">Average</div>
                <div class="v">
                    @if ($snapshot['average']['percentage'] === null)
                        —
                    @else
                        {{ $snapshot['average']['percentage'] }}%
                    @endif
                </div>
                <div class="k">{{ $snapshot['average']['graded'] }} results</div>
            </td>
            <td>
                <div class="k">Work submitted</div>
                <div class="v">{{ $snapshot['average']['graded'] }}
                    of {{ $snapshot['average']['graded'] + $snapshot['average']['missing'] }}</div>
                @if ($snapshot['average']['excluded'] > 0)
                    <div class="k">{{ $snapshot['average']['excluded'] }} excused</div>
                @endif
            </td>
            @if ($template->shows('engagement') && $snapshot['engagement_stars'] !== null)
                <td>
                    <div class="k">Engagement</div>
                    <div class="v">{!! str_repeat('&#9733;', $snapshot['engagement_stars'])
                        . str_repeat('&#9734;', 5 - $snapshot['engagement_stars']) !!}</div>
                </td>
            @endif
        </tr>
    </table>

    @if ($snapshot['attendance']['is_informational'])
        <p class="muted" style="font-size:9px">Attendance is recorded for information at this
            {{ $termFor['batch'] ?? 'class' }} and does not affect the assessment of progress.</p>
    @endif
@endif

@if ($template->shows('highlights') && $snapshot['highlights'] !== [])
    <h2>This period</h2>
    <ul>
        @foreach ($snapshot['highlights'] as $highlight)
            <li>{{ $highlight }}</li>
        @endforeach
    </ul>
@endif

@if ($template->shows('work') && $snapshot['assessments'] !== [])
    <h2>Work set</h2>
    <table class="work">
        <thead>
            <tr><th>Title</th><th>Type</th><th>Due</th><th>Result</th></tr>
        </thead>
        <tbody>
            @foreach ($snapshot['assessments'] as $line)
                <tr>
                    <td>{{ $line['title'] }}</td>
                    <td class="muted">{{ $line['type'] }}</td>
                    <td class="muted">{{ $line['due'] }}</td>
                    <td>
                        {{ $line['result'] ?? '—' }}
                        @if ($line['status'] && $line['result'] === null)
                            <span class="muted">{{ $line['status'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($template->shows('notes') && $snapshot['notes'] !== [])
    <h2>From the teacher</h2>
    @foreach ($snapshot['notes'] as $note)
        <div class="note">
            <div class="cat">{{ $note['category'] }}</div>
            {{ $note['body'] }}
        </div>
    @endforeach
@endif

@if ($template->closing)
    <p style="margin-top:16px">{{ $template->closing }}</p>
@endif

<div class="foot">
    {{ $snapshot['brand_name'] }} · {{ $reportNumber }} ·
    generated {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at_utc'])->toDayDateTimeString() }} UTC
</div>

</body>
</html>
