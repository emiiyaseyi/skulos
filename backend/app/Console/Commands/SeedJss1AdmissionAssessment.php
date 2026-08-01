<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionAnswer;
use App\Models\Tenant\CbtQuestionCategory;
use App\Models\Tenant\CbtQuestionPool;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\SchoolClass;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Demo content (user directive 2026-08-01): a real JSS1 admission
 * assessment — four question banks (Mathematics incl. quantitative
 * aptitude, English Language incl. verbal reasoning, General Knowledge
 * incl. Nigeria current affairs, Basic Science and Technology), 50
 * real questions each at a Nigerian primary 1-3 curriculum level (a
 * mix of medium and advanced difficulty, the level a JSS1 entrance
 * candidate is expected to have covered), each learner drawing 25
 * random questions per bank, one 3-hour clock for the whole paper.
 *
 * Idempotent: re-running against a school that already has this
 * assessment updates nothing and creates nothing twice — matches the
 * project's own "config seeds a DB table once" convention, just
 * triggered on demand from the CLI rather than automatically.
 */
class SeedJss1AdmissionAssessment extends Command
{
    protected $signature = 'cbt:seed-jss1-admission-assessment {school : The school slug to seed into}';

    protected $description = 'Seeds a real JSS1 Admission Assessment (4 question banks, 50 questions each) into one school';

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error("No school found with slug '{$this->argument('school')}'.");

            return self::FAILURE;
        }

        $tenants->use($school);

        try {
            if (CbtAssessment::where('title', 'JSS1 Admission Assessment')->exists()) {
                $this->info('JSS1 Admission Assessment already exists for this school — nothing to do.');

                return self::SUCCESS;
            }

            CbtSecurityProfile::ensureSeeded();
            $basic = CbtSecurityProfile::where('name', 'Basic')->first();

            $jss1 = SchoolClass::where('name', 'LIKE', 'JSS1%')->orWhere('name', 'LIKE', 'JSS 1%')->first();

            $assessment = CbtAssessment::create([
                'title' => 'JSS1 Admission Assessment',
                'instructions' => 'You will attempt 4 sections, one per subject: Mathematics, English Language, General Knowledge, and Basic Science and Technology. Each section is drawn from a bank of 50 questions. You have 3 hours in total for all 4 sections.',
                'duration_minutes' => 180,
                'timer_mode' => 'total',
                'score_aggregation' => CbtAssessment::SCORE_SUM,
                'security_profile_id' => $basic->id,
                'audience' => CbtAssessment::AUDIENCE_APPLICANT,
                'applicant_school_class_id' => $jss1?->id,
                'is_active' => true,
                'shuffle_questions' => true,
                'max_attempts' => 1,
                'pass_mark' => 50,
                'pass_message' => 'Congratulations! You have successfully moved to the next stage of the application process.',
                'fail_message' => 'You did not meet the pass mark for this assessment. Please contact the school admissions office for guidance on next steps.',
                'results_mode' => 'manual',
            ]);

            $banks = [
                'Mathematics' => $this->mathematicsQuestions(),
                'English Language' => $this->englishQuestions(),
                'General Knowledge' => $this->generalKnowledgeQuestions(),
                'Basic Science and Technology' => $this->basicScienceQuestions(),
            ];

            foreach ($banks as $subject => $questions) {
                $category = CbtQuestionCategory::firstOrCreate(['name' => $subject]);

                foreach ($questions as $row) {
                    $question = CbtQuestion::create([
                        'category_id' => $category->id,
                        'type' => 'multiple_choice_single',
                        'prompt' => $row['q'],
                        'points' => 1,
                        'is_active' => true,
                    ]);

                    foreach ($row['options'] as $sortOrder => $option) {
                        CbtQuestionAnswer::create([
                            'question_id' => $question->id,
                            'answer_text' => $option,
                            'fraction' => $option === $row['correct'] ? 1 : 0,
                            'sort_order' => $sortOrder,
                        ]);
                    }
                }

                CbtQuestionPool::create([
                    'assessment_id' => $assessment->id,
                    'category_id' => $category->id,
                    'label' => $subject,
                    'draw_count' => 25,
                    'points_each' => 1,
                ]);

                $this->info("Seeded {$subject}: 50 questions, drawing 25 per learner.");
            }

            $this->info('JSS1 Admission Assessment created — 200 questions across 4 banks, 100 questions per attempt, 3 hour total timer.');

            return self::SUCCESS;
        } finally {
            $tenants->forget();
        }
    }

    /** @return array<int, array{q: string, options: array<int, string>, correct: string}> */
    protected function mathematicsQuestions(): array
    {
        return [
            ['q' => 'What is 7 + 8?', 'options' => ['13', '14', '15', '16'], 'correct' => '15'],
            ['q' => 'What is 15 - 6?', 'options' => ['7', '8', '9', '10'], 'correct' => '9'],
            ['q' => 'What is 6 x 3?', 'options' => ['16', '18', '20', '21'], 'correct' => '18'],
            ['q' => 'What is 20 divided by 4?', 'options' => ['4', '5', '6', '8'], 'correct' => '5'],
            ['q' => 'What is 9 + 12?', 'options' => ['19', '20', '21', '22'], 'correct' => '21'],
            ['q' => 'What is 100 - 45?', 'options' => ['45', '55', '65', '50'], 'correct' => '55'],
            ['q' => 'Which number comes right after 99?', 'options' => ['98', '100', '101', '90'], 'correct' => '100'],
            ['q' => 'What is 3 x 7?', 'options' => ['18', '20', '21', '24'], 'correct' => '21'],
            ['q' => 'What is half of 20?', 'options' => ['5', '10', '15', '40'], 'correct' => '10'],
            ['q' => 'What is 45 divided by 5?', 'options' => ['7', '8', '9', '10'], 'correct' => '9'],
            ['q' => 'Which of these numbers is an even number?', 'options' => ['7', '11', '14', '19'], 'correct' => '14'],
            ['q' => 'Which of these numbers is an odd number?', 'options' => ['10', '15', '22', '30'], 'correct' => '15'],
            ['q' => 'What is 1/2 + 1/2?', 'options' => ['1/4', '1', '2', '1/2'], 'correct' => '1'],
            ['q' => 'If a cake is cut into 4 equal parts and 2 parts are eaten, what fraction is left?', 'options' => ['1/4', '1/2', '3/4', '2/4'], 'correct' => '1/2'],
            ['q' => 'What is the next number in the pattern: 2, 4, 6, 8, ___?', 'options' => ['9', '10', '12', '16'], 'correct' => '10'],
            ['q' => 'What is the next number in the pattern: 5, 10, 15, 20, ___?', 'options' => ['22', '24', '25', '30'], 'correct' => '25'],
            ['q' => 'What is the missing number: 3, 6, 9, ___, 15?', 'options' => ['10', '11', '12', '13'], 'correct' => '12'],
            ['q' => 'A pencil costs 20 naira. How much will 3 pencils cost?', 'options' => ['40 naira', '50 naira', '60 naira', '80 naira'], 'correct' => '60 naira'],
            ['q' => 'Ade has 24 oranges and shares them equally among 6 friends. How many does each friend get?', 'options' => ['3', '4', '5', '6'], 'correct' => '4'],
            ['q' => 'A box has 8 rows of 5 apples. How many apples are there in total?', 'options' => ['13', '35', '40', '45'], 'correct' => '40'],
            ['q' => 'What is the perimeter of a square with each side measuring 4cm?', 'options' => ['8cm', '12cm', '16cm', '20cm'], 'correct' => '16cm'],
            ['q' => 'How many sides does a triangle have?', 'options' => ['2', '3', '4', '5'], 'correct' => '3'],
            ['q' => 'How many sides does a rectangle have?', 'options' => ['2', '3', '4', '6'], 'correct' => '4'],
            ['q' => 'What is the shape of a ball?', 'options' => ['Cube', 'Cylinder', 'Sphere', 'Cone'], 'correct' => 'Sphere'],
            ['q' => 'Which unit is normally used to measure the length of a table?', 'options' => ['Kilogram', 'Metre', 'Litre', 'Second'], 'correct' => 'Metre'],
            ['q' => 'Which unit is normally used to measure how heavy a bag of rice is?', 'options' => ['Metre', 'Kilogram', 'Litre', 'Hour'], 'correct' => 'Kilogram'],
            ['q' => 'How many days are in one week?', 'options' => ['5', '6', '7', '8'], 'correct' => '7'],
            ['q' => 'How many months are in one year?', 'options' => ['10', '11', '12', '13'], 'correct' => '12'],
            ['q' => 'How many minutes are in one hour?', 'options' => ['30', '45', '60', '100'], 'correct' => '60'],
            ['q' => 'If it is 3 o\'clock now, what time will it be in 2 hours?', 'options' => ['4 o\'clock', '5 o\'clock', '6 o\'clock', '1 o\'clock'], 'correct' => '5 o\'clock'],
            ['q' => 'What number is 10 more than 45?', 'options' => ['35', '50', '54', '55'], 'correct' => '55'],
            ['q' => 'What number is 10 less than 80?', 'options' => ['60', '70', '75', '90'], 'correct' => '70'],
            ['q' => 'Round 47 to the nearest ten.', 'options' => ['40', '45', '50', '47'], 'correct' => '50'],
            ['q' => 'Round 82 to the nearest ten.', 'options' => ['80', '82', '85', '90'], 'correct' => '80'],
            ['q' => 'In the number 356, what is the place value of the digit 5?', 'options' => ['Ones', 'Tens', 'Hundreds', 'Thousands'], 'correct' => 'Tens'],
            ['q' => 'In the number 738, what is the place value of the digit 7?', 'options' => ['Ones', 'Tens', 'Hundreds', 'Thousands'], 'correct' => 'Hundreds'],
            ['q' => 'Which of these numbers is greater?', 'options' => ['45', '54', 'They are equal', 'Cannot tell'], 'correct' => '54'],
            ['q' => 'Which of these numbers is smaller?', 'options' => ['302', '320', 'They are equal', 'Cannot tell'], 'correct' => '302'],
            ['q' => 'What is 100 + 250?', 'options' => ['300', '325', '350', '400'], 'correct' => '350'],
            ['q' => 'What is 500 - 275?', 'options' => ['200', '215', '225', '250'], 'correct' => '225'],
            ['q' => 'What is 12 x 4?', 'options' => ['36', '44', '48', '52'], 'correct' => '48'],
            ['q' => 'What is 81 divided by 9?', 'options' => ['7', '8', '9', '11'], 'correct' => '9'],
            ['q' => 'A farmer has 3 baskets with 15 mangoes in each basket. How many mangoes does he have in total?', 'options' => ['18', '35', '45', '50'], 'correct' => '45'],
            ['q' => 'Chidi saves 50 naira every day. How much will he save in 6 days?', 'options' => ['250 naira', '280 naira', '300 naira', '350 naira'], 'correct' => '300 naira'],
            ['q' => 'What comes next in the pattern: 100, 90, 80, 70, ___?', 'options' => ['50', '55', '60', '65'], 'correct' => '60'],
            ['q' => 'What is the missing number in the pattern: 2, 4, 8, 16, ___?', 'options' => ['20', '24', '28', '32'], 'correct' => '32'],
            ['q' => 'If A=1, B=2, C=3, D=4, what number represents E?', 'options' => ['4', '5', '6', '7'], 'correct' => '5'],
            ['q' => 'In a class of 30 pupils, 18 are girls. How many are boys?', 'options' => ['10', '12', '14', '16'], 'correct' => '12'],
            ['q' => 'What is the sum of the first 4 odd numbers: 1 + 3 + 5 + 7?', 'options' => ['12', '14', '16', '18'], 'correct' => '16'],
            ['q' => 'What is 7 x 8?', 'options' => ['48', '54', '56', '64'], 'correct' => '56'],
        ];
    }

    /** @return array<int, array{q: string, options: array<int, string>, correct: string}> */
    protected function englishQuestions(): array
    {
        return [
            ['q' => 'Choose the correctly spelt word.', 'options' => ['recieve', 'receive', 'receeve', 'receve'], 'correct' => 'receive'],
            ['q' => 'Choose the correctly spelt word.', 'options' => ['frend', 'freind', 'friend', 'friand'], 'correct' => 'friend'],
            ['q' => 'What is the opposite of "hot"?', 'options' => ['Warm', 'Cold', 'Cool', 'Mild'], 'correct' => 'Cold'],
            ['q' => 'What is the opposite of "big"?', 'options' => ['Large', 'Huge', 'Small', 'Wide'], 'correct' => 'Small'],
            ['q' => 'What is the opposite of "happy"?', 'options' => ['Glad', 'Sad', 'Angry', 'Calm'], 'correct' => 'Sad'],
            ['q' => 'What is the opposite of "fast"?', 'options' => ['Quick', 'Slow', 'Speedy', 'Rapid'], 'correct' => 'Slow'],
            ['q' => 'Which word means almost the same as "happy"?', 'options' => ['Glad', 'Angry', 'Tired', 'Bored'], 'correct' => 'Glad'],
            ['q' => 'Which word means almost the same as "small"?', 'options' => ['Huge', 'Giant', 'Tiny', 'Wide'], 'correct' => 'Tiny'],
            ['q' => 'What is the plural of "child"?', 'options' => ['Childs', 'Childes', 'Children', 'Childrens'], 'correct' => 'Children'],
            ['q' => 'What is the plural of "mouse"?', 'options' => ['Mouses', 'Mice', 'Mices', 'Mouse'], 'correct' => 'Mice'],
            ['q' => 'What is the plural of "foot"?', 'options' => ['Foots', 'Feet', 'Feets', 'Footes'], 'correct' => 'Feet'],
            ['q' => 'Which of these words is a noun?', 'options' => ['Run', 'Quickly', 'Table', 'Blue'], 'correct' => 'Table'],
            ['q' => 'Which of these words is a verb?', 'options' => ['Dog', 'Jump', 'Red', 'Slowly'], 'correct' => 'Jump'],
            ['q' => 'Which of these words is an adjective?', 'options' => ['Happy', 'Quickly', 'Sing', 'Chair'], 'correct' => 'Happy'],
            ['q' => 'Which of these words is an adverb?', 'options' => ['Quickly', 'Table', 'Jump', 'Red'], 'correct' => 'Quickly'],
            ['q' => 'She ___ to school every day.', 'options' => ['go', 'goes', 'going', 'gone'], 'correct' => 'goes'],
            ['q' => 'They ___ playing football now.', 'options' => ['is', 'am', 'are', 'was'], 'correct' => 'are'],
            ['q' => 'I ___ my homework yesterday.', 'options' => ['do', 'did', 'done', 'doing'], 'correct' => 'did'],
            ['q' => 'Which sentence is written correctly?', 'options' => ['he is going to the market.', 'He is going to the market.', 'He is Going to the Market.', 'he Is going to the market'], 'correct' => 'He is going to the market.'],
            ['q' => 'Which sentence uses a capital letter correctly?', 'options' => ['my name is Ada.', 'My name is ada.', 'My name is Ada.', 'My Name is ada.'], 'correct' => 'My name is Ada.'],
            ['q' => 'What is the past tense of "eat"?', 'options' => ['Eated', 'Ate', 'Eaten', 'Eating'], 'correct' => 'Ate'],
            ['q' => 'What is the past tense of "go"?', 'options' => ['Goed', 'Gone', 'Went', 'Going'], 'correct' => 'Went'],
            ['q' => 'What is the past tense of "run"?', 'options' => ['Runned', 'Ran', 'Running', 'Runs'], 'correct' => 'Ran'],
            ['q' => 'Which punctuation mark is used to end a question?', 'options' => ['Full stop', 'Comma', 'Question mark', 'Exclamation mark'], 'correct' => 'Question mark'],
            ['q' => 'Which punctuation mark is used to end a normal statement?', 'options' => ['Full stop', 'Comma', 'Question mark', 'Colon'], 'correct' => 'Full stop'],
            ['q' => 'Which word means almost the same as "begin"?', 'options' => ['End', 'Start', 'Stop', 'Finish'], 'correct' => 'Start'],
            ['q' => 'Which word means almost the same as "quick"?', 'options' => ['Fast', 'Slow', 'Late', 'Lazy'], 'correct' => 'Fast'],
            ['q' => 'Find the odd one out.', 'options' => ['Apple', 'Banana', 'Mango', 'Chair'], 'correct' => 'Chair'],
            ['q' => 'Find the odd one out.', 'options' => ['Dog', 'Cat', 'Cow', 'Table'], 'correct' => 'Table'],
            ['q' => 'Find the odd one out.', 'options' => ['Red', 'Blue', 'Green', 'Run'], 'correct' => 'Run'],
            ['q' => 'Find the odd one out.', 'options' => ['Monday', 'Tuesday', 'January', 'Friday'], 'correct' => 'January'],
            ['q' => 'Boy is to Man as Girl is to ___.', 'options' => ['Child', 'Woman', 'Lady', 'Baby'], 'correct' => 'Woman'],
            ['q' => 'Hand is to Glove as Foot is to ___.', 'options' => ['Sock', 'Shoe', 'Leg', 'Toe'], 'correct' => 'Shoe'],
            ['q' => 'Bird is to Nest as Bee is to ___.', 'options' => ['Web', 'Hole', 'Hive', 'Farm'], 'correct' => 'Hive'],
            ['q' => 'Puppy is to Dog as Kitten is to ___.', 'options' => ['Cat', 'Cow', 'Goat', 'Bird'], 'correct' => 'Cat'],
            ['q' => 'Which word rhymes with "cat"?', 'options' => ['Cup', 'Hat', 'Dog', 'Bird'], 'correct' => 'Hat'],
            ['q' => 'Which word rhymes with "ball"?', 'options' => ['Bell', 'Bowl', 'Tall', 'Bull'], 'correct' => 'Tall'],
            ['q' => 'What does "enormous" mean?', 'options' => ['Very small', 'Very big', 'Very fast', 'Very slow'], 'correct' => 'Very big'],
            ['q' => 'What does "tiny" mean?', 'options' => ['Very big', 'Very tall', 'Very small', 'Very heavy'], 'correct' => 'Very small'],
            ['q' => 'The sun ___ in the east.', 'options' => ['rise', 'rises', 'rose', 'rising'], 'correct' => 'rises'],
            ['q' => 'Choose the correctly spelt word.', 'options' => ['Wednessday', 'Wendesday', 'Wednesday', 'Wensday'], 'correct' => 'Wednesday'],
            ['q' => 'Choose the correctly spelt word.', 'options' => ['Febuary', 'February', 'Febraury', 'Feberary'], 'correct' => 'February'],
            ['q' => 'What do we call a person who teaches pupils in school?', 'options' => ['Doctor', 'Teacher', 'Farmer', 'Driver'], 'correct' => 'Teacher'],
            ['q' => 'What do we call a person whose job is to cook food?', 'options' => ['Tailor', 'Cook', 'Nurse', 'Trader'], 'correct' => 'Cook'],
            ['q' => 'What do we call a baby dog?', 'options' => ['Kitten', 'Cub', 'Puppy', 'Calf'], 'correct' => 'Puppy'],
            ['q' => 'What do we call a baby cat?', 'options' => ['Puppy', 'Kitten', 'Cub', 'Kid'], 'correct' => 'Kitten'],
            ['q' => '"___ apple a day keeps the doctor away."', 'options' => ['A', 'An', 'The', 'No article needed'], 'correct' => 'An'],
            ['q' => '"___ is my brother."', 'options' => ['Him', 'He', 'His', 'Her'], 'correct' => 'He'],
            ['q' => 'What do we call a place where books are kept for people to read or borrow?', 'options' => ['Market', 'Library', 'Farm', 'Clinic'], 'correct' => 'Library'],
            ['q' => 'What do we call a large gathering of people in one place?', 'options' => ['Crowd', 'Team', 'Family', 'Class'], 'correct' => 'Crowd'],
        ];
    }

    /** @return array<int, array{q: string, options: array<int, string>, correct: string}> */
    protected function generalKnowledgeQuestions(): array
    {
        return [
            ['q' => 'What is the capital city of Nigeria?', 'options' => ['Lagos', 'Kano', 'Abuja', 'Ibadan'], 'correct' => 'Abuja'],
            ['q' => 'What is the currency used in Nigeria?', 'options' => ['Cedi', 'Naira', 'Franc', 'Dollar'], 'correct' => 'Naira'],
            ['q' => 'How many states are there in Nigeria?', 'options' => ['30', '33', '36', '40'], 'correct' => '36'],
            ['q' => 'Which is the largest city in Nigeria by population?', 'options' => ['Abuja', 'Kano', 'Lagos', 'Port Harcourt'], 'correct' => 'Lagos'],
            ['q' => 'In what year did Nigeria gain her independence?', 'options' => ['1957', '1960', '1963', '1970'], 'correct' => '1960'],
            ['q' => 'What are the colours of the Nigerian flag?', 'options' => ['Green, White, Green', 'Red, White, Red', 'Green, Yellow, Green', 'Blue, White, Blue'], 'correct' => 'Green, White, Green'],
            ['q' => 'Which river is the longest river in Nigeria?', 'options' => ['River Benue', 'River Niger', 'River Ogun', 'River Kaduna'], 'correct' => 'River Niger'],
            ['q' => 'Nigeria is located on which continent?', 'options' => ['Asia', 'Africa', 'Europe', 'South America'], 'correct' => 'Africa'],
            ['q' => 'Which ocean borders the southern coast of Nigeria?', 'options' => ['Indian Ocean', 'Pacific Ocean', 'Atlantic Ocean', 'Arctic Ocean'], 'correct' => 'Atlantic Ocean'],
            ['q' => 'What is the official language of Nigeria?', 'options' => ['French', 'English', 'Arabic', 'Portuguese'], 'correct' => 'English'],
            ['q' => 'Who is regarded as the first president of Nigeria?', 'options' => ['Nnamdi Azikiwe', 'Tafawa Balewa', 'Yakubu Gowon', 'Olusegun Obasanjo'], 'correct' => 'Nnamdi Azikiwe'],
            ['q' => 'What is the nickname of the Nigeria men\'s national football team?', 'options' => ['Super Falcons', 'Super Eagles', 'Green Eagles', 'Golden Eaglets'], 'correct' => 'Super Eagles'],
            ['q' => 'What is the nickname of the Nigeria women\'s national football team?', 'options' => ['Super Eagles', 'Super Falcons', 'Golden Falcons', 'Flamingos'], 'correct' => 'Super Falcons'],
            ['q' => 'What do we call the head of government in Nigeria?', 'options' => ['Prime Minister', 'President', 'Governor', 'Chairman'], 'correct' => 'President'],
            ['q' => 'What is the capital of Lagos State?', 'options' => ['Lagos Island', 'Ikeja', 'Badagry', 'Epe'], 'correct' => 'Ikeja'],
            ['q' => 'Nigeria is often called the Giant of ___.', 'options' => ['Africa', 'the World', 'West Africa', 'the Sahara'], 'correct' => 'Africa'],
            ['q' => 'What is the name of Nigeria\'s central bank?', 'options' => ['First Bank of Nigeria', 'Central Bank of Nigeria', 'Bank of Industry', 'Nigeria Reserve Bank'], 'correct' => 'Central Bank of Nigeria'],
            ['q' => 'Who is the current President of Nigeria (as at 2026)?', 'options' => ['Muhammadu Buhari', 'Goodluck Jonathan', 'Bola Ahmed Tinubu', 'Olusegun Obasanjo'], 'correct' => 'Bola Ahmed Tinubu'],
            ['q' => 'What is the capital of Ghana?', 'options' => ['Accra', 'Kumasi', 'Lome', 'Abidjan'], 'correct' => 'Accra'],
            ['q' => 'What is the capital of France?', 'options' => ['London', 'Paris', 'Rome', 'Madrid'], 'correct' => 'Paris'],
            ['q' => 'What is the capital of the United Kingdom?', 'options' => ['Manchester', 'Liverpool', 'London', 'Birmingham'], 'correct' => 'London'],
            ['q' => 'What is the largest continent in the world?', 'options' => ['Africa', 'Asia', 'Europe', 'Antarctica'], 'correct' => 'Asia'],
            ['q' => 'What is the largest ocean in the world?', 'options' => ['Atlantic Ocean', 'Indian Ocean', 'Pacific Ocean', 'Arctic Ocean'], 'correct' => 'Pacific Ocean'],
            ['q' => 'How many continents are there in the world?', 'options' => ['5', '6', '7', '8'], 'correct' => '7'],
            ['q' => 'What do we call a person who flies an aeroplane?', 'options' => ['Sailor', 'Pilot', 'Driver', 'Captain only'], 'correct' => 'Pilot'],
            ['q' => 'What do we call a doctor who treats teeth?', 'options' => ['Surgeon', 'Optician', 'Dentist', 'Physician'], 'correct' => 'Dentist'],
            ['q' => 'What is the name of the highest mountain in the world?', 'options' => ['Mount Kilimanjaro', 'Mount Everest', 'Mount Fuji', 'Mount Cameroon'], 'correct' => 'Mount Everest'],
            ['q' => 'Which planet is known as the Red Planet?', 'options' => ['Venus', 'Jupiter', 'Mars', 'Saturn'], 'correct' => 'Mars'],
            ['q' => 'Which planet is closest to the sun?', 'options' => ['Earth', 'Venus', 'Mercury', 'Mars'], 'correct' => 'Mercury'],
            ['q' => 'What do we call the star at the centre of our solar system?', 'options' => ['The Moon', 'The Sun', 'Mars', 'A comet'], 'correct' => 'The Sun'],
            ['q' => 'What is the name of the Earth\'s natural satellite?', 'options' => ['The Sun', 'A star', 'The Moon', 'Mars'], 'correct' => 'The Moon'],
            ['q' => 'What is the season called when it rains a lot in Nigeria?', 'options' => ['Dry season', 'Rainy season', 'Harmattan', 'Summer'], 'correct' => 'Rainy season'],
            ['q' => 'What is the dusty, dry, and cold season in Nigeria called?', 'options' => ['Rainy season', 'Harmattan', 'Monsoon', 'Spring'], 'correct' => 'Harmattan'],
            ['q' => 'What is the name of the organisation that unites African countries?', 'options' => ['United Nations', 'African Union', 'ECOWAS', 'Commonwealth'], 'correct' => 'African Union'],
            ['q' => 'What is the name of the global organisation that promotes peace among countries?', 'options' => ['African Union', 'United Nations', 'World Bank', 'ECOWAS'], 'correct' => 'United Nations'],
            ['q' => 'What do we call a country\'s written set of laws?', 'options' => ['Constitution', 'Manifesto', 'Decree', 'Charter'], 'correct' => 'Constitution'],
            ['q' => 'What is the upper chamber of Nigeria\'s National Assembly called?', 'options' => ['House of Representatives', 'Senate', 'House of Assembly', 'Federal Council'], 'correct' => 'Senate'],
            ['q' => 'What is the lower chamber of Nigeria\'s National Assembly called?', 'options' => ['Senate', 'House of Representatives', 'House of Lords', 'State Assembly'], 'correct' => 'House of Representatives'],
            ['q' => 'Which bird appears on Nigeria\'s coat of arms?', 'options' => ['Dove', 'Eagle', 'Parrot', 'Owl'], 'correct' => 'Eagle'],
            ['q' => 'Which animal appears on both sides of Nigeria\'s coat of arms, holding the shield?', 'options' => ['Lions', 'Horses', 'Elephants', 'Eagles'], 'correct' => 'Horses'],
            ['q' => 'What date is celebrated as Nigeria\'s Independence Day?', 'options' => ['May 29', 'June 12', 'October 1', 'January 1'], 'correct' => 'October 1'],
            ['q' => 'What is the name of the popular Yoruba festival celebrating the new yam harvest?', 'options' => ['Eyo Festival', 'New Yam Festival', 'Argungu Festival', 'Osun Festival'], 'correct' => 'New Yam Festival'],
            ['q' => 'Which Nigerian city is known as the "Centre of Excellence"?', 'options' => ['Abuja', 'Kano', 'Lagos', 'Enugu'], 'correct' => 'Lagos'],
            ['q' => 'Which Nigerian city is the seat of the country\'s federal government?', 'options' => ['Lagos', 'Abuja', 'Kaduna', 'Ibadan'], 'correct' => 'Abuja'],
            ['q' => 'What do we call a large group of islands close together?', 'options' => ['Peninsula', 'Archipelago', 'Isthmus', 'Delta'], 'correct' => 'Archipelago'],
            ['q' => 'What is the largest hot desert in the world?', 'options' => ['Kalahari Desert', 'Sahara Desert', 'Gobi Desert', 'Namib Desert'], 'correct' => 'Sahara Desert'],
            ['q' => 'What do we call money paid by a government to help its citizens, such as free school feeding?', 'options' => ['Tax', 'Subsidy', 'Loan', 'Fine'], 'correct' => 'Subsidy'],
            ['q' => 'What is the name given to Nigerians living outside Nigeria?', 'options' => ['Immigrants', 'Diaspora', 'Refugees', 'Tourists'], 'correct' => 'Diaspora'],
            ['q' => 'Which of these is a major staple food eaten widely across Nigeria?', 'options' => ['Rice', 'Pasta', 'Sushi', 'Bread only'], 'correct' => 'Rice'],
            ['q' => 'What is the name of Nigeria\'s national broadcasting radio station?', 'options' => ['Radio Nigeria', 'BBC', 'Voice of America', 'Radio France'], 'correct' => 'Radio Nigeria'],
        ];
    }

    /** @return array<int, array{q: string, options: array<int, string>, correct: string}> */
    protected function basicScienceQuestions(): array
    {
        return [
            ['q' => 'How many senses does a human being have?', 'options' => ['3', '4', '5', '6'], 'correct' => '5'],
            ['q' => 'Which part of the body is used for seeing?', 'options' => ['Ears', 'Eyes', 'Nose', 'Skin'], 'correct' => 'Eyes'],
            ['q' => 'Which part of the body is used for hearing?', 'options' => ['Eyes', 'Ears', 'Tongue', 'Nose'], 'correct' => 'Ears'],
            ['q' => 'Which part of the body is used for smelling?', 'options' => ['Eyes', 'Ears', 'Nose', 'Skin'], 'correct' => 'Nose'],
            ['q' => 'Which part of the body is used for tasting?', 'options' => ['Skin', 'Tongue', 'Nose', 'Ears'], 'correct' => 'Tongue'],
            ['q' => 'Which part of the body is used for touching and feeling?', 'options' => ['Skin', 'Eyes', 'Tongue', 'Ears'], 'correct' => 'Skin'],
            ['q' => 'What do we call the process by which green plants make their own food using sunlight?', 'options' => ['Respiration', 'Photosynthesis', 'Digestion', 'Germination'], 'correct' => 'Photosynthesis'],
            ['q' => 'Which of these do plants need in order to grow well?', 'options' => ['Sunlight only', 'Water only', 'Soil only', 'Sunlight, water and soil'], 'correct' => 'Sunlight, water and soil'],
            ['q' => 'What is the green substance found in plant leaves called?', 'options' => ['Chlorophyll', 'Nectar', 'Pollen', 'Sap'], 'correct' => 'Chlorophyll'],
            ['q' => 'What do we call an animal that eats only plants?', 'options' => ['Carnivore', 'Herbivore', 'Omnivore', 'Predator'], 'correct' => 'Herbivore'],
            ['q' => 'What do we call an animal that eats only meat?', 'options' => ['Herbivore', 'Omnivore', 'Carnivore', 'Scavenger'], 'correct' => 'Carnivore'],
            ['q' => 'What do we call an animal that eats both plants and meat?', 'options' => ['Herbivore', 'Carnivore', 'Omnivore', 'Vegetarian'], 'correct' => 'Omnivore'],
            ['q' => 'Which of these animals is a mammal?', 'options' => ['Fish', 'Snake', 'Dog', 'Frog'], 'correct' => 'Dog'],
            ['q' => 'Which of these animals is a bird?', 'options' => ['Cat', 'Eagle', 'Goat', 'Fish'], 'correct' => 'Eagle'],
            ['q' => 'How many legs does an insect have?', 'options' => ['4', '6', '8', '10'], 'correct' => '6'],
            ['q' => 'How many legs does a spider have?', 'options' => ['6', '8', '10', '4'], 'correct' => '8'],
            ['q' => 'What are the three states of matter?', 'options' => ['Solid, liquid, gas', 'Hot, cold, warm', 'Big, small, medium', 'Fast, slow, still'], 'correct' => 'Solid, liquid, gas'],
            ['q' => 'Ice is an example of which state of matter?', 'options' => ['Liquid', 'Gas', 'Solid', 'Vapour'], 'correct' => 'Solid'],
            ['q' => 'Water is an example of which state of matter?', 'options' => ['Solid', 'Liquid', 'Gas', 'Plasma'], 'correct' => 'Liquid'],
            ['q' => 'Air is an example of which state of matter?', 'options' => ['Solid', 'Liquid', 'Gas', 'None of these'], 'correct' => 'Gas'],
            ['q' => 'What happens to ice when it is heated?', 'options' => ['It freezes', 'It melts', 'It disappears forever', 'It becomes harder'], 'correct' => 'It melts'],
            ['q' => 'What happens to water when it is boiled for a long time?', 'options' => ['It freezes', 'It evaporates', 'It becomes ice', 'It becomes heavier'], 'correct' => 'It evaporates'],
            ['q' => 'What do we call the process when water changes from a liquid into a gas?', 'options' => ['Condensation', 'Evaporation', 'Freezing', 'Melting'], 'correct' => 'Evaporation'],
            ['q' => 'What do we call the process when water vapour changes back into liquid water?', 'options' => ['Evaporation', 'Condensation', 'Melting', 'Boiling'], 'correct' => 'Condensation'],
            ['q' => 'A wheelbarrow is an example of which simple machine?', 'options' => ['Lever', 'Wheel and axle', 'Pulley', 'Screw'], 'correct' => 'Wheel and axle'],
            ['q' => 'A ramp used to move heavy objects up or down is an example of which simple machine?', 'options' => ['Lever', 'Pulley', 'Inclined plane', 'Wedge'], 'correct' => 'Inclined plane'],
            ['q' => 'Scissors work using which simple machine?', 'options' => ['Wheel', 'Lever', 'Pulley', 'Screw'], 'correct' => 'Lever'],
            ['q' => 'A seesaw at a playground works using which simple machine?', 'options' => ['Pulley', 'Screw', 'Lever', 'Wedge'], 'correct' => 'Lever'],
            ['q' => 'What word describes tools and machines that make our work easier?', 'options' => ['Agriculture', 'Technology', 'Biology', 'Geography'], 'correct' => 'Technology'],
            ['q' => 'What instrument do we use to see things that are very far away, like stars?', 'options' => ['Microscope', 'Telescope', 'Binocular case', 'Magnifying glass only'], 'correct' => 'Telescope'],
            ['q' => 'What instrument do we use to see things that are too tiny to see with the eyes alone?', 'options' => ['Telescope', 'Microscope', 'Camera', 'Thermometer'], 'correct' => 'Microscope'],
            ['q' => 'What should you do before eating your food?', 'options' => ['Wash your hands', 'Watch television', 'Go outside', 'Sleep'], 'correct' => 'Wash your hands'],
            ['q' => 'What should you always do after using the toilet?', 'options' => ['Eat something', 'Wash your hands', 'Go and play', 'Change your clothes'], 'correct' => 'Wash your hands'],
            ['q' => 'How often should a person brush their teeth every day?', 'options' => ['Once', 'Twice', 'Three times', 'Not necessary'], 'correct' => 'Twice'],
            ['q' => 'What word describes keeping our body and surroundings clean?', 'options' => ['Hygiene', 'Pollution', 'Agriculture', 'Nutrition'], 'correct' => 'Hygiene'],
            ['q' => 'Which vitamin does our skin make when exposed to sunlight?', 'options' => ['Vitamin A', 'Vitamin C', 'Vitamin D', 'Vitamin K'], 'correct' => 'Vitamin D'],
            ['q' => 'Which food group mainly gives our body energy?', 'options' => ['Proteins', 'Carbohydrates', 'Vitamins', 'Minerals'], 'correct' => 'Carbohydrates'],
            ['q' => 'Which food group mainly helps build and repair our body?', 'options' => ['Carbohydrates', 'Fats', 'Proteins', 'Water'], 'correct' => 'Proteins'],
            ['q' => 'Which of these is a good source of protein?', 'options' => ['Rice', 'Beans', 'Sugar', 'Oil'], 'correct' => 'Beans'],
            ['q' => 'Which of these is a good source of vitamins?', 'options' => ['Fruits', 'Sugar', 'Salt', 'Butter only'], 'correct' => 'Fruits'],
            ['q' => 'What do we call the natural place where an animal or plant normally lives?', 'options' => ['Habitat', 'Hotel', 'Territory only', 'Zoo'], 'correct' => 'Habitat'],
            ['q' => 'What do we call it when harmful substances like waste plastics are released into the environment?', 'options' => ['Conservation', 'Pollution', 'Recycling', 'Farming'], 'correct' => 'Pollution'],
            ['q' => 'What should be done with waste to help keep the environment clean?', 'options' => ['Burn it anywhere', 'Dispose of it properly', 'Throw it in rivers', 'Leave it on the ground'], 'correct' => 'Dispose of it properly'],
            ['q' => 'What is the practice of growing crops and rearing animals called?', 'options' => ['Agriculture', 'Manufacturing', 'Mining', 'Fishing only'], 'correct' => 'Agriculture'],
            ['q' => 'What do we call water that falls from the clouds?', 'options' => ['Dew', 'Rain', 'Fog', 'Mist'], 'correct' => 'Rain'],
            ['q' => 'What is the name of the force that pulls objects down towards the ground?', 'options' => ['Friction', 'Gravity', 'Magnetism', 'Pressure'], 'correct' => 'Gravity'],
            ['q' => 'What kind of objects do magnets attract?', 'options' => ['Wooden objects', 'Plastic objects', 'Metal objects like iron', 'Paper objects'], 'correct' => 'Metal objects like iron'],
            ['q' => 'What instrument is used to measure temperature?', 'options' => ['Ruler', 'Thermometer', 'Scale', 'Stopwatch'], 'correct' => 'Thermometer'],
            ['q' => 'What instrument is used to measure the length of an object?', 'options' => ['Thermometer', 'Ruler', 'Weighing scale', 'Clock'], 'correct' => 'Ruler'],
            ['q' => 'What do we call devices such as phones and computers that help us communicate and work?', 'options' => ['Furniture', 'Gadgets', 'Vehicles', 'Instruments only'], 'correct' => 'Gadgets'],
        ];
    }
}
