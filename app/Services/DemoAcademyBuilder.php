<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\GradingSchemeKind;
use App\Enums\PeriodType;
use App\Enums\SessionStatus;
use App\Enums\Weekday;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\GradingScheme;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\NoteCategory;
use App\Models\RelationType;
use App\Models\ReportingPeriod;
use App\Models\SessionType;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Demo\DemoText;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;
use App\Support\Time\PeriodBoundary;
use App\Support\Time\PeriodService;
use App\Support\Time\SessionGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use SplPriorityQueue;

/**
 * One demonstration academy, built from a profile (DemoProfiles): its locations, team, courses
 * and classes, and a term already under way with registers, marked work, notes, status changes and
 * the last finished period's reports.
 *
 * Built through the same services the application uses, never by writing rows directly, so the
 * demo cannot show a state the product itself could not reach. The term is played forward one
 * moment at a time from two months ago with the clock set to that moment, which is why every
 * register, grade and status change carries a believable date and author.
 *
 * Seeded by date and academy, so two builds on the same day produce the same academy. Resolve a
 * new instance for each academy: it holds the state of the one it is building.
 */
final class DemoAcademyBuilder
{
    /** @var array<string, mixed> */
    private array $profile;

    private Randomizer $random;

    private CarbonImmutable $today;

    private CarbonImmutable $termStart;

    private Tenant $tenant;

    /** @var array<string, User> keyed by staff handle */
    private array $staff = [];

    /** @var array<string, Batch> keyed by class handle */
    private array $batches = [];

    /** @var array<string, array{subject: string, topics: list<string>, teacher: string, level: int|null}> keyed by class handle */
    private array $curriculum = [];

    /** @var array<int, array{ability: float, diligence: float, first: string}> keyed by learner id */
    private array $learnerProfiles = [];

    /** @var SplPriorityQueue<array{0: int, 1: int, 2: int}, array{at: CarbonImmutable, run: callable(): void}> */
    private SplPriorityQueue $timeline;

    private int $queued = 0;

    private int $families = 0;

    /** @var array<string, int> */
    private array $counts = ['learners' => 0, 'guardians' => 0, 'enrollments' => 0, 'marks' => 0,
        'assessments' => 0, 'grades' => 0, 'notes' => 0, 'reports' => 0, 'sent' => 0];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly TenantProvisioner $provisioner,
        private readonly BranchService $branches,
        private readonly CourseService $courses,
        private readonly BatchService $batchService,
        private readonly SessionGenerator $generator,
        private readonly SchedulingService $scheduling,
        private readonly LearnerService $learners,
        private readonly GuardianService $guardians,
        private readonly EnrollmentService $enrollments,
        private readonly AttendanceService $attendance,
        private readonly AssessmentService $assessments,
        private readonly GradeBookService $gradebook,
        private readonly TeacherNoteService $notes,
        private readonly ReportService $reports,
        private readonly ReportDeliveryService $deliveries,
        private readonly PeriodService $periods,
    ) {}

    /**
     * @param array<string, mixed> $profile
     * @param callable(string): void|null $progress
     * @return array{tenant: Tenant, counts: array<string, int>}
     */
    public function build(array $profile, string $password, ?callable $progress = null): array
    {
        $exists = $this->tenancy->withoutScoping(
            fn (): bool => Tenant::query()->where('slug', $profile['slug'])->exists(),
        );

        if ($exists) {
            throw new RuntimeException("The demo academy [{$profile['slug']}] already exists. Remove it first.");
        }

        $say = $progress ?? static function (string $message): void {};
        $this->profile = $profile;
        $this->timeline = new SplPriorityQueue;
        $this->today = CarbonImmutable::now($profile['timezone']);
        $this->termStart = $this->today->subMonthsNoOverflow(2)->startOfMonth();
        // The same academy for everyone who builds it on the same day.
        $this->random = new Randomizer(new Mt19937(crc32($profile['slug'].$this->today->format('Ymd'))));

        try {
            // All or nothing: a half-built demo is worse than none, and blocks the next attempt.
            DB::transaction(function () use ($password, $say): void {
                $say("{$this->profile['name']}: the academy, its locations and its team…");
                $this->academy($password);

                $this->tenancy->runAs($this->tenant, function () use ($say): void {
                    $this->classes();
                    $this->people();
                    $this->assessmentPlan();
                    $this->notePlan();
                    $this->reportPlan();
                    $say("{$this->profile['name']}: playing the term forward…");
                    $this->play();
                });
            });
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Auth::forgetUser();
        }

        $this->counts['guardians'] = $this->tenancy->runAs($this->tenant, fn (): int => Guardian::query()->count());

        return ['tenant' => $this->tenant->refresh(), 'counts' => $this->counts];
    }

    // ---------------------------------------------------------------------------------------
    // Structure

    private function academy(string $password): void
    {
        $p = $this->profile;
        [$mainCode, $mainName] = $p['branches'][0];

        $result = $this->provisioner->provision([
            'name' => $p['name'],
            'owner_name' => $p['owner'][0],
            'owner_email' => $p['owner'][1].'@'.$p['domain'],
            'password' => $password,
            // Confirmed, so the demo can email reports (to reserved addresses) like a real account.
            'email_verified_at' => now(),
            'timezone' => $p['timezone'],
            'country' => $p['country'],
            'preset_code' => $p['preset'],
            'status' => Tenant::STATUS_ACTIVE,
            'branch_name' => $mainName,
            'week_start' => $p['week_start'],
            'weekend_days' => $p['weekend'],
        ]);

        $this->tenant = $result['tenant'];
        $this->tenancy->withoutScoping(fn () => $this->tenant->update(['slug' => $p['slug']]));

        $this->tenancy->runAs($this->tenant, function () use ($result, $password, $p): void {
            /** @var Brand $brand */
            $brand = $result['brand'];
            $brand->update(['code' => $p['brand_code'], 'sender_email' => 'hello@'.$p['domain']]);

            foreach ($p['branches'] as $i => [$code, $name, $address, $phone]) {
                $details = [
                    'code' => $code,
                    'name' => $name,
                    'address' => $address,
                    'phone' => $phone,
                    'email' => strtolower(preg_replace('/\W+/', '', $name) ?? $code).'@'.$p['domain'],
                ];

                $i === 0
                    ? $result['branch']->update($details)
                    : $this->branches->create($brand, $details + [
                        'timezone' => $p['timezone'],
                        'week_start' => $p['week_start'],
                        'weekend_days' => $p['weekend'],
                        'is_active' => true,
                    ]);
            }

            if (isset($p['terminology'])) {
                app(Terminology::class)->set(array_map(fn (array $t) => [$t[0], $t[1]], $p['terminology']));
            }

            $this->staff['owner'] = $result['owner'];

            foreach ($p['staff'] as [$handle, $name, $mailbox, $role, $branchCodes]) {
                $this->staffMember($handle, $name, $mailbox, $role, $password, $branchCodes);
            }
        });
    }

    /** @param list<string> $branchCodes empty for every location */
    private function staffMember(string $handle, string $name, string $mailbox, string $role, string $password, array $branchCodes): void
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $mailbox.'@'.$this->profile['domain'],
            'password' => Hash::make($password),
            'is_active' => true,
            'scope_all_branches' => $branchCodes === [],
            'locale' => 'en',
            'timezone' => $this->profile['timezone'],
            'email_verified_at' => now(),
        ]);

        $user->syncRoles([$role]);

        foreach (Branch::query()->whereIn('code', $branchCodes)->get() as $branch) {
            $user->branches()->attach($branch->getKey(), ['tenant_id' => $this->tenant->getKey()]);
        }

        $this->staff[$handle] = $user->fresh() ?? $user;
    }

    private function classes(): void
    {
        $p = $this->profile;
        $brand = Brand::query()->firstOrFail();
        $periodType = PeriodType::from($p['period']['type']);
        $courses = [];

        foreach ($p['courses'] as [$code, $name, $audience, $about]) {
            $courses[$code] = $this->courses->create([
                'brand_id' => $brand->getKey(),
                'code' => $code,
                'name' => $name,
                'audience' => $audience,
                'description' => $about,
                'period_type' => $periodType,
                'period_block_weeks' => $p['period']['weeks'] ?? 4,
                'is_active' => true,
            ]);

            if ($periodType === PeriodType::Term) {
                $this->terms($courses[$code]);
            }
        }

        // A closure three weeks out: sessions generated over it are skipped, and the timetable
        // says why.
        foreach (Branch::query()->get() as $branch) {
            Holiday::query()->create([
                'branch_id' => $branch->getKey(),
                'date' => $this->today->addDays(19)->toDateString(),
                'name' => $p['closure'],
                'blocks_sessions' => true,
            ]);
        }

        foreach ($p['classes'] as [$handle, $course, $branch, $name, $code, $capacity, $teacher, $slots, $minutes, $subject, $topics, , , $level]) {
            $this->batch($handle, $courses[$course], Branch::query()->where('code', $branch)->firstOrFail(),
                $name, $code, $capacity, $teacher, $slots, $minutes, $subject, $topics, $level);
        }
    }

    /** Terms are set by the school, so they are written down rather than computed. */
    private function terms(Course $course): void
    {
        $weeks = (int) ($this->profile['period']['weeks'] ?? 8);
        $start = $this->termStart;
        $end = $this->today->addMonthsNoOverflow(2)->endOfMonth();

        for ($n = 1; $start->lessThanOrEqualTo($end); $n++) {
            $last = $start->addWeeks($weeks)->subDay();

            ReportingPeriod::query()->create([
                'course_id' => $course->getKey(),
                'batch_id' => null,
                'type' => PeriodType::Term,
                'label' => sprintf('Term %d (%s – %s)', $n, $start->format('j M'), $last->format('j M Y')),
                'starts_local_date' => $start->toDateString(),
                'ends_local_date' => $last->toDateString(),
                'status' => ReportingPeriod::STATUS_OPEN,
            ]);

            $start = $last->addDay();
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $slots
     * @param list<string> $topics
     */
    private function batch(string $handle, Course $course, Branch $branch, string $name, string $code, int $capacity,
        string $teacher, array $slots, int $minutes, string $subject, array $topics, ?int $level): void
    {
        $batch = $this->batchService->create($course, $branch, [
            'name' => $name,
            'code' => $code,
            'starts_on' => $this->termStart->toDateString(),
            'ends_on' => $this->today->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
            'capacity' => $capacity,
            'status' => BatchStatus::Running,
            'delivery_mode' => str_contains(strtolower($branch->name), 'online') || str_contains(strtolower($branch->name), 'virtual')
                ? DeliveryMode::Online : DeliveryMode::InPerson,
        ]);

        $class = SessionType::query()->where('code', 'CLASS')->firstOrFail();

        foreach ($slots as [$day, $time]) {
            $this->batchService->addSlot($batch, [
                'session_type_id' => $class->getKey(),
                'weekday' => Weekday::fromName($day),
                'start_time_local' => $time.':00',
                'duration_min' => $minutes,
            ]);
        }

        $this->batchService->assignTeachers($batch, [['user_id' => $this->staff[$teacher]->getKey(), 'role' => 'lead']]);

        $this->generator->generate(
            $batch->refresh(),
            $this->termStart,
            CarbonImmutable::parse((string) $batch->ends_on->toDateString(), $batch->timezone),
        );

        $this->batches[$handle] = $batch->refresh();
        $this->curriculum[$handle] = ['subject' => $subject, 'topics' => $topics, 'teacher' => $teacher, 'level' => $level];
    }

    // ---------------------------------------------------------------------------------------
    // People

    private function people(): void
    {
        $households = [];

        foreach ($this->profile['classes'] as $class) {
            [$handle] = $class;
            [$count, $ages] = [$class[11], $class[12]];

            for ($i = 0; $i < $count; $i++) {
                $learner = match ($this->profile['people']) {
                    // About one child in eight shares parents with someone already enrolled:
                    // siblings are the case a guardian list most often gets wrong.
                    'family' => $this->child(
                        ($households !== [] && $this->chance(0.12))
                            ? $households[$this->random->getInt(0, count($households) - 1)]
                            : $households[] = $this->household(),
                        $ages,
                    ),
                    default => $this->adult($ages),
                };

                // Most start with the term; a few join in its first five weeks.
                $joins = $this->chance(0.15) ? $this->termStart->addDays($this->random->getInt(7, 35)) : $this->termStart;

                // Just after midnight, so the first register of their first day already has them.
                $this->at($joins->setTime(0, 5), 0, function () use ($learner, $handle, $joins): void {
                    $this->enrollments->enroll($learner, $this->batches[$handle], $joins->toDateString());
                    $this->counts['enrollments']++;
                });
            }
        }

        $this->statusChanges();
    }

    /** @return array{surname: string, guardians: list<array<string, mixed>>} */
    private function household(): array
    {
        $this->families++;
        $surname = $this->pick(DemoText::NAMES[$this->profile['names']]['family']);
        $relations = RelationType::query()->pluck('id', 'code');

        $parent = fn (string $first, string $relation): array => [
            'name' => $first.' '.$surname,
            'relation_type_id' => $relations[$relation] ?? null,
            'email' => $this->mailbox($first, $surname, 'example.com', $this->families),
            'phone' => $this->phone(),
            'preferred_channel' => 'email',
        ];

        $father = $parent($this->pick(DemoText::PARENTS['m']), 'FATHER');
        $mother = $parent($this->pick(DemoText::PARENTS['f']), 'MOTHER');
        [$primary, $other] = $this->chance(0.55) ? [$mother, $father] : [$father, $mother];

        // A few families give the front desk a phone number and nothing else. Their children have
        // nobody to email a report to, which is exactly what the warnings are for.
        if ($this->chance(0.06)) {
            $primary['email'] = null;
            $primary['preferred_channel'] = 'phone';
        }

        $guardians = [$primary + ['receives' => true, 'is_primary' => true]];

        if ($this->chance(0.4)) {
            $guardians[] = $other + ['receives' => $this->chance(0.3), 'is_primary' => false];
        }

        return ['surname' => $surname, 'guardians' => $guardians];
    }

    /**
     * @param array{surname: string, guardians: list<array<string, mixed>>} $household
     * @param array{0: int, 1: int} $ages
     */
    private function child(array $household, array $ages): Learner
    {
        [$first, $gender] = $this->givenName();
        $learner = $this->learner($first, $household['surname'], $gender, $ages, null, null);

        foreach ($household['guardians'] as $guardian) {
            $this->guardians->attachOrCreate(
                $learner,
                array_diff_key($guardian, ['receives' => true, 'is_primary' => true]),
                isPrimary: $guardian['is_primary'],
                receivesReports: $guardian['receives'],
            );
        }

        return $learner;
    }

    /**
     * An adult learner: their own email and phone, and for those an employer sends, the
     * employer's contact as a sponsor who receives the reports.
     *
     * @param array{0: int, 1: int} $ages
     */
    private function adult(array $ages): Learner
    {
        [$first, $gender] = $this->givenName();
        $surname = $this->pick(DemoText::NAMES[$this->profile['names']]['family']);
        $sponsor = $this->sponsors() !== [] && $this->chance((float) ($this->profile['sponsored_share'] ?? 0))
            ? $this->pick($this->sponsors())
            : null;

        // A few adults never gave an email: the report screen says so before anyone sends.
        $email = $sponsor === null && $this->chance(0.05)
            ? null
            : $this->mailbox($first, $surname, $sponsor[1] ?? 'example.com', $this->counts['learners']);

        $learner = $this->learner($first, $surname, $gender, $ages, $email, $this->phone());

        if ($sponsor !== null) {
            [$company, $domain, $contact] = $sponsor;

            $this->guardians->attachOrCreate($learner, [
                'name' => $contact.' ('.$company.')',
                'relation_type_id' => RelationType::query()->where('code', 'SPONSOR')->value('id'),
                'email' => 'learning@'.$domain,
                'preferred_channel' => 'email',
            ], isPrimary: true, receivesReports: true);
        }

        return $learner;
    }

    /** @param array{0: int, 1: int} $ages */
    private function learner(string $first, string $surname, string $gender, array $ages, ?string $email, ?string $phone): Learner
    {
        $age = $this->random->getInt($ages[0], $ages[1]);
        $born = $this->today->subYears($age + 1)->addDays($this->random->getInt(1, 364));

        $learner = $this->learners->create([
            'legal_name' => $first.' '.$surname,
            'preferred_name' => isset(DemoText::NICKNAMES[$first]) && $this->chance(0.5) ? DemoText::NICKNAMES[$first] : null,
            'sort_name' => $surname.', '.$first,
            'date_of_birth' => $born->toDateString(),
            'gender' => $gender === 'f' ? 'female' : 'male',
            'country' => $this->profile['country'],
            'home_timezone' => $this->profile['timezone'],
            'email' => $email,
            'phone' => $phone,
            'status_changed_on' => $this->termStart->toDateString(),
        ]);

        $this->counts['learners']++;

        // How each learner tends to do: a little better or worse than average, rarely extreme.
        $this->learnerProfiles[$learner->getKey()] = [
            'ability' => $this->clamp($this->bell(0.72, 0.14), 0.32, 0.98),
            'diligence' => $this->clamp($this->bell(0.9, 0.07), 0.68, 0.995),
            'first' => $first,
        ];

        return $learner;
    }

    /** One withdrawal, one move between classes and one pause, at believable points in the term. */
    private function statusChanges(): void
    {
        $events = $this->profile['events'];
        $status = fn (string $code): EnrollmentStatus => EnrollmentStatus::query()->where('code', $code)->firstOrFail();
        $nth = fn (string $handle, int $n): ?Enrollment => Enrollment::query()
            ->where('batch_id', $this->batches[$handle]->getKey())->active()->orderBy('id')->skip($n)->first();

        $this->at($this->today->subDays(40)->setTime(11, 0), 1, function () use ($events, $status, $nth): void {
            [$handle, $reason] = $events['withdraw'];
            $enrollment = $nth($handle, 4);

            if ($enrollment !== null) {
                $this->enrollments->changeStatus($enrollment, $status('WITHDRAWN'), $reason);
                $this->learners->changeStatus(
                    $enrollment->learner,
                    LearnerStatus::query()->where('code', 'WITHDRAWN')->firstOrFail(),
                    $reason,
                );
            }
        });

        $this->at($this->today->subDays(30)->setTime(10, 0), 1, function () use ($events, $nth): void {
            [$from, $to, $reason] = $events['transfer'];
            $enrollment = $nth($from, 6);

            if ($enrollment !== null) {
                $this->enrollments->transfer($enrollment, $this->batches[$to], $reason);
                $this->counts['enrollments']++;
            }
        });

        $this->at($this->today->subDays(18)->setTime(12, 0), 1, function () use ($events, $status, $nth): void {
            [$handle, $reason] = $events['hold'];
            $enrollment = $nth($handle, 2);

            if ($enrollment !== null) {
                $this->enrollments->changeStatus($enrollment, $status('ON_HOLD'), $reason);
            }
        });
    }

    // ---------------------------------------------------------------------------------------
    // Teaching

    private function assessmentPlan(): void
    {
        $types = AssessmentType::query()->pluck('id', 'code')->all();
        $schemes = GradingScheme::query()->pluck('id', 'code')->all();
        $rhythm = $this->profile['rhythm'];

        foreach ($this->batches as $handle => $batch) {
            $topics = $this->curriculum[$handle]['topics'];
            $week = 0;

            for ($assigned = $this->termStart->addDays(3); $assigned->lessThan($this->today); $assigned = $assigned->addWeek()) {
                $topic = $topics[intdiv($week, 2) % count($topics)];
                $this->plan($batch, $assigned, $rhythm[$week % count($rhythm)], $topic, $types, $schemes);
                $week++;
            }

            // The bigger piece, set once at the start of each reporting period.
            foreach ($this->periods->forBatch($batch) as $i => $period) {
                $assigned = CarbonImmutable::parse($period->startsLocalDate->toDateString(), $this->profile['timezone'])->addDays(2);

                if ($assigned->lessThan($this->today)) {
                    $this->plan($batch, $assigned, $this->profile['per_period'], $topics[$i % count($topics)], $types, $schemes);
                }
            }
        }
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: int|null, 4: int} $kind
     * @param array<string, int> $types
     * @param array<string, int> $schemes
     */
    private function plan(Batch $batch, CarbonImmutable $assigned, array $kind, string $topic, array $types, array $schemes): void
    {
        [$type, $scheme, $prefix, $max, $dueIn] = $kind;

        if (! isset($types[$type], $schemes[$scheme])) {
            return;
        }

        $due = $assigned->addDays($dueIn);

        $this->at($assigned->setTime(8, 0), 2, function () use ($batch, $assigned, $due, $type, $scheme, $prefix, $max, $topic, $types, $schemes): void {
            $assessment = $this->assessments->publish($this->assessments->create($batch, array_filter([
                'assessment_type_id' => $types[$type],
                'grading_scheme_id' => $schemes[$scheme],
                'title' => $prefix.': '.$topic,
                'assigned_local_date' => $assigned->toDateString(),
                'due_local_date' => $due->toDateString(),
                'max_points' => $max,
            ], fn ($v) => $v !== null), $scheme === 'RUBRIC' ? $this->criteria() : []));

            $this->counts['assessments']++;

            // Marked two days after it is due; anything due in the last couple of days is still in
            // the teacher's pile, which is what a real grade book looks like on any given day.
            $marked = $due->addDays(2)->setTime(19, 30);

            if ($marked->lessThan($this->today)) {
                $this->at($marked, 3, fn () => $this->grade($batch, $assessment));
            }
        });
    }

    /** @return list<array{name: string, max_points: float}> */
    private function criteria(): array
    {
        return match ($this->profile['kind']) {
            'language' => [['name' => 'Task achievement', 'max_points' => 10.0], ['name' => 'Coherence and organisation', 'max_points' => 5.0],
                ['name' => 'Grammar and vocabulary', 'max_points' => 5.0]],
            'skills' => [['name' => 'Working solution', 'max_points' => 10.0], ['name' => 'Code quality and tests', 'max_points' => 5.0],
                ['name' => 'Documentation', 'max_points' => 5.0]],
            'corporate' => [['name' => 'Analysis of the situation', 'max_points' => 10.0], ['name' => 'Application to own team', 'max_points' => 5.0],
                ['name' => 'Clarity of the action plan', 'max_points' => 5.0]],
            default => [['name' => 'Understanding of the topic', 'max_points' => 10.0], ['name' => 'Working shown clearly', 'max_points' => 5.0],
                ['name' => 'Presentation and neatness', 'max_points' => 5.0]],
        };
    }

    private function grade(Batch $batch, Assessment $assessment): void
    {
        $assessment->loadMissing(['gradingScheme', 'rubricCriteria']);
        $statuses = SubmissionStatus::query()->pluck('id', 'code');
        $handle = $this->handleOf($batch);
        $this->actAs($this->curriculum[$handle]['teacher']);
        $cells = [];

        $enrollments = Enrollment::query()->where('batch_id', $batch->getKey())->active()
            ->where('enrolled_on', '<=', $assessment->due_local_date?->toDateString() ?? $this->today->toDateString())
            ->get();

        foreach ($enrollments as $enrollment) {
            $profile = $this->learnerProfiles[$enrollment->learner_id] ?? ['ability' => 0.7, 'diligence' => 0.9];
            $score = $this->clamp($profile['ability'] + $this->bell(0, 0.09), 0.05, 1.0);
            $missing = ! $this->chance(0.55 + $profile['diligence'] * 0.45);
            $late = ! $missing && $this->chance(0.06);
            $status = $missing ? 'MISSING' : ($late ? 'LATE_SUB' : 'SUBMITTED');

            if (! isset($statuses[$status])) {
                continue;
            }

            $cell = [
                'assessment_id' => $assessment->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $statuses[$status],
                'feedback' => $missing ? null : $this->pick(DemoText::FEEDBACK[$score >= 0.8 ? 'high' : ($score >= 0.55 ? 'mid' : 'low')]),
            ];

            if (! $missing) {
                $cell += $this->result($assessment, $score, $this->curriculum[$handle]['level']);
            }

            $cells[] = $cell;
        }

        if ($cells !== []) {
            $this->gradebook->saveGrid($batch, $cells);
            $this->counts['grades'] += count($cells);
        }
    }

    /** @return array<string, mixed> the result, in the terms of the assessment's own scheme */
    private function result(Assessment $assessment, float $score, ?int $level): array
    {
        $scheme = $assessment->gradingScheme;

        return match ($scheme->kind) {
            GradingSchemeKind::PassFail => ['passed' => $score >= 0.45],
            GradingSchemeKind::Rubric => ['rubric_scores' => $assessment->rubricCriteria->mapWithKeys(
                fn ($criterion) => [$criterion->getKey() => round((float) $criterion->max_points * $this->clamp($score + $this->bell(0, 0.06), 0.1, 1.0))],
            )->all()],
            GradingSchemeKind::Percentage => ['raw_score' => round($score * 100)],
            // A level near the class's own: most learners at it, a few a step either side.
            GradingSchemeKind::Level => ['level_code' => $this->levelFor($scheme, $score, $level ?? 2)],
            default => ['raw_score' => round((float) $assessment->max_points * $score)],
        };
    }

    private function levelFor(GradingScheme $scheme, float $score, int $base): string
    {
        $ladder = array_values($scheme->config['ladder'] ?? ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);
        $index = max(0, min(count($ladder) - 1, $base + (int) round(($score - 0.68) * 3)));

        return (string) $ladder[$index];
    }

    /**
     * Take every register up to today, except the latest one in two classes: on any real day
     * a few registers are still waiting, and the product has a screen for exactly that.
     */
    private function registers(): void
    {
        $leaveOpen = [];

        foreach (array_slice(array_keys($this->batches), 2, 2) as $handle) {
            $leaveOpen[] = ClassSession::query()->where('batch_id', $this->batches[$handle]->getKey())
                ->where('starts_at_utc', '<', now())->orderByDesc('starts_at_utc')->value('id');
        }

        $sessions = ClassSession::query()
            ->whereIn('batch_id', array_map(fn (Batch $b) => $b->getKey(), $this->batches))
            ->where('status', SessionStatus::Scheduled)
            ->where('ends_at_utc', '<', CarbonImmutable::now())
            ->whereNotIn('id', array_filter($leaveOpen))
            ->orderBy('starts_at_utc')
            ->get();

        // Three classes this term did not happen, and were cancelled with a reason.
        $cancel = collect(range(1, 3))
            ->map(fn () => $sessions->get($this->random->getInt(0, max(0, $sessions->count() - 1)))?->getKey())
            ->filter()->flip();

        foreach ($sessions as $session) {
            if ($cancel->has($session->getKey())) {
                $this->at(CarbonImmutable::parse($session->starts_at_utc)->subHours(3), 4, function () use ($session): void {
                    $this->actAs('manager');
                    $this->scheduling->cancel($session->refresh(), $this->pick($this->cancellations()));
                });

                continue;
            }

            $this->at(CarbonImmutable::parse($session->ends_at_utc)->addMinutes(5), 5, fn () => $this->register($session));
        }
    }

    private function register(ClassSession $session): void
    {
        $session->refresh()->loadMissing('batch');

        if ($session->status !== SessionStatus::Scheduled) {
            return;
        }

        $statuses = AttendanceStatus::query()->pluck('id', 'code');
        $people = $this->profile['people'];
        $this->actAs($this->curriculum[$this->handleOf($session->batch)]['teacher']);
        $marks = [];

        foreach ($this->attendance->roster($session) as $row) {
            $profile = $this->learnerProfiles[$row['learner_id']] ?? ['diligence' => 0.9];
            $code = 'PRESENT';
            $minutes = null;
            $note = null;

            if (! $this->chance($profile['diligence'])) {
                $code = $this->chance(0.35) ? 'EXCUSED' : 'ABSENT';
                $note = $code === 'EXCUSED' ? $this->pick(DemoText::EXCUSES[$people]) : null;
            } elseif ($this->chance(0.07)) {
                $code = 'LATE';
                $minutes = $this->random->getInt(5, 25);
                $note = $this->pick(DemoText::LATE_NOTES[$people]);
            }

            if (! isset($statuses[$code])) {
                $code = 'PRESENT';
            }

            $marks[] = ['enrollment_id' => $row['enrollment_id'], 'status_id' => $statuses[$code],
                'minutes_late' => $minutes, 'note' => $note];
        }

        if ($marks !== []) {
            $this->attendance->record($session, $marks);
            $this->counts['marks'] += count($marks);
        }
    }

    /** Comments at the end of every finished period, and for most learners in the current one. */
    private function notePlan(): void
    {
        foreach ($this->batches as $handle => $batch) {
            foreach ($this->periods->forBatch($batch) as $i => $period) {
                $start = CarbonImmutable::parse($period->startsLocalDate->toDateString(), $this->profile['timezone']);
                $end = CarbonImmutable::parse($period->endsLocalDate->toDateString(), $this->profile['timezone']);

                if ($start->greaterThanOrEqualTo($this->today)) {
                    break;
                }

                $finished = $end->lessThan($this->today);
                $writtenOn = $finished ? $end->subDays(2)->setTime(18, 0) : $this->today->subDay()->setTime(18, 0);

                if ($writtenOn->lessThan($start)) {
                    continue;
                }

                $this->at($writtenOn, 6, fn () => $this->periodNotes($handle, $batch, $period, $i, $finished ? 1.0 : 0.6));
            }
        }
    }

    private function periodNotes(string $handle, Batch $batch, PeriodBoundary $period, int $index, float $share): void
    {
        $this->actAs($this->curriculum[$handle]['teacher']);
        $categories = NoteCategory::query()->pluck('id', 'code');
        $bank = DemoText::NOTES[$this->profile['kind']] ?? DemoText::NOTES['kids'];
        $label = $this->periods->ensure($batch, $period)->label;
        $notes = [];

        foreach (Enrollment::query()->where('batch_id', $batch->getKey())->active()->get() as $enrollment) {
            if (! $this->chance($share)) {
                continue;
            }

            $profile = $this->learnerProfiles[$enrollment->learner_id] ?? ['ability' => 0.7, 'diligence' => 0.9, 'first' => 'This learner'];
            $band = $profile['ability'] >= 0.82 ? 'strong' : ($profile['ability'] >= 0.6 && $profile['diligence'] >= 0.8 ? 'steady' : 'support');
            $category = $band === 'support' && $this->chance(0.3) ? 'PARTICIPATION' : 'ACADEMIC';

            $notes[] = [
                'enrollment_id' => $enrollment->getKey(),
                'note_category_id' => $categories[$category] ?? $categories->first(),
                // A different sentence each period for the same learner, as a teacher would write.
                'body' => strtr($bank[$band][($enrollment->learner_id + $index) % count($bank[$band])], [
                    '{name}' => $profile['first'],
                    '{subject}' => $this->curriculum[$handle]['subject'],
                ]),
            ];
        }

        if ($notes !== []) {
            $this->counts['notes'] += $this->notes->writeMany($notes, $label)['written'];
        }
    }

    /**
     * The last finished period's reports, generated a few days after it ended: sent for some
     * classes, generated but not yet sent for others, not started for the rest — the three states
     * a director checks.
     */
    private function reportPlan(): void
    {
        $plan = array_fill_keys($this->profile['reports']['send'], true) + array_fill_keys($this->profile['reports']['generate'], false);

        foreach ($plan as $handle => $send) {
            $batch = $this->batches[$handle];
            $finished = array_values(array_filter(
                $this->periods->forBatch($batch),
                fn (PeriodBoundary $p) => CarbonImmutable::parse($p->endsLocalDate->toDateString(), $this->profile['timezone'])->lessThan($this->today->subDays(3)),
            ));

            if ($finished === []) {
                continue;
            }

            $period = end($finished);
            $on = CarbonImmutable::parse($period->endsLocalDate->toDateString(), $this->profile['timezone'])->addDays(3)->setTime(10, 30);

            $this->at($on, 7, function () use ($batch, $period, $send): void {
                $this->actAs('manager');

                foreach (Enrollment::query()->where('batch_id', $batch->getKey())->active()->get() as $enrollment) {
                    $report = $this->reports->generate($enrollment, $period, ['require_complete' => false]);
                    $this->counts['reports']++;

                    if (! $send) {
                        continue;
                    }

                    // Sent there and then, at the simulated time, rather than left for a worker
                    // to send today. Every address is on a reserved domain.
                    $queue = config('queue.default');
                    config(['queue.default' => 'sync']);

                    try {
                        $this->counts['sent'] += $this->deliveries->queue($report)->count();
                    } catch (ValidationException) {
                        // Nobody to send to: left visible in the archive, as it would be.
                    } finally {
                        config(['queue.default' => $queue]);
                    }
                }
            });
        }
    }

    // ---------------------------------------------------------------------------------------
    // The clock

    private function play(): void
    {
        // Enrollments, status changes and plans are already queued; registers are planned now
        // because they depend on which sessions exist. Moments can queue later ones (an
        // assessment queues its marking), so this is a queue drained in time order, not a list
        // sorted once.
        $this->registers();

        $now = CarbonImmutable::now();

        while (! $this->timeline->isEmpty()) {
            /** @var array{at: CarbonImmutable, run: callable(): void} $moment */
            $moment = $this->timeline->extract();

            if ($moment['at']->greaterThan($now)) {
                continue;
            }

            Carbon::setTestNow($moment['at']);
            CarbonImmutable::setTestNow($moment['at']);
            ($moment['run'])();
        }

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    /** @param callable(): void $run */
    private function at(CarbonImmutable $at, int $order, callable $run): void
    {
        // Earliest first; at the same instant, lower order first, then the order queued.
        $this->timeline->insert(['at' => $at, 'run' => $run], [-$at->getTimestamp(), -$order, -$this->queued++]);
    }

    private function actAs(string $handle): void
    {
        Auth::setUser($this->staff[$handle] ?? $this->staff['owner']);
    }

    private function handleOf(Batch $batch): string
    {
        return (string) array_search($batch->getKey(), array_map(fn (Batch $b) => $b->getKey(), $this->batches), true);
    }

    // ---------------------------------------------------------------------------------------
    // People's details

    /**
     * The companies that send people, as [company, email domain, contact].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function sponsors(): array
    {
        return array_values(array_map(
            fn (array $s): array => [(string) $s[0], (string) $s[1], (string) $s[2]],
            array_filter((array) ($this->profile['sponsors'] ?? []), is_array(...)),
        ));
    }

    /** @return list<string> */
    private function cancellations(): array
    {
        return array_values(array_map(strval(...), (array) $this->profile['cancellations']));
    }

    /** @return array{0: string, 1: string} */
    private function givenName(): array
    {
        return $this->pick(DemoText::NAMES[$this->profile['names']]['given']);
    }

    private function mailbox(string $first, string $surname, string $domain, int $n): string
    {
        // Plain ASCII local parts: "Côté" and "D'Souza" become "cote" and "dsouza".
        $ascii = fn (string $s): string => strtolower((string) preg_replace('/[^a-z]/i', '', (string) (iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s)));

        return $ascii($first).'.'.$ascii($surname).$n.'@'.$domain;
    }

    private function phone(): string
    {
        $prefix = (string) $this->profile['phone'];
        // North America's 555-01xx block is reserved for fiction; elsewhere the block is invented.
        $digits = str_ends_with($prefix, '555 01') ? 2 : (str_ends_with($prefix, ' ') ? 4 : 5);

        return $prefix.str_pad((string) $this->random->getInt(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------------------------------
    // Chance

    private function chance(float $probability): bool
    {
        return $this->random->nextFloat() < $probability;
    }

    /**
     * @template T
     *
     * @param array<int, T> $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /** Roughly normal, from the sum of three uniforms: no long tails, which suits marks. */
    private function bell(float $mean, float $spread): float
    {
        $sum = $this->random->nextFloat() + $this->random->nextFloat() + $this->random->nextFloat();

        return $mean + ($sum - 1.5) * $spread * 2;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
