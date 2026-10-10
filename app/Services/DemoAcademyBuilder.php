<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BatchStatus;
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
use App\Models\SessionType;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Demo\DemoText;
use App\Support\Tenancy\TenantContext;
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
 * A complete academy for demonstrations: two locations, a teaching team, six classes and a term
 * already under way, with attendance, grades, notes and last month's reports.
 *
 * Built through the same services the application uses, never by writing rows directly, so the
 * demo cannot show a state the product itself could not reach. The term is played forward one
 * moment at a time from two months ago with the clock set to that moment, which is why every
 * register, grade and status change carries a believable date and author.
 *
 * Seeded, so two builds on the same day produce the same academy.
 */
final class DemoAcademyBuilder
{
    public const SLUG = 'demo-himalayan-scholars';

    public const NAME = 'Himalayan Scholars Academy';

    public const OWNER_EMAIL = 'sunita@'.DemoText::STAFF_DOMAIN;

    private const TIMEZONE = 'Asia/Kathmandu';

    private Randomizer $random;

    private CarbonImmutable $today;

    private CarbonImmutable $termStart;

    private Tenant $tenant;

    /** @var array<string, User> keyed by role handle */
    private array $staff = [];

    /** @var array<string, Batch> keyed by batch handle */
    private array $batches = [];

    /** @var array<string, array{subject: string, topics: list<string>, teacher: string}> keyed by batch handle */
    private array $curriculum = [];

    /** @var array<int, array{ability: float, diligence: float, first: string}> keyed by learner id */
    private array $profiles = [];

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

    public function exists(): bool
    {
        return $this->tenancy->withoutScoping(
            fn (): bool => Tenant::query()->where('slug', self::SLUG)->exists(),
        );
    }

    /**
     * @param callable(string): void|null $progress
     * @return array{tenant: Tenant, counts: array<string, int>}
     */
    public function build(string $password, ?callable $progress = null): array
    {
        if ($this->exists()) {
            throw new RuntimeException('The demo academy already exists. Remove it first.');
        }

        $say = $progress ?? static function (string $message): void {};
        $this->timeline = new SplPriorityQueue;
        $this->today = CarbonImmutable::now(self::TIMEZONE);
        $this->termStart = $this->today->subMonthsNoOverflow(2)->startOfMonth();
        // The same academy for everyone who builds it on the same day.
        $this->random = new Randomizer(new Mt19937((int) $this->today->format('Ymd')));

        try {
            // All or nothing: a half-built demo is worse than none, and blocks the next attempt.
            DB::transaction(function () use ($password, $say): void {
                $say('Creating the academy, its locations and its team…');
                $this->academy($password);

                $this->tenancy->runAs($this->tenant, function () use ($say): void {
                    $say('Setting up courses, classes and timetables…');
                    $this->classes();
                    $say('Enrolling students and their families…');
                    $this->people();
                    $this->assessmentPlan();
                    $this->notePlan();
                    $this->reportPlan();
                    $say('Playing the term forward: registers, grades, notes and reports…');
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
        $result = $this->provisioner->provision([
            'name' => self::NAME,
            'owner_name' => 'Sunita Adhikari',
            'owner_email' => self::OWNER_EMAIL,
            'password' => $password,
            // Confirmed, so the demo can email reports (to reserved addresses) like a real account.
            'email_verified_at' => now(),
            'timezone' => self::TIMEZONE,
            'country' => 'NP',
            'preset_code' => 'kids-tutoring-south-asia',
            'status' => Tenant::STATUS_ACTIVE,
            'branch_name' => 'Baneshwor',
            'week_start' => 'sunday',
            'weekend_days' => ['saturday'],
        ]);

        $this->tenant = $result['tenant'];
        $this->tenancy->withoutScoping(fn () => $this->tenant->update(['slug' => self::SLUG]));

        $this->tenancy->runAs($this->tenant, function () use ($result, $password): void {
            /** @var Brand $brand */
            $brand = $result['brand'];
            $brand->update(['code' => 'HSA', 'sender_email' => 'office@'.DemoText::STAFF_DOMAIN]);

            $result['branch']->update([
                'code' => 'BNS',
                'address' => 'Old Baneshwor, Kathmandu 44600',
                'phone' => '+977 1-4400012',
                'email' => 'baneshwor@'.DemoText::STAFF_DOMAIN,
            ]);

            $this->branches->create($brand, [
                'name' => 'Pulchowk',
                'code' => 'PLC',
                'timezone' => self::TIMEZONE,
                'week_start' => 'sunday',
                'weekend_days' => ['saturday'],
                'address' => 'Pulchowk, Lalitpur 44700',
                'phone' => '+977 1-5500034',
                'email' => 'pulchowk@'.DemoText::STAFF_DOMAIN,
                'is_active' => true,
            ]);

            $this->staff['owner'] = $result['owner'];
            $this->staffMember('manager', 'Rajesh Shrestha', 'rajesh', 'Management', $password);
            $this->staffMember('frontdesk', 'Anisha Maharjan', 'anisha', 'Front desk', $password, ['BNS']);
            $this->staffMember('accounts', 'Prakash Joshi', 'prakash', 'Accountant', $password);
            $this->staffMember('maths', 'Bikash Thapa', 'bikash', 'Teacher', $password, ['BNS', 'PLC']);
            $this->staffMember('science', 'Pooja Gurung', 'pooja', 'Teacher', $password, ['BNS']);
            $this->staffMember('english', 'Suman Karki', 'suman', 'Teacher', $password, ['PLC']);
            $this->staffMember('computer', 'Nirmala Rai', 'nirmala', 'Teacher', $password, ['BNS']);
        });
    }

    /** @param list<string> $branchCodes empty for every location */
    private function staffMember(string $handle, string $name, string $mailbox, string $role, string $password, array $branchCodes = []): void
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $mailbox.'@'.DemoText::STAFF_DOMAIN,
            'password' => Hash::make($password),
            'is_active' => true,
            'scope_all_branches' => $branchCodes === [],
            'locale' => 'en',
            'timezone' => self::TIMEZONE,
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
        $brand = Brand::query()->firstOrFail();
        $bns = Branch::query()->where('code', 'BNS')->firstOrFail();
        $plc = Branch::query()->where('code', 'PLC')->firstOrFail();

        $course = fn (string $code, string $name, string $audience, string $about): Course => $this->courses->create([
            'brand_id' => $brand->getKey(),
            'code' => $code,
            'name' => $name,
            'audience' => $audience,
            'description' => $about,
            'period_type' => PeriodType::Monthly,
            'is_active' => true,
        ]);

        $maths8 = $course('MATH-8', 'Mathematics — Grade 8', 'kids', 'School curriculum support, with weekly homework and a monthly project.');
        $science9 = $course('SCI-9', 'Science — Grade 9', 'teens', 'Physics, chemistry and biology to the CDC Grade 9 syllabus.');
        $english7 = $course('ENG-7', 'English — Grade 7', 'kids', 'Reading, grammar and writing, with a speaking task each month.');
        $see = $course('SEE-MATH', 'SEE Preparation — Mathematics', 'teens', 'Intensive revision and past papers for the Secondary Education Examination.');
        $computer = $course('COMP-JR', 'Computer Basics for Juniors', 'kids', 'Typing, safe internet use and first programs in Scratch, ages 9–12.');

        // A closure three weeks out: sessions generated over it are skipped, and the timetable says why.
        foreach ([$bns, $plc] as $branch) {
            Holiday::query()->create([
                'branch_id' => $branch->getKey(),
                'date' => $this->today->addDays(19)->toDateString(),
                'name' => 'Centre closed — staff training day',
                'blocks_sessions' => true,
            ]);
        }

        $this->batch('maths-am', $maths8, $bns, 'Grade 8 Maths · Morning', 'G8M-AM', 20, 'maths',
            [[Weekday::Sunday, '06:30'], [Weekday::Tuesday, '06:30'], [Weekday::Thursday, '06:30']], 60,
            'Mathematics', ['Algebraic expressions', 'Linear equations', 'Ratio and proportion', 'Simple interest',
                'Area of plane figures', 'Mean, median and mode', 'Sets and Venn diagrams', 'Properties of triangles']);

        $this->batch('maths-pm', $maths8, $plc, 'Grade 8 Maths · Evening', 'G8M-PM', 15, 'maths',
            [[Weekday::Monday, '17:30'], [Weekday::Wednesday, '17:30']], 75,
            'Mathematics', ['Algebraic expressions', 'Linear equations', 'Percentages', 'Simple interest',
                'Area of plane figures', 'Statistics', 'Sets', 'Triangles']);

        $this->batch('science', $science9, $bns, 'Grade 9 Science · Evening', 'G9S-PM', 18, 'science',
            [[Weekday::Monday, '17:00'], [Weekday::Wednesday, '17:00'], [Weekday::Friday, '16:00']], 75,
            'Science', ['Force and motion', 'Reflection of light', 'Acids, bases and salts', 'Cell structure',
                'Electric circuits', 'Heat and temperature', 'Classification of plants', 'Simple machines']);

        $this->batch('english', $english7, $plc, 'Grade 7 English · Afternoon', 'G7E-PM', 16, 'english',
            [[Weekday::Sunday, '16:00'], [Weekday::Wednesday, '16:00']], 60,
            'English', ['Reading comprehension', 'Present and past tenses', 'Writing a letter', 'Reported speech',
                'Vocabulary in context', 'Essay: my neighbourhood', 'Poem recitation', 'Story writing']);

        $this->batch('see', $see, $plc, 'SEE Maths · Intensive', 'SEE-M', 24, 'maths',
            [[Weekday::Sunday, '07:00'], [Weekday::Monday, '07:00'], [Weekday::Tuesday, '07:00'],
                [Weekday::Wednesday, '07:00'], [Weekday::Thursday, '07:00']], 90,
            'Mathematics', ['Compound interest', 'Population growth and depreciation', 'Mensuration: prisms',
                'Algebraic fractions', 'Indices', 'Probability', 'Heights and distances', 'Past paper 2081']);

        $this->batch('computer', $computer, $bns, 'Computer Basics · Saturday', 'CMP-SAT', 12, 'computer',
            [[Weekday::Saturday, '10:00']], 120,
            'Computer', ['Parts of a computer', 'Typing practice', 'Drawing in Paint', 'Scratch: moving a sprite',
                'Staying safe online', 'Scratch: a simple game', 'Files and folders', 'Scratch: quiz game']);
    }

    /**
     * @param list<array{0: Weekday, 1: string}> $slots
     * @param list<string> $topics
     */
    private function batch(string $handle, Course $course, Branch $branch, string $name, string $code, int $capacity,
        string $teacher, array $slots, int $minutes, string $subject, array $topics): void
    {
        $batch = $this->batchService->create($course, $branch, [
            'name' => $name,
            'code' => $code,
            'starts_on' => $this->termStart->toDateString(),
            'ends_on' => $this->today->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
            'capacity' => $capacity,
            'status' => BatchStatus::Running,
        ]);

        $class = SessionType::query()->where('code', 'CLASS')->firstOrFail();

        foreach ($slots as [$day, $time]) {
            $this->batchService->addSlot($batch, [
                'session_type_id' => $class->getKey(),
                'weekday' => $day,
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
        $this->curriculum[$handle] = ['subject' => $subject, 'topics' => $topics, 'teacher' => $teacher];
    }

    // ---------------------------------------------------------------------------------------
    // People

    private function people(): void
    {
        // [batch, how many, school grade]: grade sets the age, and so the date of birth.
        $plan = [['maths-am', 16, 8], ['maths-pm', 9, 8], ['science', 15, 9], ['english', 12, 7], ['see', 20, 10], ['computer', 10, 5]];
        $families = [];

        foreach ($plan as [$handle, $count, $grade]) {
            for ($i = 0; $i < $count; $i++) {
                // About one in eight shares a family with someone already enrolled: siblings are
                // the case a guardian list most often gets wrong.
                $family = ($families !== [] && $this->chance(0.12))
                    ? $families[$this->random->getInt(0, count($families) - 1)]
                    : $families[] = $this->family();

                $learner = $this->learner($family, $grade);

                // Most start with the term; a few join in its first five weeks.
                $joins = $this->chance(0.15)
                    ? $this->termStart->addDays($this->random->getInt(7, 35))
                    : $this->termStart;

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
    private function family(): array
    {
        $this->families++;
        $surname = $this->pick(DemoText::SURNAMES);
        $relations = RelationType::query()->pluck('id', 'code');
        $mailbox = fn (string $first): string => strtolower($first.'.'.preg_replace('/\W/', '', $surname))
            .$this->families.'@'.DemoText::FAMILY_DOMAIN;
        $phone = fn (): string => '+977 98000'.str_pad((string) $this->random->getInt(0, 99999), 5, '0', STR_PAD_LEFT);

        $parent = fn (string $first, string $relation): array => [
            'name' => $first.' '.$surname,
            'relation_type_id' => $relations[$relation] ?? null,
            'email' => $mailbox($first),
            'phone' => $phone(),
            'preferred_channel' => 'email',
        ];

        $father = $parent($this->pick(DemoText::FATHERS), 'FATHER');
        $mother = $parent($this->pick(DemoText::MOTHERS), 'MOTHER');

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

    /** @param array{surname: string, guardians: list<array<string, mixed>>} $family */
    private function learner(array $family, int $grade): Learner
    {
        $girl = $this->chance(0.5);
        $first = $this->pick($girl ? DemoText::GIRLS : DemoText::BOYS);
        $born = $this->today->subYears($grade + 5)->startOfYear()->addDays($this->random->getInt(0, 364));
        $email = $grade >= 10 && $this->chance(0.3)
            ? strtolower($first).'.'.strtolower($family['surname']).$this->counts['learners'].'@'.DemoText::FAMILY_DOMAIN
            : null;

        $learner = $this->learners->create([
            'legal_name' => $first.' '.$family['surname'],
            'preferred_name' => isset(DemoText::NICKNAMES[$first]) && $this->chance(0.5) ? DemoText::NICKNAMES[$first] : null,
            'sort_name' => $family['surname'].', '.$first,
            'date_of_birth' => $born->toDateString(),
            'gender' => $girl ? 'female' : 'male',
            'country' => 'NP',
            'home_timezone' => self::TIMEZONE,
            'email' => $email,
            'status_changed_on' => $this->termStart->toDateString(),
        ]);

        foreach ($family['guardians'] as $guardian) {
            $this->guardians->attachOrCreate(
                $learner,
                array_diff_key($guardian, ['receives' => true, 'is_primary' => true]),
                isPrimary: $guardian['is_primary'],
                receivesReports: $guardian['receives'],
            );
        }

        $this->counts['learners']++;

        // How each learner tends to do: a little better or worse than average, rarely extreme.
        $this->profiles[$learner->getKey()] = [
            'ability' => $this->clamp($this->bell(0.72, 0.14), 0.32, 0.98),
            'diligence' => $this->clamp($this->bell(0.9, 0.07), 0.68, 0.995),
            'first' => $first,
        ];

        return $learner;
    }

    /** One pause, one withdrawal and one move between classes, at believable points in the term. */
    private function statusChanges(): void
    {
        $status = fn (string $code): EnrollmentStatus => EnrollmentStatus::query()->where('code', $code)->firstOrFail();
        $nth = fn (string $handle, int $n): ?Enrollment => Enrollment::query()
            ->where('batch_id', $this->batches[$handle]->getKey())->active()->orderBy('id')->skip($n)->first();

        $this->at($this->today->subDays(40)->setTime(11, 0), 1, function () use ($status, $nth): void {
            $enrollment = $nth('science', 4);

            if ($enrollment !== null) {
                $this->enrollments->changeStatus($enrollment, $status('WITHDRAWN'), 'Family moved to Pokhara');
                $this->learners->changeStatus(
                    $enrollment->learner,
                    LearnerStatus::query()->where('code', 'WITHDRAWN')->firstOrFail(),
                    'Family moved to Pokhara',
                );
            }
        });

        $this->at($this->today->subDays(30)->setTime(10, 0), 1, function () use ($nth): void {
            $enrollment = $nth('maths-am', 6);

            if ($enrollment !== null) {
                $this->enrollments->transfer($enrollment, $this->batches['maths-pm'], 'Morning class clashes with the new school bus time');
                $this->counts['enrollments']++;
            }
        });

        $this->at($this->today->subDays(18)->setTime(12, 0), 1, function () use ($status, $nth): void {
            $enrollment = $nth('english', 2);

            if ($enrollment !== null) {
                $this->enrollments->changeStatus($enrollment, $status('ON_HOLD'), 'Travelling to the village for three weeks');
            }
        });
    }

    // ---------------------------------------------------------------------------------------
    // Teaching

    private function assessmentPlan(): void
    {
        $types = AssessmentType::query()->pluck('id', 'code');
        $schemes = GradingScheme::query()->pluck('id', 'code');

        foreach ($this->batches as $handle => $batch) {
            $topics = $this->curriculum[$handle]['topics'];
            $week = 0;

            for ($assigned = $this->termStart->addDays(3); $assigned->lessThan($this->today); $assigned = $assigned->addWeek()) {
                $topic = $topics[intdiv($week, 2) % count($topics)];
                // A rhythm teachers recognise: homework, classwork, homework, a quiz; a project each month.
                $kind = ['homework', 'classwork', 'homework', 'quiz'][$week % 4];
                $week++;

                $this->plan($batch, $assigned, $kind, $topic, $types->all(), $schemes->all());

                if ($assigned->day <= 7) {
                    $this->plan($batch, $assigned->addDays(2), 'project', $topic, $types->all(), $schemes->all());
                }
            }
        }
    }

    /**
     * @param array<string, int> $types
     * @param array<string, int> $schemes
     */
    private function plan(Batch $batch, CarbonImmutable $assigned, string $kind, string $topic, array $types, array $schemes): void
    {
        $due = $assigned->addDays(match ($kind) {
            'project' => 18, 'classwork' => 0, 'quiz' => 0, default => 5,
        });

        [$type, $scheme, $title, $max] = match ($kind) {
            'homework' => ['HOMEWORK', 'POINTS', 'Homework: '.$topic, 20],
            'classwork' => ['CLASSWORK', 'POINTS', 'Classwork: '.$topic, 10],
            'quiz' => ['QUIZ', 'PASSFAIL', 'Quick quiz: '.$topic, null],
            default => ['PROJECT', 'RUBRIC', 'Monthly project: '.$topic, null],
        };

        if (! isset($types[$type], $schemes[$scheme])) {
            return;
        }

        $this->at($assigned->setTime(8, 0), 2, function () use ($batch, $assigned, $due, $type, $scheme, $title, $max, $types, $schemes, $kind): void {
            $criteria = $kind === 'project' ? [
                ['name' => 'Understanding of the topic', 'max_points' => 10],
                ['name' => 'Working shown clearly', 'max_points' => 5],
                ['name' => 'Presentation and neatness', 'max_points' => 5],
            ] : [];

            $assessment = $this->assessments->publish($this->assessments->create($batch, array_filter([
                'assessment_type_id' => $types[$type],
                'grading_scheme_id' => $schemes[$scheme],
                'title' => $title,
                'assigned_local_date' => $assigned->toDateString(),
                'due_local_date' => $due->toDateString(),
                'max_points' => $max,
            ], fn ($v) => $v !== null), $criteria));

            $this->counts['assessments']++;

            // Marked two days after it is due; anything due in the last couple of days is still in
            // the teacher's pile, which is what a real grade book looks like on any given day.
            $marked = $due->addDays(2)->setTime(19, 30);

            if ($marked->lessThan($this->today)) {
                $this->at($marked, 3, fn () => $this->grade($batch, $assessment));
            }
        });
    }

    private function grade(Batch $batch, Assessment $assessment): void
    {
        $assessment->loadMissing(['gradingScheme', 'rubricCriteria']);
        $statuses = SubmissionStatus::query()->pluck('id', 'code');
        $this->actAs($this->curriculum[$this->handleOf($batch)]['teacher']);

        $cells = [];

        $enrollments = Enrollment::query()->where('batch_id', $batch->getKey())->active()
            ->where('enrolled_on', '<=', $assessment->due_local_date?->toDateString() ?? $this->today->toDateString())
            ->get();

        foreach ($enrollments as $enrollment) {
            $profile = $this->profiles[$enrollment->learner_id] ?? ['ability' => 0.7, 'diligence' => 0.9];
            $score = $this->clamp($profile['ability'] + $this->bell(0, 0.09), 0.05, 1.0);
            $missing = ! $this->chance(0.55 + $profile['diligence'] * 0.45);
            $late = ! $missing && $this->chance(0.06);
            $status = $missing ? 'MISSING' : ($late ? 'LATE_SUB' : 'SUBMITTED');

            $cell = [
                'assessment_id' => $assessment->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $statuses[$status],
                'feedback' => $missing ? null : $this->pick(DemoText::FEEDBACK[$score >= 0.8 ? 'high' : ($score >= 0.55 ? 'mid' : 'low')]),
            ];

            if (! $missing) {
                $cell += match ($assessment->gradingScheme->kind) {
                    GradingSchemeKind::PassFail => ['passed' => $score >= 0.45],
                    GradingSchemeKind::Rubric => ['rubric_scores' => $assessment->rubricCriteria->mapWithKeys(
                        fn ($criterion) => [$criterion->getKey() => round((float) $criterion->max_points * $this->clamp($score + $this->bell(0, 0.06), 0.1, 1.0))],
                    )->all()],
                    default => ['raw_score' => round((float) $assessment->max_points * $score)],
                };
            }

            $cells[] = $cell;
        }

        if ($cells !== []) {
            $this->gradebook->saveGrid($batch, $cells);
            $this->counts['grades'] += count($cells);
        }
    }

    /**
     * Take every register up to today, except the latest one in two classes: on any real day
     * a few registers are still waiting, and the product has a screen for exactly that.
     */
    private function registers(): void
    {
        $leaveOpen = [];

        foreach (['science', 'see'] as $handle) {
            $latest = ClassSession::query()->where('batch_id', $this->batches[$handle]->getKey())
                ->where('starts_at_utc', '<', now())->orderByDesc('starts_at_utc')->value('id');
            $leaveOpen[] = $latest;
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
            $at = CarbonImmutable::parse($session->ends_at_utc)->setTimezone(self::TIMEZONE)->addMinutes(5);

            if ($cancel->has($session->getKey())) {
                $this->at(CarbonImmutable::parse($session->starts_at_utc)->subHours(3), 4, function () use ($session): void {
                    $this->actAs('manager');
                    $this->scheduling->cancel($session->refresh(), $this->pick(DemoText::CANCELLATIONS));
                });

                continue;
            }

            $this->at($at, 5, fn () => $this->register($session));
        }
    }

    private function register(ClassSession $session): void
    {
        $session->refresh()->loadMissing('batch');

        if ($session->status !== SessionStatus::Scheduled) {
            return;
        }

        $statuses = AttendanceStatus::query()->pluck('id', 'code');
        $this->actAs($this->curriculum[$this->handleOf($session->batch)]['teacher']);
        $marks = [];

        foreach ($this->attendance->roster($session) as $row) {
            $profile = $this->profiles[$row['learner_id']] ?? ['diligence' => 0.9];
            $code = 'PRESENT';
            $minutes = null;
            $note = null;

            if (! $this->chance($profile['diligence'])) {
                $code = $this->chance(0.35) ? 'EXCUSED' : 'ABSENT';
                $note = $code === 'EXCUSED' ? $this->pick(DemoText::EXCUSES) : null;
            } elseif ($this->chance(0.07)) {
                $code = 'LATE';
                $minutes = $this->random->getInt(5, 25);
                $note = $this->pick(DemoText::LATE_NOTES);
            }

            $marks[] = ['enrollment_id' => $row['enrollment_id'], 'status_id' => $statuses[$code],
                'minutes_late' => $minutes, 'note' => $note];
        }

        if ($marks !== []) {
            $this->attendance->record($session, $marks);
            $this->counts['marks'] += count($marks);
        }
    }

    /** Month-end comments for every finished month, and for most of this one so far. */
    private function notePlan(): void
    {
        for ($month = $this->termStart; $month->lessThanOrEqualTo($this->today); $month = $month->addMonthNoOverflow()) {
            $finished = $month->endOfMonth()->lessThan($this->today);
            $writtenOn = $finished ? $month->endOfMonth()->subDays(2)->setTime(18, 0) : $this->today->subDay()->setTime(18, 0);

            if ($writtenOn->lessThan($this->termStart)) {
                continue;
            }

            foreach ($this->batches as $handle => $batch) {
                $this->at($writtenOn, 6, fn () => $this->monthNotes($handle, $batch, $month, $finished ? 1.0 : 0.6));
            }
        }
    }

    private function monthNotes(string $handle, Batch $batch, CarbonImmutable $month, float $share): void
    {
        $this->actAs($this->curriculum[$handle]['teacher']);
        $categories = NoteCategory::query()->pluck('id', 'code');
        $notes = [];

        foreach (Enrollment::query()->where('batch_id', $batch->getKey())->active()->get() as $enrollment) {
            if (! $this->chance($share)) {
                continue;
            }

            $profile = $this->profiles[$enrollment->learner_id] ?? ['ability' => 0.7, 'diligence' => 0.9, 'first' => 'This student'];
            $band = $profile['ability'] >= 0.82 ? 'strong' : ($profile['ability'] >= 0.6 && $profile['diligence'] >= 0.8 ? 'steady' : 'support');

            $notes[] = [
                'enrollment_id' => $enrollment->getKey(),
                'note_category_id' => $categories[$band === 'support' && $this->chance(0.3) ? 'PARTICIPATION' : 'ACADEMIC'],
                // A different sentence each month for the same learner, as a teacher would write.
                'body' => strtr(DemoText::NOTES[$band][($enrollment->learner_id + $month->month) % count(DemoText::NOTES[$band])], [
                    '{name}' => $profile['first'],
                    '{subject}' => strtolower($this->curriculum[$handle]['subject']),
                ]),
            ];
        }

        if ($notes !== []) {
            $this->counts['notes'] += $this->notes->writeMany($notes, $month->format('Y-m'))['written'];
        }
    }

    /**
     * Last month's reports, generated early this month: sent for two classes, generated but not
     * yet sent for two more, and not started for the rest — the three states a director checks.
     */
    private function reportPlan(): void
    {
        $previous = $this->today->subMonthNoOverflow()->format('Y-m');
        $on = $this->today->day >= 4 ? $this->today->startOfMonth()->addDays(2) : $this->today->subDay();

        foreach (['maths-am' => true, 'science' => true, 'english' => false, 'see' => false] as $handle => $send) {
            $this->at($on->setTime(10, 30), 7, function () use ($handle, $previous, $send): void {
                $this->actAs('manager');
                $batch = $this->batches[$handle];
                $period = $this->periods->forLabel($batch, $previous);

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
        // Enrollments and status changes are already queued; registers are planned now because
        // they depend on which sessions exist. Moments can queue later ones (an assessment queues
        // its marking), so this is a queue drained in time order, not a list sorted once.
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
        Auth::setUser($this->staff[$handle]);
    }

    private function handleOf(Batch $batch): string
    {
        return (string) array_search($batch->getKey(), array_map(fn (Batch $b) => $b->getKey(), $this->batches), true);
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
