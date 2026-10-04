# Importing learners from a spreadsheet

Learners, each with their main guardian, can be brought in from an `.xlsx` or `.csv` file. It
takes two steps: a preview that writes nothing, then a commit. Anyone who may add learners may
import them.

## 1. Get the template

`GET /api/v1/imports/learners/template` downloads a CSV with the expected columns:

| Column | Notes |
|---|---|
| Legal name | Required. Also recognised as "Name", "Full name", "Student name" |
| Preferred name | |
| Date of birth | `YYYY-MM-DD`, or a real date cell in Excel. Also "DOB" |
| Gender, Email, Phone | |
| Country | Two-letter code, e.g. `NP`, `GB` |
| Guardian name | Required if a guardian email or phone is given. Also "Parent name" |
| Guardian email, Guardian phone | Matched against guardians already on file, so siblings share one |
| Guardian receives reports | `yes` or `no`; blank means yes |

Column order doesn't matter, and other columns are ignored (the preview lists them). Format phone
columns as text in Excel, or leading zeros are lost.

## 2. Preview

`POST /api/v1/imports/learners` with `file` (multipart, at most 5 MB and 5,000 rows).

Nothing is written. The response gives:

- `totals`: rows, ready, blocked, possible duplicates.
- `blocked`: every row that can't be imported, with its row number as it appears in the
  spreadsheet (the header is row 1) and every reason. A row repeated in the file is blocked as
  "Same learner as row N".
- `possible_duplicates`: ready rows whose name matches a learner already on file, with the reason
  and the existing learner numbers.
- `sample`: the first ten ready rows, as they will be written.
- `rejects_url`: `GET /api/v1/imports/{id}/rejects` returns the blocked rows as a CSV, with the
  problems in the last column, ready to fix and import again.

A file with no "Legal name" column is refused as a whole.

## 3. Commit or discard

`POST /api/v1/imports/{id}/commit` `{ "include_possible_duplicates": false }`

The file is read again against the data as it is now, and every ready row is written in a single
transaction. If anything fails part-way, nothing is written and the import can be committed
again. Possible duplicates are left out unless `include_possible_duplicates` is true. Each learner
and guardian created goes into the audit log like any other.

`DELETE /api/v1/imports/{id}` discards a preview.

An import can be committed or discarded once. The uploaded file is deleted either way.

## Safety

- In a CSV, a cell such as `=SUM(...)` is read as plain text and never evaluated. In an `.xlsx`,
  formulas give their calculated value.
- In the rejects file, a cell starting with `=`, `+`, `-` or `@` is prefixed with `'`, so a
  spreadsheet program can't run it as a formula.
