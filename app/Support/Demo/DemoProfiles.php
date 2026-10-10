<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The demonstration academies: one of each kind of customer the platform serves, so a demo shows
 * one product taking four shapes rather than one product for one kind of school.
 *
 * Each profile is plain data: who works there, what they teach, when, to whom, and how they mark
 * it. DemoAcademyBuilder turns a profile into a term already under way.
 *
 * Class entries: [handle, course, branch, name, code, capacity, teacher, slots, minutes, subject,
 * topics, how many enrolled, [youngest, oldest], level on the CEFR ladder (0 = A1) or null].
 *
 * Assessment rhythm: one entry per week in turn, each [type code, scheme code, title, max points,
 * days until due]; "per_period" is set once in each reporting period.
 */
final class DemoProfiles
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [self::kids(), self::language(), self::skills(), self::corporate()];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /** The platform operator a demo signs in to the admin panel with. */
    public const OPERATOR_EMAIL = 'operator@tutorium-demo.example';

    /** @return array<string, mixed> */
    private static function kids(): array
    {
        return [
            'slug' => 'demo-himalayan-scholars',
            'name' => 'Himalayan Scholars Academy',
            'kind' => 'kids',
            'preset' => 'kids-tutoring-south-asia',
            'country' => 'NP',
            'timezone' => 'Asia/Kathmandu',
            'week_start' => 'sunday',
            'weekend' => ['saturday'],
            'domain' => 'himalayan-scholars.example',
            'names' => 'nepal',
            'people' => 'family',
            'phone' => '+977 98000',
            'brand_code' => 'HSA',
            'owner' => ['Sunita Adhikari', 'sunita'],
            'branches' => [
                ['BNS', 'Baneshwor', 'Old Baneshwor, Kathmandu 44600', '+977 1-4400012'],
                ['PLC', 'Pulchowk', 'Pulchowk, Lalitpur 44700', '+977 1-5500034'],
            ],
            'staff' => [
                ['manager', 'Rajesh Shrestha', 'rajesh', 'Management', []],
                ['frontdesk', 'Anisha Maharjan', 'anisha', 'Front desk', ['BNS']],
                ['accounts', 'Prakash Joshi', 'prakash', 'Accountant', []],
                ['maths', 'Bikash Thapa', 'bikash', 'Teacher', ['BNS', 'PLC']],
                ['science', 'Pooja Gurung', 'pooja', 'Teacher', ['BNS']],
                ['english', 'Suman Karki', 'suman', 'Teacher', ['PLC']],
                ['computer', 'Nirmala Rai', 'nirmala', 'Teacher', ['BNS']],
            ],
            'period' => ['type' => 'monthly'],
            'courses' => [
                ['MATH-8', 'Mathematics — Grade 8', 'kids', 'School curriculum support, with weekly homework and a monthly project.'],
                ['SCI-9', 'Science — Grade 9', 'teens', 'Physics, chemistry and biology to the CDC Grade 9 syllabus.'],
                ['ENG-7', 'English — Grade 7', 'kids', 'Reading, grammar and writing, with a speaking task each month.'],
                ['SEE-MATH', 'SEE Preparation — Mathematics', 'teens', 'Intensive revision and past papers for the Secondary Education Examination.'],
                ['COMP-JR', 'Computer Basics for Juniors', 'kids', 'Typing, safe internet use and first programs in Scratch, ages 9–12.'],
            ],
            'classes' => [
                ['maths-am', 'MATH-8', 'BNS', 'Grade 8 Maths · Morning', 'G8M-AM', 20, 'maths',
                    [['sunday', '06:30'], ['tuesday', '06:30'], ['thursday', '06:30']], 60, 'Mathematics',
                    ['Algebraic expressions', 'Linear equations', 'Ratio and proportion', 'Simple interest', 'Area of plane figures', 'Mean, median and mode'],
                    16, [13, 14], null],
                ['maths-pm', 'MATH-8', 'PLC', 'Grade 8 Maths · Evening', 'G8M-PM', 15, 'maths',
                    [['monday', '17:30'], ['wednesday', '17:30']], 75, 'Mathematics',
                    ['Algebraic expressions', 'Linear equations', 'Percentages', 'Simple interest', 'Area of plane figures', 'Statistics'],
                    9, [13, 14], null],
                ['science', 'SCI-9', 'BNS', 'Grade 9 Science · Evening', 'G9S-PM', 18, 'science',
                    [['monday', '17:00'], ['wednesday', '17:00'], ['friday', '16:00']], 75, 'Science',
                    ['Force and motion', 'Reflection of light', 'Acids, bases and salts', 'Cell structure', 'Electric circuits', 'Heat and temperature'],
                    15, [14, 15], null],
                ['english', 'ENG-7', 'PLC', 'Grade 7 English · Afternoon', 'G7E-PM', 16, 'english',
                    [['sunday', '16:00'], ['wednesday', '16:00']], 60, 'English',
                    ['Reading comprehension', 'Present and past tenses', 'Writing a letter', 'Reported speech', 'Vocabulary in context', 'Story writing'],
                    12, [12, 13], null],
                ['see', 'SEE-MATH', 'PLC', 'SEE Maths · Intensive', 'SEE-M', 24, 'maths',
                    [['sunday', '07:00'], ['monday', '07:00'], ['tuesday', '07:00'], ['wednesday', '07:00'], ['thursday', '07:00']], 90, 'Mathematics',
                    ['Compound interest', 'Population growth', 'Mensuration: prisms', 'Algebraic fractions', 'Indices', 'Probability'],
                    20, [15, 16], null],
                ['computer', 'COMP-JR', 'BNS', 'Computer Basics · Saturday', 'CMP-SAT', 12, 'computer',
                    [['saturday', '10:00']], 120, 'Computer',
                    ['Parts of a computer', 'Typing practice', 'Drawing in Paint', 'Scratch: moving a sprite', 'Staying safe online', 'Scratch: a simple game'],
                    10, [9, 11], null],
            ],
            'rhythm' => [
                ['HOMEWORK', 'POINTS', 'Homework', 20, 5],
                ['CLASSWORK', 'POINTS', 'Classwork', 10, 0],
                ['HOMEWORK', 'POINTS', 'Homework', 20, 5],
                ['QUIZ', 'PASSFAIL', 'Quick quiz', null, 0],
            ],
            'per_period' => ['PROJECT', 'RUBRIC', 'Monthly project', null, 18],
            'events' => [
                'withdraw' => ['science', 'Family moved to Pokhara'],
                'transfer' => ['maths-am', 'maths-pm', 'Morning class clashes with the new school bus time'],
                'hold' => ['english', 'Travelling to the village for three weeks'],
            ],
            'reports' => ['send' => ['maths-am', 'science'], 'generate' => ['english', 'see']],
            'closure' => 'Centre closed — staff training day',
            'cancellations' => ['Teacher unwell — class moved to the make-up week', 'Heavy rain, centre closed for the afternoon', 'Power cut across the area'],
        ];
    }

    /** @return array<string, mixed> */
    private static function language(): array
    {
        return [
            'slug' => 'demo-lingua-bridge',
            'name' => 'Lingua Bridge Language Centre',
            'kind' => 'language',
            'preset' => 'language-school-gulf',
            // What this school calls things, on top of the preset: academies rename freely.
            'terminology' => ['batch' => ['Group', 'Groups']],
            'country' => 'AE',
            'timezone' => 'Asia/Dubai',
            'week_start' => 'sunday',
            'weekend' => ['friday', 'saturday'],
            'domain' => 'linguabridge.example',
            'names' => 'gulf',
            'people' => 'adult',
            'phone' => '+971 50 000 ',
            'brand_code' => 'LBLC',
            'owner' => ['Rania Haddad', 'rania'],
            'branches' => [
                ['ABR', 'Al Barsha', 'Al Barsha 1, Dubai', '+971 4 000 0120'],
                ['ONL', 'Online', 'Live online classes', '+971 4 000 0121'],
            ],
            'staff' => [
                ['manager', 'Daniel Brooks', 'daniel', 'Management', []],
                ['frontdesk', 'Joy Santos', 'joy', 'Front desk', []],
                ['english', 'Claire Dubois', 'claire', 'Teacher', ['ABR']],
                ['arabic', 'Omar Farouk', 'omar', 'Teacher', ['ABR']],
                ['business', 'Ana Petrova', 'ana', 'Teacher', ['ABR', 'ONL']],
            ],
            // Eight-week terms, defined by the school as a language school would.
            'period' => ['type' => 'term', 'weeks' => 8, 'label' => 'Term %d'],
            'courses' => [
                ['GE-B1', 'General English — Intermediate (B1)', 'adults', 'Speaking, listening, reading and writing at B1, towards B2.'],
                ['IELTS', 'IELTS Preparation', 'adults', 'Exam technique and timed practice for IELTS Academic and General.'],
                ['AR-A1', 'Arabic for Beginners (A1)', 'adults', 'Modern Standard Arabic and everyday Emirati phrases.'],
                ['BUS-EN', 'Business English', 'adults', 'Meetings, presentations and email for working professionals.'],
            ],
            'classes' => [
                ['ge-am', 'GE-B1', 'ABR', 'General English B1 · Morning', 'GEB1-AM', 12, 'english',
                    [['sunday', '09:30'], ['tuesday', '09:30'], ['thursday', '09:30']], 120, 'English',
                    ['Talking about experiences', 'Future plans', 'Giving opinions', 'Narrative tenses', 'Making requests', 'Comparing'],
                    10, [22, 45], 2],
                ['ge-pm', 'GE-B1', 'ABR', 'General English B1 · Evening', 'GEB1-PM', 12, 'english',
                    [['monday', '19:00'], ['wednesday', '19:00']], 120, 'English',
                    ['Talking about experiences', 'Future plans', 'Giving opinions', 'Narrative tenses', 'Making requests', 'Comparing'],
                    7, [22, 45], 2],
                ['ielts', 'IELTS', 'ABR', 'IELTS Preparation · Saturday Intensive', 'IELTS-SAT', 14, 'business',
                    [['saturday', '10:00']], 180, 'IELTS',
                    ['Task 1: describing data', 'Task 2: opinion essays', 'Listening: maps and plans', 'Reading: true/false/not given', 'Speaking part 2', 'Full mock test'],
                    12, [19, 34], 3],
                ['arabic', 'AR-A1', 'ABR', 'Arabic A1 · Evening', 'ARA1-PM', 10, 'arabic',
                    [['monday', '18:00'], ['wednesday', '18:00']], 90, 'Arabic',
                    ['Greetings and introductions', 'Numbers and prices', 'At the market', 'Directions', 'Daily routine', 'At the restaurant'],
                    8, [24, 55], 0],
                ['business', 'BUS-EN', 'ONL', 'Business English · Online', 'BUSEN-ONL', 10, 'business',
                    [['tuesday', '20:00'], ['thursday', '20:00']], 60, 'Business English',
                    ['Running a meeting', 'Presenting results', 'Negotiating', 'Writing clear emails', 'Small talk with clients', 'Handling complaints'],
                    8, [27, 50], 3],
            ],
            'rhythm' => [
                ['HOMEWORK', 'POINTS', 'Homework', 20, 4],
                ['QUIZ', 'CEFR', 'Speaking check', null, 0],
                ['CLASSWORK', 'POINTS', 'Reading task', 10, 0],
                ['HOMEWORK', 'POINTS', 'Listening practice', 20, 4],
            ],
            'per_period' => ['PROJECT', 'RUBRIC', 'Writing portfolio', null, 21],
            'events' => [
                'withdraw' => ['arabic', 'Relocating to Abu Dhabi for work'],
                'transfer' => ['ge-am', 'ge-pm', 'New job hours — moving to the evening group'],
                'hold' => ['ielts', 'Exam booked for next term; pausing until then'],
            ],
            'reports' => ['send' => ['ge-am', 'business'], 'generate' => ['ielts']],
            'closure' => 'Centre closed — teacher development day',
            'cancellations' => ['Teacher unwell — make-up class on Saturday', 'Building maintenance, centre closed', 'Online platform outage'],
        ];
    }

    /** @return array<string, mixed> */
    private static function skills(): array
    {
        return [
            'slug' => 'demo-codecraft',
            'name' => 'CodeCraft Institute',
            'kind' => 'skills',
            'preset' => 'skills-institute',
            'country' => 'NP',
            'timezone' => 'Asia/Kathmandu',
            'week_start' => 'sunday',
            'weekend' => ['saturday'],
            'domain' => 'codecraft.example',
            'names' => 'nepal',
            'people' => 'adult',
            // Some trainees are put through by their employer, which receives their reports.
            'sponsors' => [['Bagmati Cloud Services', 'bagmaticloud.example', 'Shanti Thapa'],
                ['Kantipur Byte Solutions', 'kantipurbyte.example', 'Gopal Pradhan'],
                ['Lalitpur Data Labs', 'lalitpurdata.example', 'Kalpana Rai']],
            'sponsored_share' => 0.3,
            'phone' => '+977 98000',
            'brand_code' => 'CCI',
            'owner' => ['Rohit Shakya', 'rohit'],
            'branches' => [
                ['JMS', 'Jhamsikhel', 'Jhamsikhel, Lalitpur 44700', '+977 1-5400078'],
            ],
            'staff' => [
                ['manager', 'Sabina Karki', 'sabina', 'Management', []],
                ['frontdesk', 'Bina Maharjan', 'bina', 'Front desk', []],
                ['web', 'Anil Gurung', 'anil', 'Teacher', []],
                ['data', 'Pratima Joshi', 'pratima', 'Teacher', []],
                ['network', 'Sujan Rai', 'sujan', 'Teacher', []],
            ],
            'period' => ['type' => 'block', 'weeks' => 4],
            'courses' => [
                ['FSWD', 'Full-Stack Web Development', 'adults', 'HTML to deployed apps: JavaScript, React, Node and SQL, in 4-week blocks.'],
                ['DATA-PY', 'Data Analysis with Python', 'adults', 'pandas, visualisation and SQL for analysts.'],
                ['NET-CCNA', 'Networking Essentials (CCNA track)', 'adults', 'Routing, switching and security fundamentals with lab work.'],
            ],
            'classes' => [
                ['web-am', 'FSWD', 'JMS', 'Full-Stack Web · Morning Cohort', 'FSWD-AM', 20, 'web',
                    [['sunday', '07:00'], ['monday', '07:00'], ['tuesday', '07:00'], ['wednesday', '07:00'], ['thursday', '07:00']], 120, 'web development',
                    ['Semantic HTML and CSS', 'JavaScript fundamentals', 'DOM and events', 'React components', 'REST APIs with Node', 'SQL and data modelling'],
                    16, [19, 26], null],
                ['web-pm', 'FSWD', 'JMS', 'Full-Stack Web · Evening Cohort', 'FSWD-PM', 20, 'web',
                    [['sunday', '17:30'], ['tuesday', '17:30'], ['thursday', '17:30']], 120, 'web development',
                    ['Semantic HTML and CSS', 'JavaScript fundamentals', 'DOM and events', 'React components', 'REST APIs with Node', 'SQL and data modelling'],
                    10, [21, 32], null],
                ['data', 'DATA-PY', 'JMS', 'Data Analysis · Evening Cohort', 'DATA-PM', 18, 'data',
                    [['monday', '18:00'], ['wednesday', '18:00'], ['friday', '18:00']], 120, 'data analysis',
                    ['Python refresher', 'pandas DataFrames', 'Cleaning messy data', 'Visualisation', 'SQL for analysts', 'Telling the story'],
                    14, [21, 35], null],
                ['network', 'NET-CCNA', 'JMS', 'Networking Essentials · Weekday Cohort', 'NET-WD', 16, 'network',
                    [['sunday', '16:00'], ['tuesday', '16:00'], ['thursday', '16:00']], 120, 'networking',
                    ['OSI and TCP/IP', 'IPv4 subnetting', 'Switching and VLANs', 'Routing basics', 'Wireless', 'Network security'],
                    12, [19, 28], null],
            ],
            'rhythm' => [
                ['LAB', 'POINTS', 'Lab', 20, 3],
                ['QUIZ', 'PASSFAIL', 'Concept quiz', null, 0],
                ['LAB', 'POINTS', 'Lab', 20, 3],
                ['LAB', 'POINTS', 'Lab', 20, 3],
            ],
            'per_period' => ['PROJECT', 'RUBRIC', 'Block project', null, 20],
            'events' => [
                'withdraw' => ['network', 'Accepted a full-time job abroad'],
                'transfer' => ['web-am', 'web-pm', 'Started a day job — moving to the evening cohort'],
                'hold' => ['data', 'Medical leave for two weeks'],
            ],
            'reports' => ['send' => ['web-am', 'data'], 'generate' => ['network']],
            'closure' => 'Institute closed — curriculum review day',
            'cancellations' => ['Trainer unwell — recording shared instead', 'Lab network upgrade', 'Power cut across the area'],
        ];
    }

    /** @return array<string, mixed> */
    private static function corporate(): array
    {
        return [
            'slug' => 'demo-summit-learning',
            'name' => 'Summit Corporate Learning',
            'kind' => 'corporate',
            'preset' => 'corporate-training',
            'country' => 'CA',
            'timezone' => 'America/Toronto',
            'week_start' => 'monday',
            'weekend' => ['saturday', 'sunday'],
            'domain' => 'summitlearning.example',
            'names' => 'canada',
            'people' => 'sponsored',
            // Every participant is sent by a client company, whose contact receives the reports.
            'sponsors' => [['Maple Freight Ltd.', 'maplefreight.example', 'Karen Wilson'],
                ['Northshore Insurance', 'northshoreins.example', 'Paul Gagnon'],
                ['Lakeview Health Network', 'lakeviewhealth.example', 'Sandra Lee'],
                ['Brightline Software', 'brightline.example', 'Jason Patel']],
            'sponsored_share' => 1.0,
            'phone' => '+1 416 555 01',
            'brand_code' => 'SCL',
            'owner' => ['Megan Ross', 'megan'],
            'branches' => [
                ['KSW', 'King Street West', '220 King St W, Toronto, ON', '+1 416 555 0100'],
                ['ONL', 'Virtual Classroom', 'Live online sessions', '+1 416 555 0101'],
            ],
            'staff' => [
                ['manager', 'Raj Patel', 'raj', 'Management', []],
                ['frontdesk', 'Chloe Tremblay', 'chloe', 'Front desk', []],
                ['accounts', 'Mark Anderson', 'mark', 'Accountant', []],
                ['leadership', 'David Chen', 'david', 'Teacher', []],
                ['excel', 'Amira Saleh', 'amira', 'Teacher', []],
                ['pm', 'Tom MacLeod', 'tom', 'Teacher', []],
            ],
            'period' => ['type' => 'block', 'weeks' => 4],
            'courses' => [
                ['FTM', 'First-Time Manager Programme', 'adults', 'Delegation, feedback and running a team for newly promoted managers.'],
                ['XL-AN', 'Excel for Analysts', 'adults', 'Lookups, PivotTables, Power Query and dashboards.'],
                ['PMF', 'Project Management Fundamentals', 'adults', 'Scope, schedule, risk and stakeholders, aligned to PMI terminology.'],
            ],
            'classes' => [
                ['ftm-a', 'FTM', 'KSW', 'First-Time Managers · Cohort A', 'FTM-A', 16, 'leadership',
                    [['tuesday', '09:00']], 180, 'leadership',
                    ['From peer to manager', 'Delegating well', 'Giving feedback', 'Difficult conversations', 'Running one-to-ones', 'Leading change'],
                    14, [28, 48], null],
                ['ftm-b', 'FTM', 'KSW', 'First-Time Managers · Cohort B', 'FTM-B', 16, 'leadership',
                    [['thursday', '13:00']], 180, 'leadership',
                    ['From peer to manager', 'Delegating well', 'Giving feedback', 'Difficult conversations', 'Running one-to-ones', 'Leading change'],
                    8, [28, 48], null],
                ['excel', 'XL-AN', 'ONL', 'Excel for Analysts · Virtual', 'XL-ONL', 20, 'excel',
                    [['monday', '12:00'], ['wednesday', '12:00']], 90, 'Excel',
                    ['XLOOKUP and INDEX/MATCH', 'PivotTables', 'Power Query', 'Dynamic arrays', 'Charts that explain', 'Building a dashboard'],
                    16, [24, 52], null],
                ['pm', 'PMF', 'KSW', 'Project Management Fundamentals', 'PMF-FRI', 18, 'pm',
                    [['friday', '09:00']], 240, 'project management',
                    ['Project charters', 'Scope and WBS', 'Scheduling', 'Risk registers', 'Stakeholder maps', 'Closing a project'],
                    12, [26, 55], null],
            ],
            'rhythm' => [
                ['EXERCISE', 'PASSFAIL', 'Workplace exercise', null, 6],
                ['EXERCISE', 'PASSFAIL', 'Reflection task', null, 6],
            ],
            'per_period' => ['ASSESSMENT', 'RUBRIC', 'Case study assessment', null, 14],
            'events' => [
                'withdraw' => ['pm', 'Left the sponsoring company'],
                'transfer' => ['ftm-a', 'ftm-b', 'Tuesday clashes with a new team meeting'],
                'hold' => ['excel', 'Parental leave — resuming next cohort'],
            ],
            'reports' => ['send' => ['ftm-a', 'excel'], 'generate' => ['pm']],
            'closure' => 'Office closed — staff planning day',
            'cancellations' => ['Facilitator unwell — session rescheduled', 'Client requested a postponement', 'Building fire drill'],
        ];
    }
}
