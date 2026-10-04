<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Import;
use App\Models\Learner;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LearnerService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Learners from a spreadsheet: every blocked row named with its row number and reason, nothing
 * written until commit, and the blocked rows handed back as a file.
 */
final class LearnerImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Legal name,Preferred name,Date of birth,Email,Country,Guardian name,Guardian email,Guardian receives reports';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
            'status' => Tenant::STATUS_ACTIVE,
        ])['tenant'];

        Sanctum::actingAs(app(TenantContext::class)->runAs($this->tenant, fn () => User::query()->firstOrFail()));
    }

    #[Test]
    public function the_preview_names_every_blocked_row_and_writes_nothing(): void
    {
        $response = $this->upload([
            self::HEADER,
            'Aarav Sharma,Aaru,2015-04-02,,NP,Sita Sharma,sita@sample.test,yes',
            ',,2016-01-01,,,,,',
            'Maya Gurung,,02/03/2014,,NP,,,',
            'Bina Rai,,2014-07-09,not-an-email,Nepal,,parent@sample.test,maybe',
            'Aarav Sharma,,2015-04-02,,NP,,,',
            'Kiran Thapa,,2013-11-30,,NP,,,',
        ])->assertCreated();

        $response->assertJsonPath('data.status', Import::STATUS_PREVIEWED)
            ->assertJsonPath('data.totals.rows', 6)
            ->assertJsonPath('data.totals.ready', 2)
            ->assertJsonPath('data.totals.blocked', 4);

        /** @var array<int, array{row: int, reasons: array<int, string>}> $rows */
        $rows = $response->json('data.blocked');
        $blocked = collect($rows)->keyBy('row');

        $this->assertSame([3, 4, 5, 6], $blocked->keys()->all(), 'Row numbers are the ones in the spreadsheet.');
        $this->assertContains('A name is required.', $blocked[3]['reasons']);
        $this->assertStringContainsString('YYYY-MM-DD', implode(' ', $blocked[4]['reasons']));

        $row5 = implode(' ', $blocked[5]['reasons']);
        $this->assertStringContainsString('email', $row5);
        $this->assertStringContainsString('two-letter country code', $row5);
        $this->assertStringContainsString('yes or no', $row5);
        $this->assertStringContainsString("guardian's name", $row5);

        $this->assertContains('Same learner as row 2.', $blocked[6]['reasons']);

        // Nothing is written by a preview.
        $this->assertSame(0, $this->rowsOf(Learner::class));
        $this->assertNotNull($response->json('data.rejects_url'));
    }

    #[Test]
    public function commit_writes_every_ready_row_with_its_guardian_and_audit_trail(): void
    {
        $id = $this->upload([
            self::HEADER,
            'Aarav Sharma,Aaru,2015-04-02,,np,Sita Sharma,sita@sample.test,yes',
            'Maya Gurung,,2014-03-02,,NP,Hari Gurung,hari@sample.test,no',
            ',,,,,,,',
            'Broken Row,,tomorrow,,,,,',
        ])->json('data.id');

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk()
            ->assertJsonPath('data.status', Import::STATUS_COMMITTED)
            ->assertJsonPath('data.totals.created', 2)
            ->assertJsonPath('data.message', '2 imported. 1 blocked.');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $aarav = Learner::query()->where('legal_name', 'Aarav Sharma')->firstOrFail();
            $this->assertSame('NP', $aarav->country);
            $this->assertSame('Aaru', $aarav->preferred_name);
            $this->assertSame('sita@sample.test', $aarav->guardians()->firstOrFail()->email);

            $maya = Learner::query()->where('legal_name', 'Maya Gurung')->firstOrFail();
            $this->assertFalse((bool) $maya->guardians()->firstOrFail()->pivot->getAttribute('receives_reports'));

            // Imports are audited per record, like any other create.
            $this->assertSame(2, AuditLog::query()->where('module', 'People')->where('action', 'created')
                ->where('auditable_type', (new Learner)->getMorphClass())->count());
        });

        // The uploaded file is not kept once it has been used.
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));

        // And it cannot be committed twice.
        $this->postJson("/api/v1/imports/{$id}/commit")->assertStatus(422);
        $this->assertSame(2, $this->rowsOf(Learner::class));
    }

    #[Test]
    public function a_failure_part_way_leaves_nothing_behind(): void
    {
        $id = $this->upload([
            self::HEADER,
            'Aarav Sharma,,2015-04-02,,NP,,,',
            'Maya Gurung,,2014-03-02,,NP,,,',
        ])->json('data.id');

        // The second create fails, as a database error would.
        $calls = 0;
        Learner::creating(function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('Lost connection');
            }
        });

        $this->postJson("/api/v1/imports/{$id}/commit")->assertStatus(422)->assertJsonValidationErrors('import');

        $this->assertSame(0, $this->rowsOf(Learner::class));
        $this->assertSame(Import::STATUS_PREVIEWED, app(TenantContext::class)->runAs($this->tenant, fn () => Import::query()->findOrFail($id)->status));
    }

    #[Test]
    public function blocked_rows_come_back_as_a_file_with_the_problems_beside_them(): void
    {
        $id = $this->upload([
            self::HEADER,
            'Good Row,,2015-04-02,,NP,,,',
            '=HYPERLINK("http://evil.test"),,2099-01-01,,,,,',
        ])->json('data.id');

        $csv = $this->get("/api/v1/imports/{$id}/rejects")->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $lines = array_values(array_filter(explode("\n", (string) $csv)));

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Row,"Legal name"', $lines[0]);
        $this->assertStringStartsWith('3,', $lines[1]);
        $this->assertStringContainsString('The date of birth is in the future.', $lines[1]);
        // A formula typed into a cell is neutralised, not handed back live.
        $this->assertStringContainsString("'=HYPERLINK", $lines[1]);
    }

    #[Test]
    public function possible_duplicates_of_existing_learners_are_flagged_and_left_out_unless_included(): void
    {
        app(TenantContext::class)->runAs($this->tenant, fn () => app(LearnerService::class)->create([
            'legal_name' => 'Aarav Sharma', 'date_of_birth' => '2015-04-02',
        ]));

        $preview = $this->upload([
            self::HEADER,
            'aarav  sharma,,2015-04-02,,NP,,,',
            'New Person,,2014-01-01,,NP,,,',
        ]);

        $preview->assertJsonPath('data.totals.possible_duplicates', 1)
            ->assertJsonPath('data.possible_duplicates.0.row', 2)
            ->assertJsonPath('data.possible_duplicates.0.reason', 'same name and date of birth');

        $this->postJson('/api/v1/imports/'.$preview->json('data.id').'/commit')->assertOk()
            ->assertJsonPath('data.totals.created', 1)
            ->assertJsonPath('data.totals.skipped_duplicates', 1);

        $this->assertSame(2, $this->rowsOf(Learner::class));

        $again = $this->upload([self::HEADER, 'Aarav Sharma,,2015-04-02,,NP,,,']);
        $this->postJson('/api/v1/imports/'.$again->json('data.id').'/commit', ['include_possible_duplicates' => true])
            ->assertOk()->assertJsonPath('data.totals.created', 1);

        $this->assertSame(3, $this->rowsOf(Learner::class));
    }

    #[Test]
    public function an_xlsx_with_real_date_cells_and_aliased_headers_is_understood(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Student name', 'DOB', 'Parent name', 'Parent phone', 'Shoe size']]);
        $sheet->fromArray([['Maya Gurung', ExcelDate::PHPToExcel(new \DateTimeImmutable('2014-03-02')), 'Hari Gurung', 9800000000, 34]], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $id = $this->postJson('/api/v1/imports/learners', [
            'file' => new UploadedFile($path, 'learners.xlsx', null, null, true),
        ])->assertCreated()
            ->assertJsonPath('data.totals.ready', 1)
            ->assertJsonPath('data.ignored_columns', ['Shoe size'])
            ->assertJsonPath('data.sample.0.date_of_birth', '2014-03-02')
            ->json('data.id');

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame('9800000000', Guardian::query()->firstOrFail()->phone);
        });
    }

    #[Test]
    public function a_file_without_a_name_column_is_refused_whole(): void
    {
        $this->upload(['First,Last', 'Aarav,Sharma'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, $this->rowsOf(Import::class));
    }

    #[Test]
    public function a_discarded_import_cannot_be_committed(): void
    {
        $id = $this->upload([self::HEADER, 'Aarav Sharma,,2015-04-02,,NP,,,'])->json('data.id');

        $this->deleteJson("/api/v1/imports/{$id}")->assertOk()->assertJsonPath('data.status', Import::STATUS_DISCARDED);
        $this->postJson("/api/v1/imports/{$id}/commit")->assertStatus(422);

        $this->assertSame(0, $this->rowsOf(Learner::class));
    }

    #[Test]
    public function the_template_lists_the_columns(): void
    {
        $this->get('/api/v1/imports/learners/template')->assertOk()
            ->assertSee('"Legal name","Preferred name","Date of birth"', false)
            ->assertSee('"Guardian receives reports"', false);
    }

    #[Test]
    public function only_people_who_can_add_learners_can_import(): void
    {
        Sanctum::actingAs($this->user('Teacher'));

        $this->upload([self::HEADER, 'Aarav Sharma,,2015-04-02,,NP,,,'])->assertForbidden();
        $this->get('/api/v1/imports/learners/template')->assertForbidden();
    }

    /**
     * @param array<int, string> $lines
     * @return TestResponse<JsonResponse>
     */
    private function upload(array $lines): TestResponse
    {
        return $this->postJson('/api/v1/imports/learners', [
            'file' => UploadedFile::fake()->createWithContent('learners.csv', implode("\n", $lines)."\n"),
        ]);
    }

    /** @param class-string<Model> $model */
    private function rowsOf(string $model): int
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => $model::query()->count());
    }

    private function user(string $role): User
    {
        return app(TenantContext::class)->runAs($this->tenant, function () use ($role): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles([$role]);

            return $user->fresh() ?? $user;
        });
    }
}
