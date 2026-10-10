<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The words the demonstration academies are made of.
 *
 * Names are common given names and family names for each region, combined at random: they read
 * like a real register without describing anyone. Every address is on a reserved domain
 * (RFC 2606: example.com and .example) and every phone number is in an invented or reserved block
 * (555-01xx in North America), so nothing here can reach a real person even if a staging server
 * is pointed at a real mail provider.
 */
final class DemoText
{
    /**
     * Given names as [name, 'f'|'m'], and family names, by region.
     *
     * @var array<string, array{given: list<array{0: string, 1: string}>, family: list<string>}>
     */
    public const NAMES = [
        'nepal' => [
            'given' => [
                ['Aarav', 'm'], ['Aayush', 'm'], ['Anish', 'm'], ['Arjun', 'm'], ['Bibek', 'm'], ['Bishal', 'm'],
                ['Dipesh', 'm'], ['Kritan', 'm'], ['Nischal', 'm'], ['Prabin', 'm'], ['Prajwal', 'm'], ['Rohan', 'm'],
                ['Sajan', 'm'], ['Samir', 'm'], ['Saugat', 'm'], ['Shreyash', 'm'], ['Sujal', 'm'], ['Sushant', 'm'],
                ['Utsav', 'm'], ['Yuvraj', 'm'], ['Kushal', 'm'], ['Niraj', 'm'], ['Rijan', 'm'], ['Sagar', 'm'],
                ['Aashma', 'f'], ['Aayusha', 'f'], ['Anusha', 'f'], ['Asmita', 'f'], ['Bipana', 'f'], ['Diya', 'f'],
                ['Elina', 'f'], ['Kritika', 'f'], ['Manisha', 'f'], ['Nisha', 'f'], ['Prakriti', 'f'], ['Pratiksha', 'f'],
                ['Rachana', 'f'], ['Riya', 'f'], ['Samikshya', 'f'], ['Sanjana', 'f'], ['Shristi', 'f'], ['Sneha', 'f'],
                ['Srijana', 'f'], ['Sujata', 'f'], ['Barsha', 'f'], ['Garima', 'f'], ['Rashmi', 'f'], ['Trishna', 'f'],
            ],
            'family' => [
                'Shrestha', 'Maharjan', 'Tamang', 'Gurung', 'Thapa', 'Rai', 'Limbu', 'Magar', 'Adhikari', 'Bhattarai',
                'Karki', 'Khadka', 'Basnet', 'Poudel', 'Pandey', 'Sharma', 'Joshi', 'Pradhan', 'Bajracharya', 'Shakya',
                'Dahal', 'Ghimire', 'Koirala', 'Sapkota', 'Neupane', 'Bista', 'Rana', 'KC', 'Lama', 'Dangol',
            ],
        ],
        'gulf' => [
            'given' => [
                ['Ahmed', 'm'], ['Omar', 'm'], ['Yousef', 'm'], ['Khalid', 'm'], ['Hassan', 'm'], ['Bilal', 'm'],
                ['Tariq', 'm'], ['Rahul', 'm'], ['Arjun', 'm'], ['Jose', 'm'], ['Mark', 'm'], ['Dmitri', 'm'],
                ['Karim', 'm'], ['Vikram', 'm'], ['Paolo', 'm'], ['Samuel', 'm'],
                ['Fatima', 'f'], ['Layla', 'f'], ['Mariam', 'f'], ['Noor', 'f'], ['Aisha', 'f'], ['Zainab', 'f'],
                ['Hiba', 'f'], ['Priya', 'f'], ['Anjali', 'f'], ['Maria', 'f'], ['Grace', 'f'], ['Olena', 'f'],
                ['Sara', 'f'], ['Reem', 'f'], ['Meera', 'f'], ['Joanna', 'f'],
            ],
            'family' => [
                'Al Mansoori', 'Haddad', 'Khoury', 'Rahman', 'Siddiqui', 'Nair', 'Menon', 'Pillai', 'Reyes', 'Santos',
                'Cruz', 'Petrova', 'Novak', 'Farouk', 'Qureshi', 'Malik', 'Iyer', 'D\'Souza', 'Fernandes', 'Saleh',
            ],
        ],
        'canada' => [
            'given' => [
                ['Liam', 'm'], ['Noah', 'm'], ['Ethan', 'm'], ['Lucas', 'm'], ['Owen', 'm'], ['Daniel', 'm'],
                ['Ryan', 'm'], ['Wei', 'm'], ['Jun', 'm'], ['Amir', 'm'], ['Jean', 'm'], ['Marcus', 'm'],
                ['Emily', 'f'], ['Olivia', 'f'], ['Sophie', 'f'], ['Chloe', 'f'], ['Maya', 'f'], ['Hannah', 'f'],
                ['Priya', 'f'], ['Fatima', 'f'], ['Marie', 'f'], ['Grace', 'f'], ['Natalie', 'f'], ['Leah', 'f'],
            ],
            'family' => [
                'Tremblay', 'Gagnon', 'Roy', 'Smith', 'Brown', 'Wilson', 'Martin', 'Lee', 'Wong', 'Chen',
                'Singh', 'Patel', 'Nguyen', 'MacDonald', 'Campbell', 'Taylor', 'Anderson', 'Kim', 'Côté', 'Bouchard',
            ],
        ],
    ];

    /** Parents' given names, for families with children (Nepal). @var array{f: list<string>, m: list<string>} */
    public const PARENTS = [
        'm' => ['Ram', 'Hari', 'Krishna', 'Shyam', 'Bishnu', 'Gopal', 'Dinesh', 'Ramesh', 'Suresh', 'Mahesh',
            'Prakash', 'Rajendra', 'Narayan', 'Binod', 'Santosh', 'Kiran', 'Rabindra', 'Umesh', 'Deepak', 'Mohan'],
        'f' => ['Sita', 'Gita', 'Laxmi', 'Sarita', 'Sunita', 'Kamala', 'Anita', 'Bimala', 'Radha', 'Sabina',
            'Sushma', 'Mina', 'Parbati', 'Saraswati', 'Kalpana', 'Rita', 'Shanti', 'Durga', 'Yashoda', 'Manju'],
    ];

    /** Short names people actually go by, for a few learners. @var array<string, string> */
    public const NICKNAMES = [
        'Aarav' => 'Aaru', 'Prajwal' => 'PJ', 'Shreyash' => 'Shrey', 'Samikshya' => 'Sami', 'Pratiksha' => 'Prati',
        'Yuvraj' => 'Yuvi', 'Mohammed' => 'Mo', 'Dmitri' => 'Dima', 'Joanna' => 'Jo', 'Daniel' => 'Dan',
        'Natalie' => 'Nat', 'Samuel' => 'Sam',
    ];

    /** Why someone missed a class, as the front desk would note it. @var array<string, list<string>> */
    public const EXCUSES = [
        'family' => ['Unwell — parent called in the morning', 'Family function in the village', 'School exam the same day',
            'Doctor\'s appointment', 'Travelling with family', 'Fever, parent informed'],
        'adult' => ['Unwell, emailed in advance', 'Work deadline', 'Travelling for work', 'Doctor\'s appointment',
            'Family commitment', 'Visa appointment'],
        'sponsored' => ['Client meeting overran', 'Annual leave', 'Unwell, manager informed', 'Business travel',
            'Quarter-end close', 'Medical appointment'],
    ];

    /** Notes on a late arrival. @var array<string, list<string|null>> */
    public const LATE_NOTES = [
        'family' => ['School bus was late', 'Traffic at Koteshwor', 'Came straight from school', null, null, null],
        'adult' => ['Traffic on Sheikh Zayed Road', 'Metro delay', 'Came from work', null, null, null],
        'sponsored' => ['TTC delay', 'Previous meeting overran', 'Joined late from another call', null, null, null],
    ];

    /** Short feedback on a piece of work, by result. @var array<string, list<string|null>> */
    public const FEEDBACK = [
        'high' => ['Excellent work.', 'Neat and complete.', 'Well done — clear and well organised.', null, null],
        'mid' => ['Good effort; check the last part again.', 'Mostly right — watch the details.', null, null, null],
        'low' => ['Let\'s go through this together next session.', 'Incomplete — please finish and resubmit.',
            'Revise the examples from the session notes.', null],
    ];

    /**
     * End-of-period comments by how the learner is doing, per kind of academy. "{name}" is the
     * learner's first name and "{subject}" the class's subject.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const NOTES = [
        'kids' => [
            'strong' => [
                '{name} is working well ahead of the class in {subject} and has started helping classmates who get stuck.',
                '{name} has been consistently excellent this month. The next step is showing working as carefully as the answers.',
                'Very confident in {subject}. {name} finished the extension problems and asked for more.',
                '{name} explains reasoning clearly when called on — a real strength. Keep encouraging questions at home.',
            ],
            'steady' => [
                '{name} is steady in {subject} and improving week by week. Regular homework is making a visible difference.',
                'Good progress this month. {name} sometimes rushes the last step, so checking answers is our focus next month.',
                '{name} participates well and asks good questions. A little more practice on the harder problems will help.',
                'Solid month for {name}. Confidence is growing; we are now working on speed under exam conditions.',
            ],
            'support' => [
                '{name} finds parts of {subject} difficult at the moment. We are revisiting the basics together in class.',
                '{name} has missed a few classes and is behind on homework. A short catch-up session would help — please contact the front desk.',
                'Effort in class is good, but {name} needs more regular practice at home to keep up with the pace.',
                '{name} is more confident one-to-one than in the group. We will check in at the start of each class.',
            ],
        ],
        'language' => [
            'strong' => [
                '{name} speaks with real fluency now and is ready for more demanding {subject} material.',
                'Excellent term so far. {name}\'s writing is accurate and well organised; the next step is a wider range of vocabulary.',
                '{name} leads pair work naturally and corrects their own mistakes. On track for the next level.',
                'Very strong listening and reading results. {name} should try the optional exam practice papers.',
            ],
            'steady' => [
                '{name} is making steady progress in {subject}. Speaking confidence has grown noticeably.',
                'Good attendance and effort. {name} still mixes up past tenses under pressure; we are drilling these in class.',
                '{name} writes clearly but briefly — longer answers with linking words are the focus for the rest of the term.',
                'Reliable and well prepared. {name} would benefit from 15 minutes of listening practice a day.',
            ],
            'support' => [
                '{name} has missed several sessions because of work, which is showing in the listening tasks. The recordings are on the portal.',
                '{name} understands more than they say. We are building speaking confidence with short, structured tasks.',
                'Homework is often incomplete. {name} has agreed a realistic weekly plan with the teacher.',
                '{name} finds the pace of this group quick; a move to the evening group may suit better. Let\'s discuss.',
            ],
        ],
        'skills' => [
            'strong' => [
                '{name}\'s lab work is consistently excellent and the code is clean and well tested. Ready for the capstone.',
                'Outstanding block. {name} picked up {subject} concepts quickly and helped others debug.',
                '{name} goes beyond the brief in projects — a strong portfolio piece is taking shape.',
                'Very good problem-solving. {name} should now focus on explaining design decisions in reviews.',
            ],
            'steady' => [
                '{name} is progressing well in {subject}. Labs are complete; documentation could be more thorough.',
                'Solid block. {name} occasionally submits late — keeping to the lab deadlines will help with the project.',
                '{name} understands the fundamentals; more practice with the harder lab variants is recommended.',
                'Good participation in sessions. {name} should push the project beyond the minimum requirements.',
            ],
            'support' => [
                '{name} is behind on labs this block. Two catch-up sessions have been booked with the trainer.',
                '{name} attends well but struggles with the practical tasks. Pair programming is helping.',
                'Missed sessions have left gaps in {subject}. {name} should use the recorded sessions before the next block.',
                '{name} needs to submit the outstanding labs before the project review to stay on track.',
            ],
        ],
        'corporate' => [
            'strong' => [
                '{name} contributed thoughtfully throughout and applied the {subject} frameworks to real team situations.',
                'Excellent engagement. {name}\'s case-study analysis was among the strongest in the cohort.',
                '{name} completed every activity to a high standard and is ready to coach colleagues.',
                'Strong participant. {name} would be a good candidate for the advanced programme.',
            ],
            'steady' => [
                '{name} engaged well and completed the core activities. The action plan needs one more concrete goal.',
                'Good progress. {name} is applying the {subject} tools; follow-up with their manager is recommended.',
                '{name} participates actively in discussion; written exercises could go into more depth.',
                'Solid completion. {name} should revisit the module 2 materials before the final assessment.',
            ],
            'support' => [
                '{name} missed sessions because of client commitments and has outstanding activities. Catch-up options sent.',
                '{name} is engaged but has not yet completed the assessed exercises. A short call has been arranged.',
                'Attendance is below the programme requirement. {name}\'s sponsor has been informed, as agreed.',
                '{name} would benefit from the recorded module before the final assessment.',
            ],
        ],
    ];
}
