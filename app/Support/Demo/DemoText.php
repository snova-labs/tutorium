<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * The words a demonstration academy is made of.
 *
 * Names are common given names and family names, combined at random: they read like a real
 * class register without describing anyone. Every address is on a reserved domain (RFC 2606) and
 * every phone number is in one narrow, invented block, so nothing here can reach a real person
 * even if a staging server is pointed at a real mail provider.
 */
final class DemoText
{
    public const STAFF_DOMAIN = 'himalayan-scholars.example';

    public const FAMILY_DOMAIN = 'example.com';

    /** @var list<string> */
    public const BOYS = [
        'Aarav', 'Aayush', 'Anish', 'Arjun', 'Bibek', 'Bishal', 'Dipesh', 'Kritan', 'Nischal', 'Prabin',
        'Prajwal', 'Rohan', 'Sajan', 'Samir', 'Saugat', 'Shreyash', 'Sujal', 'Sushant', 'Utsav', 'Yuvraj',
        'Ayan', 'Kushal', 'Niraj', 'Pranish', 'Rijan', 'Sagar', 'Sandesh', 'Swornim', 'Abhinav', 'Ishan',
    ];

    /** @var list<string> */
    public const GIRLS = [
        'Aashma', 'Aayusha', 'Anusha', 'Asmita', 'Bipana', 'Diya', 'Elina', 'Kritika', 'Manisha', 'Nisha',
        'Prakriti', 'Pratiksha', 'Rachana', 'Riya', 'Samikshya', 'Sanjana', 'Shristi', 'Sneha', 'Srijana', 'Sujata',
        'Aakriti', 'Barsha', 'Garima', 'Isha', 'Muna', 'Nirmala', 'Puja', 'Rashmi', 'Sadikshya', 'Trishna',
    ];

    /** @var list<string> */
    public const FATHERS = [
        'Ram', 'Hari', 'Krishna', 'Shyam', 'Bishnu', 'Gopal', 'Dinesh', 'Ramesh', 'Suresh', 'Mahesh',
        'Prakash', 'Rajendra', 'Narayan', 'Binod', 'Santosh', 'Kiran', 'Rabindra', 'Umesh', 'Deepak', 'Mohan',
    ];

    /** @var list<string> */
    public const MOTHERS = [
        'Sita', 'Gita', 'Laxmi', 'Sarita', 'Sunita', 'Kamala', 'Anita', 'Bimala', 'Radha', 'Sabina',
        'Sushma', 'Mina', 'Parbati', 'Saraswati', 'Kalpana', 'Rita', 'Shanti', 'Durga', 'Yashoda', 'Manju',
    ];

    /** @var list<string> */
    public const SURNAMES = [
        'Shrestha', 'Maharjan', 'Tamang', 'Gurung', 'Thapa', 'Rai', 'Limbu', 'Magar', 'Adhikari', 'Bhattarai',
        'Karki', 'Khadka', 'Basnet', 'Poudel', 'Pandey', 'Sharma', 'Joshi', 'Pradhan', 'Bajracharya', 'Shakya',
        'Dahal', 'Ghimire', 'Koirala', 'Sapkota', 'Neupane', 'Bista', 'Rana', 'KC', 'Lama', 'Dangol',
    ];

    /** Short names families actually use, for a few learners. @var array<string, string> */
    public const NICKNAMES = [
        'Aarav' => 'Aaru', 'Prajwal' => 'PJ', 'Shreyash' => 'Shrey', 'Samikshya' => 'Sami',
        'Pratiksha' => 'Prati', 'Yuvraj' => 'Yuvi', 'Sadikshya' => 'Sadi', 'Abhinav' => 'Abhi',
    ];

    /** Why a learner missed a class, as a parent would tell the front desk. @var list<string> */
    public const EXCUSES = [
        'Unwell — parent called in the morning',
        'Family function in the village',
        'School exam the same day',
        'Doctor\'s appointment',
        'Travelling with family',
        'Fever, parent informed',
    ];

    /** Notes on a late arrival. @var list<string> */
    public const LATE_NOTES = [
        'School bus was late',
        'Traffic at Koteshwor',
        'Came straight from school',
        null, null, null,
    ];

    /** @var list<string> */
    public const CANCELLATIONS = [
        'Teacher unwell — class moved to the make-up week',
        'Heavy rain, centre closed for the afternoon',
        'Power cut across the area',
    ];

    /**
     * Teacher's comments, by how the learner is doing. "{name}" is the learner's first name and
     * "{subject}" the course's subject, so the same sentence works across courses.
     *
     * @var array<string, list<string>>
     */
    public const NOTES = [
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
    ];

    /** Short feedback on a piece of work, by result. @var array<string, list<string>> */
    public const FEEDBACK = [
        'high' => ['Excellent work.', 'Neat and complete.', 'Well done — clear working.', null, null],
        'mid' => ['Good effort; check the last question.', 'Mostly correct, watch the units.', null, null, null],
        'low' => ['Let\'s go through this together in class.', 'Incomplete — please finish and resubmit.', 'Revise the examples from the notebook.', null],
    ];
}
